<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Sites\Sites;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use SensitiveParameter;
use stdClass;
use Throwable;

/**
 * PostgreSQL, MySQL and Valkey on the application's own server: the "local"
 * way to supply a service, labelled managed by cloud-manager. Single-server
 * applications only; a shared database across servers is a cluster.
 *
 * Nothing is exposed: each binds 127.0.0.1. One database and one user per
 * site, with a random password that lives only in the site's injected
 * variables in the vault. The administrator credentials are never held: the
 * grant runs as root on the server over the local socket.
 *
 * Valkey is installed from Ubuntu's valkey-server when 24.04 has it, else
 * redis-server, which speaks the same protocol to phpredis.
 */
final class LocalServices
{
    /** service => label, with the engine key sites use for DB_CONNECTION. */
    public const SERVICES = [
        'pgsql' => ['label' => 'PostgreSQL', 'kind' => 'database', 'connection' => 'pgsql', 'port' => 5432],
        'mysql' => ['label' => 'MySQL', 'kind' => 'database', 'connection' => 'mysql', 'port' => 3306],
        'valkey' => ['label' => 'Valkey', 'kind' => 'cache', 'port' => 6379],
    ];

    public const SCRIPT_PATH = '/root/.cm-local-service.php';

    public function __construct(private readonly GatewayClient $gateway, private readonly Sites $sites, private readonly AuditLog $audit) {}

    public static function label(string $service): string
    {
        return self::SERVICES[$service]['label'] ?? throw new InvalidArgumentException("There is no service called {$service}.");
    }

    public function installed(string $node, string $service): bool
    {
        return DB::table('server_services')->where('node_id', $node)->where('service', $service)->where('state', 'installed')->exists();
    }

    /** @return array<string, string> service => version, for the node's card */
    public function on(string $node): array
    {
        return DB::table('server_services')->where('node_id', $node)->where('state', 'installed')->pluck('version', 'service')->map(fn ($v) => (string) $v)->all();
    }

    /**
     * What installing a service does, as an idempotent recipe.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function installRecipe(string $service): array
    {
        self::label($service);

        $steps = match ($service) {
            // Ubuntu ships an older PostgreSQL, so the package comes from PostgreSQL's own repository. A server made only
            // to run a database never had the stack, which is the other place that adds it.
            'pgsql' => [
                ServerStack::pgdgRepositoryStep(),
                ['id' => 'pkg-postgresql', 'type' => 'package', 'name' => 'postgresql-'.ServerStack::PG_CLIENT],
                ['id' => 'svc-postgresql', 'type' => 'service', 'name' => 'postgresql', 'state' => 'running'],
            ],
            'mysql' => [
                ['id' => 'pkg-mysql-server', 'type' => 'package', 'name' => 'mysql-server'],
                ['id' => 'svc-mysql', 'type' => 'service', 'name' => 'mysql', 'state' => 'running'],
            ],
            'valkey' => [[
                'id' => 'valkey', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'pkg=redis-server; apt-cache show valkey-server >/dev/null 2>&1 && pkg=valkey-server;'
                    .' DEBIAN_FRONTEND=noninteractive apt-get install -y -q "$pkg" && systemctl enable --now "$pkg"'],
                'unless' => ['sh', '-c', 'command -v valkey-server || command -v redis-server'],
                'description' => 'Valkey (or Redis, where Ubuntu has no Valkey) is installed and running on 127.0.0.1',
            ]],
        };

        // Packages create their service user with chfn, which refuses to run as root while root's password is flagged as
        // expired (a new Droplet can start that way), and an install that stopped there leaves dpkg half done. Both are
        // put right first; each is skipped when there is nothing to do.
        $prepare = [
            [
                'id' => 'root-not-expired', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'chage -d "$(date +%Y-%m-%d)" root'],
                'unless' => ['sh', '-c', '! chage -l root | grep -q "must be changed"'],
                'description' => "root's password is not marked expired, which stops packages creating their service user",
            ],
            [
                'id' => 'dpkg-repair', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'export DEBIAN_FRONTEND=noninteractive; apt-get install -f -y -q && dpkg --configure -a'],
                'unless' => ['sh', '-c', 'test -z "$(dpkg --audit)"'],
                'description' => 'an install that stopped half way is finished: apt installs what it was missing, then dpkg configures what is left',
            ],
        ];

        return ['name' => "install-{$service}", 'steps' => [...$prepare, ...$steps]];
    }

    /**
     * Installs the service on the node if it is not there. Safe to repeat.
     *
     * @param  Closure(string, string): void  $log
     */
    public function install(string $organizationId, string $node, string $service, Closure $log, ?string $reason = null): void
    {
        if ($this->installed($node, $service)) {
            $log('service', self::label($service).' is already on '.(strstr($node, '.', true) ?: $node));

            return;
        }
        $result = $this->gateway->run($organizationId, $node, 'apply_recipe', ['spec' => self::installRecipe($service)], timeoutSeconds: 900, waitSeconds: 930,
            reason: $reason ?? 'Install '.self::label($service));
        foreach ($result['detail']['run']['steps'] ?? [] as $step) {
            $log((string) $step['action'], (string) $step['description']);
        }
        if (($result['status'] ?? null) !== 'succeeded') {
            // apt prints its reason at the end, so the end is what is kept.
            $why = trim((string) ($result['error'] ?? 'no detail'));
            throw new RuntimeException('Installing '.self::label($service).' failed: '.(mb_strlen($why) > 1800 ? mb_substr($why, 0, 300)."\n…\n".mb_substr($why, -1500) : $why));
        }

        DB::table('server_services')->updateOrInsert(['node_id' => $node, 'service' => $service],
            ['organization_id' => $organizationId, 'state' => 'installed', 'updated_at' => now(), 'created_at' => now()]);
        $this->audit->record($organizationId, Actor::system('control-plane'), 'server.service_installed', resourceType: 'node', resourceId: $node, metadata: ['service' => $service]);
        $log('service', self::label($service).' installed, listening on 127.0.0.1 only');
    }

