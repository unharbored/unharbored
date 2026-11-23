<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\Spaces;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Servers\ServerStack;
use Closure;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * A copy of one site's database, taken on its primary and kept in the backup
 * bucket for KEEP_DAYS: nightly, before each deploy's migrations, before a
 * restore, and on demand.
 *
 * The dump runs on the primary because only it, and the site's other
 * servers, can reach the database. It reads the credentials from the site's
 * own .env with the app's own Dotenv parser and hands them to pg_dump or
 * mysqldump through the environment or a 0600 file, never the command line,
 * so they do not show in a process list. The upload and download use
 * five-minute URLs for one object each: the server holds no bucket key.
 *
 * PostgreSQL dumps are pg_dump's custom format, restored with --clean so the
 * dumped tables replace what is there; MySQL dumps drop and recreate each
 * table. Either way a table created after the dump is left alone. SQLite is
 * a VACUUM INTO copy of the file, swapped in whole.
 */
final class SiteBackups
{
    public const KEEP_DAYS = 14;

    public const KINDS = ['nightly', 'deploy', 'manual', 'restore', 'preview'];

    /** pg_dump must be at least the server's version: see ServerStack::PG_CLIENT. */
    public const PG_BIN = '/usr/lib/postgresql/'.ServerStack::PG_CLIENT.'/bin';

    public function __construct(
        private readonly GatewayClient $gateway,
        private readonly Spaces $spaces,
        private readonly Sites $sites,
        private readonly AuditLog $audit,
    ) {}

    /** Whether backups can be taken at all: they need the backup bucket. */
    public function enabled(): bool
    {
        return $this->spaces->backupTarget() !== null;
    }

    public static function prefix(stdClass $site): string
    {
        return "sites/{$site->id}-{$site->name}/";
    }

