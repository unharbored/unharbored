<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Cloud\DatabaseServers;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Resources\ResourceManager;
use App\Lib\Servers\ServerStack;
use App\Lib\Vault\Vault;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * A named copy of an application's database, made from a fresh dump (not the
 * newest backup, which can be a day old), for staging, testing a migration or
 * a one-off. Schema only, or schema and data.
 *
 * On a managed cluster the copy is a new database and user beside the site's
 * own; with SQLite it is a file under the site's shared/ folder. Nothing is
 * written to the source. The copy's connection details are kept in the vault
 * and revealed on request, audited, like any secret.
 *
 * The dump and load run in one program on the primary, as root, from a file
 * that carries the new database's password and is deleted afterwards, so the
 * password is never in a command line or a log. It reads the source's
 * credentials from the site's own .env.
 *
 * Optional conversion queries (CloneConversion) run last, in that same program,
 * one statement at a time through a PDO connection made as the copy's own user
 * to the copy only, in one transaction where the engine allows it. The source's
 * credentials are never used for them. A failing statement fails the program,
 * and the copy is dropped like any other failed copy.
 */
final class DatabaseClones
{
    public const NAME = '/^[a-z][a-z0-9_]{1,40}$/';

    private const SCRIPT_PATH = '/root/.cm-db-clone.php';

    public function __construct(
        private readonly GatewayClient $gateway,
        private readonly DatabaseClusters $clusters,
        private readonly ResourceManager $resources,
        private readonly SiteBackups $backups,
        private readonly Vault $vault,
        private readonly AuditLog $audit,
    ) {}

    /** @return list<array<string, mixed>> */
    public function list(stdClass $site): array
    {
        return DB::table('database_clones')->where('site_id', $site->id ?? 0)->orderBy('id')->get()->map(fn ($c) => (array) $c)->all();
    }