    /**
     * Gives the site a database (or its cache) on its own server and points
     * its injected variables at it. Takes effect on the next deploy.
     *
     * @param  Closure(string, string): void  $log
     * @return list<string>
     */
    public function attach(stdClass $site, string $service, Closure $log): array
    {
        if (($site->compute ?? 'single') !== 'single') {
            throw new InvalidArgumentException('A database or cache on the server is for a single server; several servers need one they share.');
        }
        $info = self::SERVICES[$service] ?? throw new InvalidArgumentException("There is no service called {$service}.");
        $node = (string) $site->node_id;
        $this->install($site->organization_id, $node, $service, $log);

        $name = str_replace('-', '_', (string) $site->name);
        if ($info['kind'] === 'database') {
            $password = bin2hex(random_bytes(16));
            $this->grant($site, $service, $name, $password);
            $log('db', "database and user {$name} created on ".self::label($service));
            $this->sites->setInjected($site, [
                'DB_CONNECTION' => $info['connection'], 'DB_HOST' => '127.0.0.1', 'DB_PORT' => (string) $info['port'],
                'DB_DATABASE' => $name, 'DB_USERNAME' => $name, 'DB_PASSWORD' => $password, 'DB_SSLMODE' => null, 'PGSSLROOTCERT' => null, 'MYSQL_ATTR_SSL_CA' => null,
            ], self::label($service).' on this server');
            DB::table('sites')->where('id', $site->id)->update(['database_mode' => "local:{$service}", 'updated_at' => now()]);
            $done = ['.env points at the database on this server; deploy to switch'];
            if (($over = $this->sites->overriddenKeys($site, Sites::INJECTABLE['database'])) !== []) {
                $done[] = 'your own variables override '.implode(', ', $over).', so those keep their value; remove the overrides to use this database';
            }
        } else {
            $prefix = $name.'_';
            $this->sites->setInjected($site, [
                'REDIS_CLIENT' => 'phpredis', 'REDIS_HOST' => '127.0.0.1', 'REDIS_PORT' => (string) $info['port'], 'REDIS_USERNAME' => null, 'REDIS_PASSWORD' => null,
                'REDIS_PREFIX' => $prefix, 'CACHE_PREFIX' => $prefix, 'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis', 'QUEUE_CONNECTION' => 'redis',
            ], self::label($service).' on this server');
            DB::table('sites')->where('id', $site->id)->update(['cache_mode' => 'local:valkey', 'updated_at' => now()]);
            $done = ["cache, sessions and queue on this server under prefix {$prefix}", '.env points at it; deploy to switch'];
        }
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.local_service_attached', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['service' => $service]);

        return $done;
    }