    /**
     * Dumps the site's database and uploads it.
     *
     * @return array{object: string, bytes: int}
     */
    public function take(stdClass $site, string $kind): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown backup kind {$kind}.");
        }
        $engine = $this->engine($site);
        $object = self::prefix($site).gmdate('Ymd\THis\Z')."-{$kind}.".self::EXTENSIONS[$engine];
        if (! $this->enabled() && str_starts_with((string) ($site->database_mode ?? ''), 'local:')) {
            return $this->takeLocal($site, $kind, $engine);
        }
        $output = $this->php($site, $site->node_id, self::DUMP, [$engine, $this->spaces->backupUrl('PUT', $object, ttl: Spaces::TRANSFER_TTL)],
            "Backing up {$site->name}'s database");
        $bytes = (int) (json_decode(trim($output), true)['bytes'] ?? 0);

        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.backed_up', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['object' => $object, 'bytes' => $bytes, 'kind' => $kind]);

        return ['object' => $object, 'bytes' => $bytes];
    }

    /** Where a site's dumps are kept on its own server when there is no backup bucket. */
    public static function localDirectory(stdClass $site): string
    {
        return Sites::root($site).'/shared/backups';
    }

    /**
     * A site on a database on its own server, with no backup bucket: the dump
     * stays on that server's disk, 14 days of them. It is on the same machine
     * as the data, so it protects against a bad migration, not a lost server;
     * the console says so.
     *
     * @return array{object: string, bytes: int}
     */
    private function takeLocal(stdClass $site, string $kind, string $engine): array
    {
        $path = self::localDirectory($site).'/'.gmdate('Ymd\THis\Z')."-{$kind}.".self::EXTENSIONS[$engine];
        $output = $this->php($site, $site->node_id, self::DUMP_LOCAL, [$engine, $path], "Backing up {$site->name}'s database to this server's disk");
        $bytes = (int) (json_decode(trim($output), true)['bytes'] ?? 0);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.backed_up', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['object' => $path, 'bytes' => $bytes, 'kind' => $kind, 'where' => 'server disk']);

        return ['object' => $path, 'bytes' => $bytes];
    }

    /**
     * The site's backups, newest first.
     *
     * @return list<array{object: string, at: Carbon, kind: string, bytes: int}>
     */
    public function list(stdClass $site): array
    {
        $out = [];
        foreach ($this->spaces->backupObjects(self::prefix($site)) as $object => $bytes) {
            if (preg_match('#/(\d{8}T\d{6}Z)-([a-z]+)\.#', $object, $m)) {
                $out[] = ['object' => $object, 'at' => Carbon::createFromFormat('Ymd\THis\Z', $m[1], 'UTC'), 'kind' => $m[2], 'bytes' => $bytes];
            }
        }

        return array_reverse($out);
    }

    /** @return list<string> the objects deleted */
    public function prune(stdClass $site): array
    {
        $cutoff = now()->subDays(self::KEEP_DAYS);
        $deleted = [];
        foreach ($this->list($site) as $backup) {
            if ($backup['at']->lt($cutoff)) {
                $this->spaces->deleteBackupObject($backup['object']);
                $deleted[] = $backup['object'];
            }
        }

        return $deleted;
    }

    /**
     * Copies the site's database from another server of the same engine,
     * a cluster restored to an earlier moment, into the live one: the same
     * safety backup and maintenance window as restore().
     *
     * @param  Closure(string, string): void  $log
     */
    public function restoreFromHost(stdClass $site, string $host, int $port, Closure $log): void
    {
        $engine = $this->engine($site);
        if (! in_array($engine, ['pgsql', 'mysql'], true) || ! preg_match('/^[a-z0-9.-]+$/i', $host)) {
            throw new InvalidArgumentException('Only a managed PostgreSQL or MySQL database restores to a point in time.');
        }
        $this->load($site, $engine, "db://{$host}:{$port}", "{$host}:{$port}", $log);
    }

    /**
     * Puts a backup back. Every server goes into maintenance mode first, so
     * no request or worker writes mid-restore, and comes out of it however
     * the restore ends. What is there now is backed up before it is
     * replaced.
     *
     * @param  Closure(string, string): void  $log
     */
    public function restore(stdClass $site, string $object, Closure $log): void
    {
        if (! str_starts_with($object, self::prefix($site)) || str_contains($object, '..')) {
            throw new InvalidArgumentException('That backup is not one of this site\'s.');
        }
        $engine = $this->engine($site);
        if (! str_ends_with($object, '.'.self::EXTENSIONS[$engine])) {
            throw new InvalidArgumentException("That backup is not a {$engine} dump; the site has changed database since.");
        }

        $this->load($site, $engine, $this->spaces->backupUrl('GET', $object, ttl: Spaces::TRANSFER_TTL), basename($object), $log);
    }

    /**
     * Fills a cloned preview with its parent's data: the parent's newest
     * backup if under a day old (never a restore's safety copy), else one
     * taken now, loaded into the
     * preview's own database, then the branch's migrations on top. Nothing
     * is backed up first and nothing goes into maintenance: the preview has
     * had no data of its own yet.
     *
     * @param  Closure(string, string): void  $log
     */
    public function cloneInto(stdClass $preview, stdClass $parent, Closure $log): void
    {
        $engine = $this->engine($parent);
        if ($this->engine($preview) !== $engine) {
            throw new RuntimeException("{$preview->name} is not on {$engine} like {$parent->name}, so it cannot take a copy of it.");
        }
        // Not a restore's safety copy: that is the data a restore replaced.
        $latest = array_values(array_filter($this->list($parent), fn ($b) => $b['kind'] !== 'restore'))[0] ?? null;
        if ($latest === null || $latest['at']->lt(now()->subDay()) || ! str_ends_with($latest['object'], '.'.self::EXTENSIONS[$engine])) {
            $latest = $this->take($parent, 'preview');
            $log('copy', "{$parent->name}'s database copied to ".basename($latest['object']));
        } else {
            $log('copy', "using {$parent->name}'s backup ".basename($latest['object']));
        }
        $this->php($preview, $preview->node_id, self::LOAD, [$engine, $this->spaces->backupUrl('GET', $latest['object'], ttl: Spaces::TRANSFER_TTL)], "Cloning {$parent->name}'s database into {$preview->name}");
        $log('load', 'loaded into '.$preview->name);
        $this->artisan($preview, $preview->node_id, ['migrate', '--force']);
        $log('migrate', "{$preview->branch}'s migrations run on the copy");

        $this->audit->record($preview->organization_id, Actor::system('control-plane'), 'site.preview_cloned', resourceType: 'site',
            resourceId: "{$preview->node_id}/{$preview->name}", metadata: ['from' => $latest['object']]);
    }

    /** @param  Closure(string, string): void  $log */
    private function load(stdClass $site, string $engine, string $source, string $label, Closure $log): void
    {
        $safety = $this->take($site, 'restore');
        $log('backup', "what is there now is kept as {$safety['object']}");

        $nodes = Sites::nodes($site);
        try {
            foreach ($nodes as $node) {
                $this->artisan($site, $node, ['down', '--retry=30']);
            }
            $log('down', 'maintenance mode on '.count($nodes).' server(s)');
            $this->php($site, $site->node_id, self::LOAD, [$engine, $source], "Restoring {$site->name}'s database");
            $log('restore', "{$label} loaded");
        } finally {
            $failed = [];
            foreach ($nodes as $node) {
                try {
                    $this->artisan($site, $node, ['up']);
                } catch (Throwable $e) {
                    $failed[] = strstr($node, '.', true) ?: $node;
                }
            }
            $log('up', $failed === [] ? 'maintenance mode off' : 'still in maintenance mode on '.implode(', ', $failed).': run php artisan up there');
        }

        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.restored', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['from' => $label, 'safety' => $safety['object']]);
    }

    private const EXTENSIONS = ['pgsql' => 'pgdump', 'mysql' => 'sql.gz', 'sqlite' => 'sqlite.gz'];

    /** What the site's .env says it uses. */
    public function engine(stdClass $site): string
    {
        preg_match('/^DB_CONNECTION=["\']?(\w+)/m', $this->sites->env($site), $m);
        $engine = $m[1] ?? 'sqlite';
        if (! isset(self::EXTENSIONS[$engine])) {
            throw new RuntimeException("{$site->name} uses {$engine}, which backups do not cover.");
        }

        return $engine;
    }

    /** @param list<string> $args */
    private function artisan(stdClass $site, string $node, array $args): void
    {
        $result = $this->gateway->run($site->organization_id, $node, 'exec', [
            'name' => 'runuser',
            'args' => ['-u', ServerStack::DEPLOY_USER, '--', ServerStack::phpBinary(Sites::phpVersion($site)), Sites::current($site).'/artisan', ...$args],
        ], reason: "php artisan {$args[0]} for {$site->name}'s database");
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException("artisan {$args[0]} failed on {$node}: ".($result['error'] ?? 'no detail'));
        }
    }

    /**
     * Runs one of the scripts below as the deploy user, from the site's root.
     *
     * @param  list<string>  $args
     */
    private function php(stdClass $site, string $node, string $script, array $args, string $reason): string
    {
        $result = $this->gateway->run($site->organization_id, $node, 'exec', [
            'name' => 'runuser',
            'args' => ['-u', ServerStack::DEPLOY_USER, '--', ServerStack::phpBinary(Sites::phpVersion($site)), '-r', self::PRELUDE.$script, Sites::root($site), Sites::rootDir($site), self::PG_BIN, ...$args],
        ], timeoutSeconds: 1800, reason: $reason);
        $output = (string) ($result['detail']['output'] ?? '');
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException((string) ($result['error'] ?? 'failed'));
        }
        if (($at = strpos($output, 'CM-FAILED: ')) !== false) {
            throw new RuntimeException(mb_substr(substr($output, $at + 11), 0, 1500));
        }

        return $output;
    }

    /**
     * Shared by both scripts: the site's .env, parsed as the app parses it,
     * and a way to run a command that stops the script when it fails.
     * $argv: root, the application's folder in the repository (may be empty), pg bin dir, engine, URL.
     */
    private const PRELUDE = <<<'PHP'
        [, $root, $sub, $pgBin, $engine, $url] = $argv;
        require "{$root}/current/{$sub}/vendor/autoload.php";
        $env = Dotenv\Dotenv::parse(file_get_contents("{$root}/shared/.env"));
        // The gateway keeps no output from a command that fails, so a
        // failure is reported in the output, after which the script exits 0.
        $run = function (string $cmd): void {
            exec('bash -o pipefail -c '.escapeshellarg($cmd).' 2>&1', $out, $code);
            if ($code !== 0) { echo 'CM-FAILED: '.strtok($cmd, ' ')." exited {$code}: ".implode("\n", array_slice($out, -15)); exit(0); }
        };
        $db = $env['DB_DATABASE'] ?? '';
        if ($engine === 'pgsql') {
            putenv('PGHOST='.($env['DB_HOST'] ?? '')); putenv('PGPORT='.($env['DB_PORT'] ?? '5432'));
            putenv('PGUSER='.($env['DB_USERNAME'] ?? '')); putenv('PGPASSWORD='.($env['DB_PASSWORD'] ?? ''));
            putenv('PGDATABASE='.$db); putenv('PGSSLMODE='.($env['DB_SSLMODE'] ?? 'prefer'));
        }
        $cnf = null;
        if ($engine === 'mysql') {
            $cnf = tempnam(sys_get_temp_dir(), 'cm-my'); chmod($cnf, 0600);
            $q = fn ($v) => '"'.addcslashes((string) $v, '"\\').'"';
            file_put_contents($cnf, "[client]\nhost=".$q($env['DB_HOST'] ?? '')."\nport=".$q($env['DB_PORT'] ?? '3306')."\nuser=".$q($env['DB_USERNAME'] ?? '')."\npassword=".$q($env['DB_PASSWORD'] ?? '')."\n");
            register_shutdown_function(fn () => @unlink($cnf));
        }
        $file = tempnam(sys_get_temp_dir(), 'cm-db');
        register_shutdown_function(fn () => @unlink($file));

        PHP;

    private const DUMP_BODY = <<<'PHP'
        match ($engine) {
            'pgsql' => $run("{$pgBin}/pg_dump -Fc --no-owner --no-acl -f ".escapeshellarg($file)),
            'mysql' => $run('mysqldump --defaults-extra-file='.escapeshellarg($cnf).' --single-transaction --no-tablespaces --set-gtid-purged=OFF '.escapeshellarg($db).' | gzip > '.escapeshellarg($file)),
            'sqlite' => (function () use ($db, $file, $run) {
                $copy = $file.'.copy';
                (new PDO('sqlite:'.$db))->exec('VACUUM INTO '.(new PDO('sqlite::memory:'))->quote($copy));
                $run('gzip -c '.escapeshellarg($copy).' > '.escapeshellarg($file).' && rm -f '.escapeshellarg($copy));
            })(),
        };
        PHP;

    private const DUMP = self::DUMP_BODY."\n".<<<'PHP'
        $run('curl -fsS -X PUT --upload-file '.escapeshellarg($file).' '.escapeshellarg($url));
        echo json_encode(['bytes' => filesize($file)]);
        PHP;

    /** $url is the file to write on this server; dumps in its directory older than 14 days are deleted. */
    private const DUMP_LOCAL = self::DUMP_BODY."\n".<<<'PHP'
        $dir = dirname($url);
        is_dir($dir) || mkdir($dir, 0700, true);
        copy($file, $url);
        chmod($url, 0600);
        foreach (glob($dir.'/*') ?: [] as $old) {
            if (filemtime($old) < time() - 14 * 86400) { @unlink($old); }
        }
        echo json_encode(['bytes' => filesize($url)]);
        PHP;

    /** $url is a backup to download, or db://host:port to dump the same database from first. */
    private const LOAD = <<<'PHP'
        if (str_starts_with($url, 'db://')) {
            [$host, $port] = explode(':', substr($url, 5)) + [1 => ''];
            match ($engine) {
                'pgsql' => $run('PGHOST='.escapeshellarg($host).' PGPORT='.escapeshellarg($port)." {$pgBin}/pg_dump -Fc --no-owner --no-acl -f ".escapeshellarg($file)),
                'mysql' => $run('mysqldump --defaults-extra-file='.escapeshellarg($cnf).' --host='.escapeshellarg($host).' --port='.escapeshellarg($port).' --single-transaction --no-tablespaces --set-gtid-purged=OFF '.escapeshellarg($db).' | gzip > '.escapeshellarg($file)),
            };
        } else {
            $run('curl -fsS -o '.escapeshellarg($file).' '.escapeshellarg($url));
        }
        match ($engine) {
            'pgsql' => $run("{$pgBin}/pg_restore --clean --if-exists --no-owner --no-acl --single-transaction -d ".escapeshellarg($db).' '.escapeshellarg($file)),
            'mysql' => $run('gunzip -c '.escapeshellarg($file).' | mysql --defaults-extra-file='.escapeshellarg($cnf).' '.escapeshellarg($db)),
            'sqlite' => (function () use ($db, $file, $run) {
                $run('gunzip -c '.escapeshellarg($file).' > '.escapeshellarg($db.'.restoring'));
                rename($db.'.restoring', $db);
                @unlink($db.'-wal'); @unlink($db.'-shm');
            })(),
        };
        echo 'restored';
        PHP;
}