    /**
     * Makes the copy and returns its id.
     *
     * @param  Closure(string, string): void  $log
     * @param  string  $conversion  SQL to run on the copy once it is restored, placeholders unfilled
     */
    public function clone(stdClass $site, string $name, bool $schemaOnly, Closure $log, string $conversion = ''): int
    {
        $engine = $this->backups->engine($site);
        if (! preg_match(self::NAME, $name)) {
            throw new InvalidArgumentException('A name is lower case letters, digits and underscores, starting with a letter.');
        }
        if (DB::table('database_clones')->where('site_id', $site->id)->where('name', $name)->exists()) {
            throw new InvalidArgumentException("{$site->name} already has a clone called {$name}.");
        }
        if ($engine === 'sqlite' && $schemaOnly) {
            throw new InvalidArgumentException('A SQLite copy is the whole file, data included.');
        }
        $conversion = trim($conversion);
        // Checked before anything is made, so a refused statement costs nothing.
        $statements = $conversion === '' ? [] : CloneConversion::prepare($conversion, $engine);
        $cluster = $site->database_cluster_id !== null ? $this->resources->findById((int) $site->database_cluster_id) : null;
        if ($engine !== 'sqlite' && $cluster === null) {
            throw new InvalidArgumentException("{$site->name}'s database is not on a managed cluster here, so it cannot be copied. SQLite and managed clusters can.");
        }

        if ($engine === 'sqlite') {
            $target = ['engine' => 'sqlite', 'host' => null, 'port' => null, 'database' => $name, 'username' => null, 'password' => '', 'path' => Sites::root($site)."/shared/database-{$name}.sqlite"];
            $log('create', "a SQLite copy at {$target['path']}");
        } else {
            $target = $this->clusters->createDatabase($site, $cluster, $name) + ['path' => null];
            $log('create', "database {$name} and its own user made on {$cluster->slug}");
        }

        try {
            $this->run($site, $engine, $target + ['schema_only' => $schemaOnly, 'statements' => $statements], $log);
        } catch (Throwable $e) {
            if ($cluster !== null) {
                $this->clusters->discardDatabase($site, $cluster, $name);
            } else {
                try {
                    $this->gateway->run($site->organization_id, $site->node_id, 'apply_recipe', ['spec' => ['name' => 'database-clone-discard', 'steps' => [['id' => 'file', 'type' => 'absent', 'path' => (string) $target['path']]]]], timeoutSeconds: 60);
                } catch (Throwable) {
                }
            }
            throw $e;
        }

        $id = (int) DB::table('database_clones')->insertGetId([
            'site_id' => $site->id, 'name' => $name, 'engine' => $engine, 'schema_only' => $schemaOnly, 'host' => $target['host'], 'port' => $target['port'],
            'database' => $name, 'username' => $target['username'], 'path' => $target['path'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        if ($target['password'] !== '') {
            $this->vault->put($site->organization_id, self::vaultKey($id), $target['password']);
        }
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.database_cloned', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['name' => $name, 'engine' => $engine, 'schema_only' => $schemaOnly]
                + ($statements === [] ? [] : ['conversion_statements' => count($statements), 'conversion_sha256' => CloneConversion::hash($conversion)]));

        return $id;
    }

    /**
     * The copy's connection as .env lines, to paste into an application. Audited.
     *
     * @return array<string, string>
     */
    public function reveal(stdClass $site, int $id, Actor $actor): array
    {
        $clone = DB::table('database_clones')->where('id', $id)->where('site_id', $site->id)->first() ?? throw new InvalidArgumentException('That clone no longer exists.');
        $this->audit->record($site->organization_id, $actor, 'site.database_clone_revealed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['name' => $clone->name]);

        if ($clone->engine === 'sqlite') {
            return ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => (string) $clone->path];
        }

        // A server Unharbored runs is verified against its own certificate, which the site's servers hold; a managed cluster is not.
        $cluster = $site->database_cluster_id !== null ? $this->resources->findById((int) $site->database_cluster_id) : null;
        $verified = $cluster !== null && DatabaseServers::isSelfRun($cluster);

        return array_filter([
            'DB_CONNECTION' => $clone->engine, 'DB_HOST' => (string) $clone->host, 'DB_PORT' => (string) $clone->port, 'DB_DATABASE' => (string) $clone->database,
            'DB_USERNAME' => (string) $clone->username, 'DB_PASSWORD' => (string) $this->vault->get($site->organization_id, self::vaultKey($id)),
            'DB_SSLMODE' => $clone->engine === 'pgsql' ? ($verified ? 'verify-full' : 'require') : null,
            'PGSSLROOTCERT' => $clone->engine === 'pgsql' && $verified ? Sites::root($site).'/shared/database-ca.crt' : null,
            // A managed MySQL cluster needs its CA, which the site's servers already hold.
            'MYSQL_ATTR_SSL_CA' => $clone->engine === 'mysql' ? Sites::root($site).'/shared/database-ca.crt' : null,
        ], fn ($v) => $v !== null);
    }

    /** Deletes a copy: the database and user on the cluster, or the file. It cannot be undone. */
    public function remove(stdClass $site, int $id): void
    {
        $clone = DB::table('database_clones')->where('id', $id)->where('site_id', $site->id)->first() ?? throw new InvalidArgumentException('That clone no longer exists.');
        if ($clone->engine === 'sqlite') {
            $this->gateway->run($site->organization_id, $site->node_id, 'apply_recipe', ['spec' => ['name' => 'database-clone-remove', 'steps' => [['id' => 'file', 'type' => 'absent', 'path' => (string) $clone->path]]]], timeoutSeconds: 60, reason: "Remove {$site->name}'s clone {$clone->name}");
        } elseif (($cluster = $this->resources->findById((int) $site->database_cluster_id)) !== null) {
            $this->clusters->discardDatabase($site, $cluster, (string) $clone->database);
        }
        $this->vault->forget($site->organization_id, self::vaultKey($id));
        DB::table('database_clones')->where('id', $id)->delete();
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.database_clone_removed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['name' => $clone->name]);
    }

    private static function vaultKey(int $id): string
    {
        return "database-clone.{$id}.password";
    }

    /**
     * Writes the program, runs it, and removes it, whatever happens.
     *
     * @param  array<string, mixed>  $target
     * @param  Closure(string, string): void  $log
     */
    private function run(stdClass $site, string $engine, array $target, Closure $log): void
    {
        try {
            $result = $this->gateway->run($site->organization_id, $site->node_id, 'apply_recipe', ['spec' => [
                'name' => 'database-clone',
                'steps' => [
                    ['id' => 'script', 'type' => 'file', 'path' => self::SCRIPT_PATH, 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode(self::script($target))],
                    ['id' => 'clone', 'type' => 'command', 'name' => ServerStack::phpBinary(ServerStack::defaultPhpFor($site->node_id)), 'args' => [self::SCRIPT_PATH, Sites::root($site), Sites::rootDir($site), SiteBackups::PG_BIN, $engine], 'description' => "copy {$site->name}'s database"],
                    ['id' => 'forget', 'type' => 'absent', 'path' => self::SCRIPT_PATH],
                ],
            ]], timeoutSeconds: 1800, reason: "Clone {$site->name}'s database");
        } finally {
            try {
                $this->gateway->run($site->organization_id, $site->node_id, 'apply_recipe', ['spec' => ['name' => 'database-clone-cleanup', 'steps' => [['id' => 'forget', 'type' => 'absent', 'path' => self::SCRIPT_PATH]]]], timeoutSeconds: 30);
            } catch (Throwable) {
            }
        }
        if (($result['status'] ?? null) !== 'succeeded') {
            // The engine's error can echo a value; never a password hash.
            $error = preg_replace('/\$2[aby]\$\d\d\$[.\/A-Za-z0-9]{53}/', '[hash]', (string) ($result['error'] ?? 'no detail'));
            throw new RuntimeException('Copying the database failed: '.mb_substr((string) $error, 0, 600));
        }
        $log('copy', ($target['schema_only'] ? 'the schema' : 'the schema and data').' of a fresh dump loaded into '.$target['database']);
        // The program reports each statement by number and rows affected, never the statement.
        foreach (preg_split('/\R/', (string) ($result['detail']['output'] ?? '')) ?: [] as $line) {
            if (preg_match('/^statement \d+: \d+ rows? affected$/', $line)) {
                $log('convert', $line);
            }
        }
    }

    /**
     * The program: a fresh dump of the site's database, loaded into the copy.
     * $argv is the site's root, its folder in the repository (may be empty), the PostgreSQL bin directory and the engine.
     *
     * @param  array<string, mixed>  $target
     */
    public static function script(#[SensitiveParameter] array $target): string
    {
        return str_replace('__TARGET__', var_export($target, true), <<<'PHP'
            <?php
            [, $root, $sub, $pgBin, $engine] = $argv;
            $t = __TARGET__;
            require "{$root}/current/{$sub}/vendor/autoload.php";
            $env = Dotenv\Dotenv::parse(file_get_contents("{$root}/shared/.env"));
            $run = function (string $cmd): void {
                exec('bash -o pipefail -c '.escapeshellarg($cmd).' 2>&1', $out, $code);
                if ($code !== 0) { fwrite(STDERR, strtok($cmd, ' ')." exited {$code}: ".implode("\n", array_slice($out, -15))."\n"); exit(1); }
            };
            $file = tempnam(sys_get_temp_dir(), 'cm-clone');
            register_shutdown_function(fn () => @unlink($file));
            $cnf = function (string $host, string $port, string $user, string $password) use ($env): string {
                $path = tempnam(sys_get_temp_dir(), 'cm-my'); chmod($path, 0600);
                $q = fn ($v) => '"'.addcslashes((string) $v, '"\\').'"';
                file_put_contents($path, "[client]\nhost=".$q($host)."\nport=".$q($port)."\nuser=".$q($user)."\npassword=".$q($password)."\n".(($env['MYSQL_ATTR_SSL_CA'] ?? '') !== '' ? 'ssl-ca='.$q($env['MYSQL_ATTR_SSL_CA'])."\n" : ''));
                register_shutdown_function(fn () => @unlink($path));
                return $path;
            };
            // Conversion queries: the copy's own user, the copy's own database, one statement at a time.
            $convert = function (PDO $pdo) use ($t): void {
                if (! $t['statements']) { return; }
                $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
                $n = 0;
                try {
                    $pdo->beginTransaction();
                    foreach ($t['statements'] as $i => $sql) {
                        $n = $i + 1;
                        echo "statement {$n}: ".(int) $pdo->exec($sql)." rows affected\n";
                    }
                    if ($pdo->inTransaction()) { $pdo->commit(); }
                } catch (Throwable $e) {
                    try { if ($pdo->inTransaction()) { $pdo->rollBack(); } } catch (Throwable) {}
                    fwrite(STDERR, "conversion statement {$n} of ".count($t['statements'])." failed: ".$e->getMessage()."\n");
                    exit(1);
                }
            };
            $db = $env['DB_DATABASE'] ?? '';
            if ($engine === 'pgsql') {
                putenv('PGHOST='.($env['DB_HOST'] ?? '')); putenv('PGPORT='.($env['DB_PORT'] ?? '5432'));
                putenv('PGUSER='.($env['DB_USERNAME'] ?? '')); putenv('PGPASSWORD='.($env['DB_PASSWORD'] ?? ''));
                putenv('PGDATABASE='.$db); putenv('PGSSLMODE='.($env['DB_SSLMODE'] ?? 'prefer'));
                if (($env['PGSSLROOTCERT'] ?? '') !== '') { putenv('PGSSLROOTCERT='.$env['PGSSLROOTCERT']); }
                $run("{$pgBin}/pg_dump -Fc --no-owner --no-acl".($t['schema_only'] ? ' --schema-only' : '').' -f '.escapeshellarg($file));
                putenv('PGUSER='.$t['username']); putenv('PGPASSWORD='.$t['password']); putenv('PGDATABASE='.$t['database']);
                $run("{$pgBin}/pg_restore --no-owner --no-acl --single-transaction -d ".escapeshellarg($t['database']).' '.escapeshellarg($file));
                if ($t['statements']) {
                    $convert(new PDO('pgsql:host='.$t['host'].';port='.$t['port'].';dbname='.$t['database'].(($env['PGSSLROOTCERT'] ?? '') !== '' ? ';sslmode=verify-full;sslrootcert='.$env['PGSSLROOTCERT'] : ';sslmode=require'), $t['username'], $t['password']));
                }
            } elseif ($engine === 'mysql') {
                $source = $cnf($env['DB_HOST'] ?? '', $env['DB_PORT'] ?? '3306', $env['DB_USERNAME'] ?? '', $env['DB_PASSWORD'] ?? '');
                $run('mysqldump --defaults-extra-file='.escapeshellarg($source).' --single-transaction --no-tablespaces --set-gtid-purged=OFF'.($t['schema_only'] ? ' --no-data' : '').' '.escapeshellarg($db).' | gzip > '.escapeshellarg($file));
                $copy = $cnf((string) $t['host'], (string) $t['port'], (string) $t['username'], (string) $t['password']);
                $run('gunzip -c '.escapeshellarg($file).' | mysql --defaults-extra-file='.escapeshellarg($copy).' '.escapeshellarg($t['database']));
                if ($t['statements']) {
                    $mc = fn (string $name) => constant(class_exists('Pdo\\Mysql') ? 'Pdo\\Mysql::ATTR_'.$name : 'PDO::MYSQL_ATTR_'.$name);
                    $ca = "{$root}/shared/database-ca.crt";
                    $convert(new PDO('mysql:host='.$t['host'].';port='.$t['port'].';dbname='.$t['database'], $t['username'], $t['password'],
                        [$mc('LOCAL_INFILE') => false, $mc('MULTI_STATEMENTS') => false] + (is_file($ca) ? [$mc('SSL_CA') => $ca] : [])));
                }
            } else {
                // The copy goes where the application can write, so root must not write it or chown it
                // by path (audit 2026-11-08, finding 1): become the deploy user first, for good, and
                // the file is deploy's from the start with nothing for a swapped path to redirect.
                $pw = posix_getpwnam('deploy') ?: throw new RuntimeException('no deploy user');
                if (! posix_initgroups('deploy', $pw['gid']) || ! posix_setgid($pw['gid']) || ! posix_setuid($pw['uid'])) {
                    throw new RuntimeException('could not drop to the deploy user');
                }
                (new PDO('sqlite:'.$db))->exec('VACUUM INTO '.(new PDO('sqlite::memory:'))->quote($t['path']));
            }
            echo "cloned\n";
            PHP);
    }
}