    /** "None": nothing is installed or injected, and the default SQLite or database-backed settings are dropped. */
    public function detach(stdClass $site, string $kind): void
    {
        if ($kind === 'database') {
            $this->sites->setInjected($site, ['DB_CONNECTION' => null, 'DB_DATABASE' => null], 'None: managed by you');
            DB::table('sites')->where('id', $site->id)->update(['database_mode' => 'none', 'updated_at' => now()]);
        } else {
            $this->sites->setInjected($site, ['SESSION_DRIVER' => null, 'CACHE_STORE' => null, 'QUEUE_CONNECTION' => null], 'None: managed by you');
            DB::table('sites')->where('id', $site->id)->update(['cache_mode' => 'none', 'updated_at' => now()]);
        }
    }

    /** Creates the site's database and user as root over the local socket. The password is in the program, which is removed whatever happens. */
    private function grant(stdClass $site, string $service, string $name, #[SensitiveParameter] string $password): void
    {
        $this->grantOnNode($site->organization_id, (string) $site->node_id, $service, $name, $password, "Create {$site->name}'s database on this server");
    }

    /**
     * The same, on any node whose engine this installed. $hosts are the places the user may connect from: the
     * server's own socket by default, '%' for a database server that other servers reach over the network.
     *
     * @param  list<string>  $hosts
     */
    public function grantOnNode(string $organizationId, string $node, string $service, string $name, #[SensitiveParameter] string $password, string $reason, array $hosts = ['localhost', '127.0.0.1']): void
    {
        $this->runProgram($organizationId, $node, 'local-database', self::grantScript($service, $name, $password, $hosts), "create {$name} its database", $reason, 'Creating the database failed');
    }

    /** Drops a database and its user on the node: the reverse of grantOnNode. */
    public function dropOnNode(string $organizationId, string $node, string $service, string $name, string $reason, array $hosts = []): void
    {
        $this->runProgram($organizationId, $node, 'local-database-drop', self::dropScript($service, $name, $hosts), "drop {$name} and its user", $reason, 'Dropping the database failed');
    }

    /** Runs one PHP program as a 0600 file on the node, removed whatever happens, so a password in it never lingers. */
    private function runProgram(string $organizationId, string $node, string $recipe, string $program, string $description, string $reason, string $failure): void
    {
        try {
            $result = $this->gateway->run($organizationId, $node, 'apply_recipe', ['spec' => [
                'name' => $recipe,
                'steps' => [
                    ['id' => 'script', 'type' => 'file', 'path' => self::SCRIPT_PATH, 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode($program)],
                    ['id' => 'grant', 'type' => 'command', 'name' => ServerStack::phpBinary(ServerStack::defaultPhpFor($node)), 'args' => [self::SCRIPT_PATH], 'description' => $description],
                    ['id' => 'forget', 'type' => 'absent', 'path' => self::SCRIPT_PATH],
                ],
            ]], timeoutSeconds: 120, reason: $reason);
        } finally {
            try {
                $this->gateway->run($organizationId, $node, 'apply_recipe', ['spec' => ['name' => $recipe.'-cleanup', 'steps' => [['id' => 'forget', 'type' => 'absent', 'path' => self::SCRIPT_PATH]]]], timeoutSeconds: 30);
            } catch (Throwable) {
            }
        }
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException($failure.': '.mb_substr((string) ($result['error'] ?? 'no detail'), 0, 600));
        }
    }

    /**
     * The program: SQL piped to the server's own client, as the user that owns
     * the engine's socket. Nothing is on a command line; the password is only
     * inside this file, which is 0600 and removed after.
     */
    /** @param list<string> $hosts */
    public static function grantScript(string $service, string $name, #[SensitiveParameter] string $password, array $hosts = ['localhost', '127.0.0.1']): string
    {
        if (! preg_match('/^[a-z0-9_]{1,63}$/', $name)) {
            throw new InvalidArgumentException("Refusing to create a database called {$name}.");
        }
        if (! preg_match('/^[0-9a-f]{32}$/', $password)) {
            throw new InvalidArgumentException('Unexpected password shape.');
        }
        foreach ($hosts as $host) {
            if (! preg_match('/^(%|localhost|[0-9.]{7,15})$/', $host)) {
                throw new InvalidArgumentException("Refusing to allow connections from {$host}.");
            }
        }

        if ($service === 'pgsql') {
            $sql = <<<SQL
DO \$\$ BEGIN
  IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname = '{$name}') THEN CREATE ROLE "{$name}" LOGIN PASSWORD '{$password}';
  ELSE ALTER ROLE "{$name}" PASSWORD '{$password}'; END IF;
END \$\$;
SELECT 'CREATE DATABASE "{$name}" OWNER "{$name}"' WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = '{$name}')\\gexec
SQL;
        } elseif ($service === 'mysql') {
            $sql = "CREATE DATABASE IF NOT EXISTS `{$name}`;\n"
                .implode("\n", array_map(fn ($host) => "CREATE USER IF NOT EXISTS '{$name}'@'{$host}' IDENTIFIED BY '{$password}'; ALTER USER '{$name}'@'{$host}' IDENTIFIED BY '{$password}'; GRANT ALL PRIVILEGES ON `{$name}`.* TO '{$name}'@'{$host}';", $hosts))
                .(in_array('%', $hosts, true) ? '' : "\nDROP USER IF EXISTS '{$name}'@'%';")
                ."\nFLUSH PRIVILEGES;";
        } else {
            throw new InvalidArgumentException("{$service} has no database to create.");
        }

        return self::program($service, $sql);
    }

    /** The reverse: the database goes, then its user (and, for PostgreSQL, anyone still connected to it). @param list<string> $hosts extra MySQL account hosts to drop */
    public static function dropScript(string $service, string $name, array $hosts = []): string
    {
        foreach ($hosts as $host) {
            if (! preg_match('/^(%|localhost|[0-9.]{7,15})$/', $host)) {
                throw new InvalidArgumentException("Refusing to drop an account at {$host}.");
            }
        }
        if (! preg_match('/^[a-z0-9_]{1,63}$/', $name)) {
            throw new InvalidArgumentException("Refusing to drop a database called {$name}.");
        }
        $sql = match ($service) {
            'pgsql' => "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '{$name}';\nDROP DATABASE IF EXISTS \"{$name}\";\nDROP ROLE IF EXISTS \"{$name}\";",
            'mysql' => "DROP DATABASE IF EXISTS `{$name}`;\nDROP USER IF EXISTS ".implode(', ', array_map(fn ($h) => "'{$name}'@'{$h}'", array_unique(['%', 'localhost', '127.0.0.1', ...$hosts]))).';',
            default => throw new InvalidArgumentException("{$service} has no database to drop."),
        };

        return self::program($service, $sql);
    }

    /** SQL piped to the engine's own client, as the user that owns its socket. Nothing is on a command line. */
    private static function program(string $service, string $sql): string
    {
        $command = $service === 'pgsql'
            ? "['runuser', '-u', 'postgres', '--', 'psql', '-v', 'ON_ERROR_STOP=1', '-q']"
            : "['mysql', '--user=root']";

        return "<?php\n"
            ."\$p = proc_open({$command}, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], \$pipes);\n"
            .'fwrite($pipes[0], '.var_export($sql, true).");\n"
            ."fclose(\$pipes[0]);\n"
            ."\$out = stream_get_contents(\$pipes[1]).stream_get_contents(\$pipes[2]);\n"
            ."\$code = proc_close(\$p);\n"
            ."if (\$code !== 0) { fwrite(STDERR, 'exited '.\$code.': '.substr(\$out, -400).\"\\n\"); exit(1); }\n"
            ."echo \"created\\n\";\n";
    }
}
