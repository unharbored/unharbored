<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Git\GitRepo;
use App\Lib\Servers\ServerStack;
use App\Lib\Vault\Vault;
use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use SensitiveParameter;
use stdClass;

/**
 * A site is one application on one node, deployed from one GitHub repository.
 *
 * Each site gets its own deploy key, so a server holds read access to the
 * repositories it serves and nothing else. The private half and the site's
 * .env live in the vault; the node receives them in the setup recipe, which
 * is re-applied on every deploy so a changed .env takes effect then.
 *
 * Deploy keys are registered on GitHub by hand for now. A GitHub App replaces
 * them later, at which point nothing long-lived is stored on the node.
 */
final class Sites
{
    /**
     * GitHub's published SSH host key, pinned rather than trusted on first
     * use. If GitHub rotates it, clones fail loudly here instead of silently
     * accepting whatever answered.
     */
    public const GITHUB_HOST_KEY = 'github.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIOMqqnkVzrm0SdG6UOoqKLsabgH5C9okWi0dh2l9GKJl';

    public function __construct(
        private readonly Vault $vault,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Records the site, mints its deploy key, and stores a starting .env.
     *
     * @return stdClass The site row.
     */
    public function create(
        string $organizationId,
        string $nodeId,
        string $name,
        string $domain,
        ?string $repository,
        string $branch = 'main',
        ?string $gitHostKey = null,
        ?int $projectId = null,
    ): stdClass {
        self::validate($name, $domain, $repository, $branch);
        if ($gitHostKey !== null && ! preg_match('/^\S+ (ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp\d+) [A-Za-z0-9+\/=]+$/', trim($gitHostKey))) {
            throw new InvalidArgumentException('The SSH host key is not a known_hosts line.');
        }

        if (($why = self::nameInUse($organizationId, $name)) !== null) {
            throw new InvalidArgumentException("{$name} cannot be used: {$why}.");
        }

        // The one place an application row is made, so none can be without a project (Default unless one is asked for).
        $projectId = \App\Lib\Auth\Projects::resolve($organizationId, $projectId);

        // An image application has no repository (null): no deploy key either, and every git path skips the row.
        $public = null;
        if ($repository !== null) {
            $key = EC::createKey('Ed25519');
            $public = $key->getPublicKey()->toString('OpenSSH', ['comment' => "cloud-manager {$name}@{$nodeId}"]);
            $this->vault->put($organizationId, self::deployKeySecret($nodeId, $name), $key->toString('OpenSSH'));
        }
        // The user's own variables start empty. What is made for them, the key and the names, is injected like everything else Unharbored sets.
        $this->vault->put($organizationId, self::envSecret($nodeId, $name), '');
        $this->vault->put($organizationId, self::injectedSecret($nodeId, $name), (string) json_encode($this->defaultInjected($name) + $this->generated($name, $domain)));

        $id = DB::table('sites')->insertGetId([
            'organization_id' => $organizationId,
            'project_id' => $projectId,
            'node_id' => $nodeId,
            'provider' => DB::table('nodes')->where('node_id', $nodeId)->value('provider') ?: 'custom',
            'name' => $name,
            'domain' => $domain,
            'repository' => $repository,
            'branch' => $branch,
            'deploy_key_public' => $public,
            // A public repository is cloned with no key at all.
            'git_source' => $repository !== null && str_starts_with($repository, 'https://') ? 'https' : 'deploy_key',
            'git_host_key' => $gitHostKey,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            organizationId: $organizationId,
            actor: Actor::system('control-plane'),
            action: 'site.created',
            resourceType: 'site',
            resourceId: "{$nodeId}/{$name}",
            metadata: ['domain' => $domain, 'repository' => $repository, 'branch' => $branch],
        );

        return $this->find($nodeId, $name);
    }

    /**
     * Turns a new site into an application from a Dockerfile or an image: its runtime, its container state, and none of Laravel's
     * injected defaults (no APP_KEY, no SQLite). Pull and push deploys are off for an image application: it has no repository.
     *
     * @param  array<string, mixed>  $state  see ContainerApps::state()
     */
    public function makeContainer(stdClass $site, string $runtime, array $state): stdClass
    {
        if (! in_array($runtime, [Runtimes::DOCKERFILE, Runtimes::IMAGE], true)) {
            throw new InvalidArgumentException('A container application is from a Dockerfile or a Docker image.');
        }
        $this->vault->put($site->organization_id, self::injectedSecret($site->node_id, $site->name), '{}');
        DB::table('sites')->where('id', $site->id)->update(['runtime' => $runtime, 'container' => json_encode($state),
            'push_to_deploy' => false, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.runtime_changed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['runtime' => $runtime]);

        return DB::table('sites')->where('id', $site->id)->first();
    }

    public function find(string $nodeId, string $name): ?stdClass
    {
        return DB::table('sites')->where('node_id', $nodeId)->where('name', $name)->first();
    }

    /**
     * Why an application name cannot be used yet, or null when it can.
     *
     * A deleted application keeps its name while its data is retained or an
     * app-owned cloud resource (bucket, balancer, pool) survives, so a new
     * one can neither overwrite its secrets nor adopt its data.
     */
    public static function nameInUse(string $organizationId, string $name): ?string
    {
        // A name is the organisation's, not a server's: the database and user on a
        // cluster are named after it, so a second application of that name on another
        // server would share and delete the first one's data (audit 2026-11-08, #7).
        if (DB::table('sites')->where('organization_id', $organizationId)->where('name', $name)->exists()) {
            return "an application called {$name} already exists in this team";
        }
        // Services (templates) share the namespace: both use the name for folders, units and nginx files.
        if (DB::table('services')->where('organization_id', $organizationId)->where('name', $name)->exists()) {
            return "a service called {$name} already exists in this team";
        }
        if (DB::table('site_archives')->where('organization_id', $organizationId)->where('name', $name)->exists()) {
            return "an application called {$name} was deleted and its data was kept; delete that retained data first";
        }
        if (DB::table('resources')->where('organization_id', $organizationId)->where('slug', $name)
            ->whereIn('type', ['bucket', 'load_balancer', 'autoscale_pool'])->where('state', '!=', 'deleted')->exists()) {
            return "an earlier application called {$name} still owns cloud resources";
        }

        return null;
    }

    /**
     * The .env a site runs with: the user's custom variables, then every
     * injected value the user has not overridden. A key the user set wins and
     * appears once. This is what reaches the server and what everything else
     * reads; only the console's editor and the API's writes see the layers.
     */
    public function env(stdClass $site): string
    {
        $custom = $this->customEnv($site);
        $mine = self::envKeys($custom);
        $lines = [];
        foreach ($this->injected($site) as $key => $entry) {
            if (! isset($mine[$key])) {
                $lines[] = "{$key}={$entry['value']}";
            }
        }

        return $lines === [] ? $custom
            : rtrim($custom, "\n")."\n\n# Injected by cloud-manager. Set the same name in your own variables to override one.\n".implode("\n", $lines)."\n";
    }

    /** The user's own variables, as written. Unharbored never writes here. */
    /**
     * Variables of the user's that only repeat what Unharbored injects: the
     * same value as is injected now, or as it injected before a database or
     * cache was attached. An earlier fault wrote the merged environment into
     * the user's layer whenever a domain or HTTPS changed, and a copy there
     * wins over every later change to the real value. A value that differs
     * from both is the user's own and is left alone.
     *
     * @return list<string>
     */
    public function envCopies(stdClass $site): array
    {
        $injected = array_map(fn ($e) => $e['value'], $this->injected($site));
        $defaults = array_map(fn ($e) => $e['value'], $this->defaultInjected($site->name));
        $copies = [];
        foreach (preg_split('/\R/', $this->customEnv($site)) as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m)) {
                $value = trim($m[2], " \"'");
                if (($injected[$m[1]] ?? null) === $value || ($defaults[$m[1]] ?? null) === $value) {
                    $copies[] = $m[1];
                }
            }
        }

        return array_values(array_unique($copies));
    }

    /**
     * Takes those copies out of the user's variables, with the "Injected by
     * Unharbored" comment that came with them, so what is injected applies.
     *
     * @return list<string> the names removed
     */
    public function removeEnvCopies(stdClass $site): array
    {
        $copies = $this->envCopies($site);
        if ($copies === []) {
            return [];
        }
        $env = $this->customEnv($site);
        foreach ($copies as $key) {
            $env = (string) preg_replace('/^'.preg_quote($key, '/').'=.*\R?/m', '', $env);
        }
        $env = (string) preg_replace('/^# Injected by cloud-manager\..*\R?/m', '', $env);
        $env = rtrim((string) preg_replace("/\n{3,}/", "\n\n", $env), "\n")."\n";
        $this->setEnv($site, $env, 'removed copies of injected values: '.implode(', ', $copies));

        return $copies;
    }

    public function customEnv(stdClass $site): string
    {
        return (string) $this->vault->get($site->organization_id, self::envSecret(self::home($site), $site->name));
    }

    /**
     * What Unharbored sets from the resources the site uses: its database,
     * cache, storage and Reverb. Rewritten when those change, and overridden
     * by anything of the same name in the custom layer.
     *
     * @return array<string, array{value: string, source: string}>
     */
    public function injected(stdClass $site): array
    {
        $json = $this->vault->get($site->organization_id, self::injectedSecret(self::home($site), $site->name));

        return $json === null || $json === '' ? [] : (json_decode((string) $json, true) ?: []);
    }

    /**
     * Sets injected values, saying where they came from; a null removes one.
     * The user's custom variables are left alone, so an override survives an
     * attach, a rotation or a detach. Takes effect on the next deploy.
     *
     * @param  array<string, string|null>  $values
     */
    public function setInjected(stdClass $site, array $values, string $source): void
    {
        // A site from before the layers existed is split first, so its old
        // copies of these values cannot override the new ones.
        $this->splitEnvLayers($site);
        $current = $this->injected($site);
        $next = $current;
        foreach ($values as $key => $value) {
            if ($value === null) {
                unset($next[$key]);
            } else {
                $next[$key] = ['value' => (string) $value, 'source' => $source];
            }
        }
        if ($next === $current) {
            return;
        }
        $this->vault->put($site->organization_id, self::injectedSecret(self::home($site), $site->name), (string) json_encode($next));
        $this->markPending($site);

        $before = array_map(fn ($e) => $e['value'], $current);
        $after = array_map(fn ($e) => $e['value'], $next);
        $changes = [
            'added' => array_values(array_keys(array_diff_key($after, $before))),
            'removed' => array_values(array_keys(array_diff_key($before, $after))),
            'changed' => array_values(array_keys(array_filter(array_intersect_key($after, $before), fn ($v, $k) => $before[$k] !== $v, ARRAY_FILTER_USE_BOTH))),
        ];
        $latest = (int) DB::table('site_env_versions')->where('site_id', $site->id)->max('version');
        DB::table('site_env_versions')->insert(['site_id' => $site->id, 'version' => $latest + 1, 'layer' => 'injected',
            'reason' => mb_substr("{$source}: ".implode(', ', array_keys($values)), 0, 250), 'changes' => json_encode($changes), 'created_at' => now()]);
        $this->pruneEnvVersions($site);

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.env_injected',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['source' => $source, 'keys' => array_keys($values)],
        );
    }

    /**
     * Hands injected values over to the user: they move into the custom layer
     * and stop being injected, so the app keeps its connection and Unharbored
     * stops managing it. A key the user already set is left as theirs.
     *
     * @param  list<string>  $keys
     */
    public function releaseInjected(stdClass $site, array $keys): void
    {
        $injected = $this->injected($site);
        $mine = self::envKeys($this->customEnv($site));
        $custom = $this->customEnv($site);
        $release = [];
        foreach ($keys as $key) {
            if (isset($injected[$key]) && ! isset($mine[$key])) {
                $custom = rtrim($custom, "\n")."\n{$key}={$injected[$key]['value']}\n";
                $release[] = $key;
            }
        }
        if ($release !== []) {
            $this->setEnv($site, $custom, 'taken over from Unharbored: '.implode(', ', $release));
        }
        $this->setInjected($site, array_fill_keys(array_intersect($keys, array_keys($injected)), null), 'Released to you');
    }

    /**
     * The injected names the user's custom layer overrides, optionally limited to some keys.
     *
     * @param  list<string>|null  $only
     * @return list<string>
     */
    public function overriddenKeys(stdClass $site, ?array $only = null): array
    {
        $keys = array_keys(array_intersect_key($this->injected($site), self::envKeys($this->customEnv($site))));

        return array_values($only === null ? $keys : array_intersect($keys, $only));
    }

    /**
     * The names Unharbored has injected, by what injects them. A custom
     * variable of one of these names is an override.
     */
    public const INJECTABLE = [
        'database' => ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_SSLMODE', 'MYSQL_ATTR_SSL_CA'],
        'cache' => ['REDIS_CLIENT', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_PREFIX', 'CACHE_PREFIX', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION'],
        'storage' => ['FILESYSTEM_DISK', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY', 'AWS_DEFAULT_REGION', 'AWS_BUCKET', 'AWS_ENDPOINT', 'AWS_URL', 'AWS_USE_PATH_STYLE_ENDPOINT'],
        'reverb' => ['BROADCAST_CONNECTION', 'REVERB_APP_ID', 'REVERB_APP_KEY', 'REVERB_APP_SECRET', 'REVERB_HOST', 'REVERB_PORT', 'REVERB_SCHEME', 'REVERB_SERVER_HOST', 'REVERB_SERVER_PORT'],
    ];

    /**
     * Both layers for the Variables page: the custom text, and each injected
     * value with where it came from and whether the user's variables override it.
     *
     * @return array{custom: string, injected: list<array{key: string, value: string, source: string, overridden: bool}>, overridden: list<string>}
     */
    public function envLayers(stdClass $site): array
    {
        $over = $this->overriddenKeys($site);
        $rows = [];
        foreach ($this->injected($site) as $key => $entry) {
            $rows[] = ['key' => $key, 'value' => $entry['value'], 'source' => $entry['source'], 'overridden' => in_array($key, $over, true)];
        }
        usort($rows, fn ($a, $b) => strcmp($a['key'], $b['key']));

        return ['custom' => $this->customEnv($site), 'injected' => $rows, 'overridden' => $over];
    }

    /** Whether the site has an injected layer yet; one made before the layers existed does not. */
    private function hasLayers(stdClass $site): bool
    {
        return $this->vault->get($site->organization_id, self::injectedSecret(self::home($site), $site->name)) !== null;
    }

    /**
     * Splits a site's single .env into the two layers, once. What the platform
     * wrote (database, cache, storage and Reverb values that still agree with
     * the resources the site uses) becomes injected; everything else, and any
     * value the user changed to something else, stays theirs. The effective
     * .env is the same before and after, so nothing needs a redeploy.
     *
     * @return array{injected: list<string>, custom: list<string>}|null null when already split
     */
    public function splitEnvLayers(stdClass $site): ?array
    {
        if ($this->hasLayers($site)) {
            return null;
        }
        $site = DB::table('sites')->where('id', $site->id)->first() ?? $site;
        $before = $this->customEnv($site);
        $have = self::envKeys($before);
        $resource = fn ($id) => $id === null ? null : DB::table('resources')->where('id', $id)->first(['slug', 'external_id', 'attributes']);
        $attrs = fn ($r) => $r === null ? [] : (json_decode((string) $r->attributes, true) ?: []);
        $db = $resource($site->database_cluster_id ?? null);
        $cache = $resource($site->cache_cluster_id ?? null);
        $bucket = $resource($site->bucket_id ?? null);
        $dbName = str_replace('-', '_', $site->name);
        $sqlitePath = '/home/'.ServerStack::DEPLOY_USER."/{$site->name}/shared/database.sqlite";
        $default = 'Default: SQLite on the server';

        // KEY => source when the key is the platform's, from what the site uses.
        $mine = [];
        if ($db !== null) {
            $source = "Database cluster {$db->slug}";
            foreach (self::INJECTABLE['database'] as $key) {
                $value = trim($have[$key] ?? '', '"\'');
                $agrees = match ($key) {
                    'DB_HOST' => ! isset($attrs($db)['host']) || $value === $attrs($db)['host'],
                    'DB_PORT' => ! isset($attrs($db)['port']) || $value === (string) $attrs($db)['port'],
                    'DB_DATABASE', 'DB_USERNAME' => $value === $dbName,
                    'DB_CONNECTION' => in_array($value, ['pgsql', 'mysql'], true),
                    default => true,
                };
                if (isset($have[$key]) && $agrees) {
                    $mine[$key] = $source;
                }
            }
        } elseif (trim($have['DB_CONNECTION'] ?? '', '"\'') === 'sqlite') {
            $mine['DB_CONNECTION'] = $default;
            if (trim($have['DB_DATABASE'] ?? '', '"\'') === $sqlitePath) {
                $mine['DB_DATABASE'] = $default;
            }
        }
        foreach (['SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION'] as $key) {
            $value = trim($have[$key] ?? '', '"\'');
            if ($cache !== null && $value === 'redis') {
                $mine[$key] = "Cache cluster {$cache->slug}";
            } elseif ($value === 'database') {
                $mine[$key] = $default;
            }
        }
        if ($cache !== null) {
            foreach (['REDIS_CLIENT', 'REDIS_HOST', 'REDIS_PORT', 'REDIS_USERNAME', 'REDIS_PASSWORD', 'REDIS_PREFIX', 'CACHE_PREFIX'] as $key) {
                $agrees = $key !== 'REDIS_HOST' || ! isset($attrs($cache)['host']) || trim($have[$key] ?? '', '"\'') === 'tls://'.$attrs($cache)['host'];
                if (isset($have[$key]) && $agrees) {
                    $mine[$key] = "Cache cluster {$cache->slug}";
                }
            }
        }
        if ($bucket !== null) {
            foreach (self::INJECTABLE['storage'] as $key) {
                if (isset($have[$key]) && ($key !== 'AWS_BUCKET' || trim($have[$key], '"\'') === (string) $bucket->external_id)) {
                    $mine[$key] = "Spaces bucket {$bucket->external_id}";
                }
            }
        }
        if ((bool) ($site->reverb ?? false) || (isset($have['REVERB_APP_ID']) && ($have['BROADCAST_CONNECTION'] ?? '') === 'log')) {
            foreach (self::INJECTABLE['reverb'] as $key) {
                if (isset($have[$key])) {
                    $mine[$key] = 'Reverb';
                }
            }
        }

        $injected = [];
        $kept = [];
        foreach (preg_split('/\R/', $before) ?: [] as $line) {
            if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $line, $m) && isset($mine[$m[1]])) {
                $injected[$m[1]] = ['value' => $m[2], 'source' => $mine[$m[1]]];

                continue;
            }
            $kept[] = $line;
        }
        $after = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)), "\n")."\n";

        $this->vault->put($site->organization_id, self::injectedSecret(self::home($site), $site->name), (string) json_encode($injected));
        $this->vault->put($site->organization_id, self::envSecret(self::home($site), $site->name), $after);
        if ($before !== $after) {
            $this->recordEnvVersion($site, $before, $after, 'split into injected and custom variables');
        }
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.env_split', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['injected' => array_keys($injected)]);

        return ['injected' => array_keys($injected), 'custom' => array_keys(self::envKeys($after))];
    }

    /**
     * A database connection as a URL, from Laravel-style DB_* values: the one
     * string most tools (psql, TablePlus, DBeaver, a DATABASE_URL) accept.
     * Null when there is no network database to point at (SQLite, or nothing).
     *
     * @param  array<string, string>  $v
     */
    public static function connectionUrl(#[SensitiveParameter] array $v): ?string
    {
        $engine = match (strtolower((string) ($v['DB_CONNECTION'] ?? ''))) {
            'pgsql', 'postgres', 'postgresql' => 'postgresql',
            'mysql', 'mariadb' => 'mysql',
            default => null,
        };
        $host = (string) ($v['DB_HOST'] ?? '');
        if ($engine === null || $host === '') {
            return null;
        }
        $port = (string) (($v['DB_PORT'] ?? '') !== '' ? $v['DB_PORT'] : ($engine === 'mysql' ? '3306' : '5432'));
        $auth = rawurlencode((string) ($v['DB_USERNAME'] ?? ''));
        if (($v['DB_PASSWORD'] ?? '') !== '') {
            $auth .= ':'.rawurlencode((string) $v['DB_PASSWORD']);
        }
        $query = $engine === 'postgresql'
            ? '?sslmode='.rawurlencode((string) (($v['DB_SSLMODE'] ?? '') !== '' ? $v['DB_SSLMODE'] : 'require'))
            : '?ssl-mode=REQUIRED';

        return "{$engine}://".($auth !== '' ? "{$auth}@" : '')."{$host}:{$port}/".rawurlencode((string) ($v['DB_DATABASE'] ?? '')).$query;
    }

    /**
     * KEY => value for every assignment in an env text.
     *
     * @return array<string, string>
     */
    public static function envKeys(#[SensitiveParameter] string $env): array
    {
        preg_match_all('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/m', $env, $m);

        return array_combine($m[1], $m[2]) ?: [];
    }

    /** Versions of a site's .env kept, newest first; older ones are deleted. */
    public const ENV_VERSIONS_KEPT = 20;

    /** Replaces the user's custom variables. */
    public function setEnv(stdClass $site, #[SensitiveParameter] string $contents, string $reason = 'changed'): void
    {
        $previous = $this->customEnv($site);
        if ($previous === $contents) {
            return;
        }
        $this->vault->put($site->organization_id, self::envSecret(self::home($site), $site->name), $contents);
        $this->markPending($site);
        $this->recordEnvVersion($site, $previous, $contents, $reason);

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.env_updated',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
        );
    }

    /** The shared .env on a server, as one recipe step: every release links to it. */
    public function envStep(stdClass $site): array
    {
        $user = ServerStack::DEPLOY_USER;

        return [
            'id' => 'env',
            'type' => 'file',
            'path' => self::root($site).'/shared/.env',
            'content_b64' => base64_encode($this->env($site)),
            'mode' => '0600',
            'sensitive' => true,
            'owner' => $user,
            'base' => "/home/{$user}",
        ];
    }

    /**
     * Everything a site needs on the node before code arrives: directories
     * owned by the deploy user, the deploy key, the pinned GitHub host key,
     * the shared .env, and the nginx vhost. Idempotent apart from the
     * ownership fixes and the reload.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function setupRecipe(stdClass $site): array
    {
        $user = ServerStack::DEPLOY_USER;
        $home = "/home/{$user}";
        $root = self::root($site);

        $steps = [];

        // Everything under the deploy user's home is writable by the application,
        // so root touches it only through owned steps: confined to the home,
        // created and owned through descriptors, never by path (audit 2026-11-08, #1).
        $owned = ['owner' => $user, 'base' => $home];
        foreach (["{$home}/.ssh", $root, "{$root}/releases", "{$root}/shared"] as $i => $dir) {
            $steps[] = ['id' => "dir-{$i}", 'type' => 'dir', 'path' => $dir, 'mode' => $i === 0 ? '0700' : '0755'] + $owned;
        }

        // A public repository is cloned with no credential: no key, no host key. An image application has no repository at all.
        if (($site->git_source ?? 'deploy_key') !== 'https' && ($site->repository ?? null) !== null) {
            $hostKey = $site->git_host_key ?? (GitRepo::parse($site->repository)->isGitHub() ? self::GITHUB_HOST_KEY : null)
                ?? throw new \RuntimeException("No SSH host key is pinned for {$site->repository}. Set it on the application's Git settings first.");
            $steps[] = [
                'id' => 'deploy-key',
                'type' => 'file',
                'path' => self::keyPath($site),
                'content_b64' => base64_encode((string) $this->vault->get($site->organization_id, self::deployKeySecret(self::home($site), $site->name))),
                'mode' => '0600',
                'sensitive' => true,
            ] + $owned;
            $steps[] = [
                'id' => 'known-hosts',
                'type' => 'file',
                'path' => "{$home}/.ssh/known_hosts_git",
                'content_b64' => base64_encode(trim($hostKey)."\n"),
                'mode' => '0644',
            ] + $owned;
        }
        // A container application's variables go to a root-owned file under /etc/cloud-manager/apps when a container is started
        // (AppRecipes::prepare), not into a file in the deploy user's home.
        if (! Runtimes::isContainer($site)) {
            $steps[] = $this->envStep($site);
        }

        // Composer's credentials for private packages: a file only the deploy user reads, in a folder Composer is pointed at.
        // Removed again when the application no longer has any.
        if (Runtimes::has($site, 'composer')) {
            $composerAuth = $this->composerAuth($site);
            if ($composerAuth !== null) {
                $steps[] = ['id' => 'composer-home', 'type' => 'dir', 'path' => self::composerHome($site), 'mode' => '0700'] + $owned;
                $steps[] = ['id' => 'composer-auth', 'type' => 'file', 'path' => self::composerHome($site).'/auth.json', 'content_b64' => base64_encode($composerAuth), 'mode' => '0600', 'sensitive' => true] + $owned;
            } else {
                $steps[] = ['id' => 'composer-auth', 'type' => 'absent', 'path' => self::composerHome($site).'/auth.json'] + $owned;
            }
        }

        // The managed database's CA, for connections that verify it (MySQL's
        // MYSQL_ATTR_SSL_CA). Public, so readable by all.
        $ca = $site->database_cluster_id ?? null
            ? json_decode((string) DB::table('resources')->where('id', $site->database_cluster_id)->value('attributes'), true)['ca_pem'] ?? null
            : null;
        if ($ca !== null) {
            $steps[] = ['id' => 'database-ca', 'type' => 'file', 'path' => "{$root}/shared/database-ca.crt", 'content_b64' => base64_encode($ca), 'mode' => '0644'] + $owned;
        }

        // nginx's default allows names up to about 50 characters; an
        // application's sslip.io name on its balancer is often longer.
        $steps[] = ['id' => 'nginx-names', 'type' => 'file', 'path' => '/etc/nginx/conf.d/00-server-names.conf',
            'content_b64' => base64_encode(ServerStack::NGINX_NAMES), 'mode' => '0644'];
        array_push($steps, ...$this->vhostSteps($site));

        return ['name' => "site-setup-{$site->name}", 'steps' => $steps];
    }

    /**
     * Writes the site's nginx config, checks it, and reloads nginx, putting
     * the previous config back if the check fails. nginx reads every file in
     * sites-enabled, so a bad config left in place would break the next reload
     * of every other site on the server, not just this one.
     *
     * @return list<array<string, mixed>>
     */
    private function vhostSteps(stdClass $site): array
    {
        $conf = "/etc/nginx/sites-enabled/{$site->name}.conf";
        $backup = "/var/backups/cloud-manager/nginx/{$site->name}.conf";

        return [
            ['id' => 'vhost-backup', 'type' => 'command', 'name' => 'sh',
                // With no config now, any older backup is stale and must not be restored over a failed first write.
                'args' => ['-c', 'install -d -m 0700 '.dirname($backup).' && if [ -f '.$conf.' ]; then cp -p '.$conf.' '.$backup.'; else rm -f '.$backup.'; fi'],
                'description' => 'previous web server configuration kept'],
            ...(self::basicAuthOn($site) ? [
                ['id' => 'auth-dir', 'type' => 'dir', 'path' => self::AUTH_DIR, 'mode' => '0755'],
                // A hash, readable by nginx's workers; the file is not under anything the application can write.
                ['id' => 'auth-file', 'type' => 'file', 'path' => self::authFile($site), 'mode' => '0644', 'sensitive' => true,
                    'content_b64' => base64_encode("{$site->basic_auth_user}:{$site->basic_auth_hash}\n")],
            ] : [['id' => 'auth-file', 'type' => 'absent', 'path' => self::authFile($site)]]),
            ['id' => 'vhost', 'type' => 'file', 'path' => $conf, 'content_b64' => base64_encode($this->vhost($site)), 'mode' => '0644'],
            // nginx's own message is the step's output; the old file is put back before the step fails.
            ['id' => 'nginx-test', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'nginx -t 2>&1 && exit 0; if [ -f '.$backup.' ]; then cp -p '.$backup.' '.$conf.'; else rm -f '.$conf.'; fi; exit 1'],
                'description' => 'nginx configuration is valid'],
            ['id' => 'reload-nginx', 'type' => 'service', 'name' => 'nginx', 'state' => 'reloaded'],
        ];
    }

    /** Where an uploaded certificate lives: outside /etc/letsencrypt, so certbot can never overwrite it. */
    public static function customCertificateDir(string $domain): string
    {
        return "/etc/cloud-manager/certs/{$domain}";
    }

    /**
     * The certificate and key files nginx and the balancer sync read: the
     * uploaded ones for a custom certificate, certbot's live ones otherwise.
     *
     * @return array{0: string, 1: string}
     */
    public static function certificateFiles(stdClass $site): array
    {
        $dir = ($site->cert_source ?? 'letsencrypt') === 'custom' ? self::customCertificateDir($site->domain) : "/etc/letsencrypt/live/{$site->domain}";

        return ["{$dir}/fullchain.pem", "{$dir}/privkey.pem"];
    }

    /**
     * An uploaded certificate on one server, then the vhost switched to it
     * (the vhost steps keep the old config and put it back if nginx -t
     * fails), then the reload. $site must already say cert_source custom.
     * Any files already there are kept aside first so a failed upload can put
     * them back; the key is `sensitive`, so the agent never logs it.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function uploadCertificateRecipe(stdClass $site, #[\SensitiveParameter] string $fullchain, #[\SensitiveParameter] string $key): array
    {
        $dir = self::customCertificateDir($site->domain);
        [$chainPath, $keyPath] = self::certificateFiles($site);
        $backup = self::certificateBackupDir($site->domain);
        $qdir = escapeshellarg($dir);
        $qbackup = escapeshellarg($backup);

        return ['name' => "certificate-upload-{$site->name}", 'steps' => [
            // The backup only counts once the directory is renamed into place: a copy that stopped half way
            // never has the final name, so a restore cannot mistake it for a complete one. When there was no
            // earlier upload the "backup" is an empty directory holding `.absent`, so a restore knows the
            // directory is entirely this upload's.
            ['id' => 'cert-backup', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', "set -e; rm -rf {$qbackup} {$qbackup}.tmp; install -d -m 0700 ".escapeshellarg(dirname($backup))."; if [ -d {$qdir} ]; then cp -a {$qdir} {$qbackup}.tmp; else install -d -m 0700 {$qbackup}.tmp; touch {$qbackup}.tmp/.absent; fi; mv {$qbackup}.tmp {$qbackup}"],
                'description' => 'previous uploaded certificate kept aside'],
            ['id' => 'cert-dir', 'type' => 'dir', 'path' => $dir, 'mode' => '0750'],
            ['id' => 'cert-chain', 'type' => 'file', 'path' => $chainPath, 'mode' => '0644', 'content_b64' => base64_encode($fullchain)],
            ['id' => 'cert-key', 'type' => 'file', 'path' => $keyPath, 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode($key)],
            // The files on the server must be a pair, whatever happened on the way.
            ['id' => 'cert-verify', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'test "$(openssl x509 -in '.escapeshellarg($chainPath).' -noout -pubkey | openssl sha256)" = "$(openssl pkey -in '.escapeshellarg($keyPath).' -pubout | openssl sha256)"'],
                'description' => 'certificate and key on the server match'],
            ...$this->vhostSteps($site),
        ]];
    }

    /**
     * Puts the previously uploaded files back, or removes the directory when
     * there were none. It acts only when the backup step finished (the backup
     * directory exists): if that step never completed, nothing was written
     * yet and the directory is left exactly as it was.
     */
    public function restoreCertificateRecipe(string $domain): array
    {
        $dir = escapeshellarg(self::customCertificateDir($domain));
        $backup = escapeshellarg(self::certificateBackupDir($domain));

        return ['name' => "certificate-restore-{$domain}", 'steps' => [['id' => 'cert-restore', 'type' => 'command', 'name' => 'sh',
            'args' => ['-c', "if [ -d {$backup} ]; then rm -rf {$dir}; if [ -e {$backup}/.absent ]; then rm -rf {$backup}; else mv {$backup} {$dir}; fi; fi; rm -rf {$backup}.tmp"],
            'description' => 'uploaded certificate files put back as they were']]];
    }

    /** Removes the uploaded files and the copy kept aside; used once they are no longer served. */
    public function retireCustomCertificateRecipe(string $domain): array
    {
        return ['name' => "retire-custom-certificate-{$domain}", 'steps' => [
            ['id' => 'cert-files', 'type' => 'absent', 'path' => self::customCertificateDir($domain)],
            ['id' => 'cert-backup', 'type' => 'absent', 'path' => self::certificateBackupDir($domain)],
        ]];
    }

    /** Removes the copy kept aside during an upload, now that the upload is live. */
    public function discardCertificateBackupRecipe(string $domain): array
    {
        return ['name' => "certificate-backup-discard-{$domain}", 'steps' => [['id' => 'cert-backup', 'type' => 'absent', 'path' => self::certificateBackupDir($domain)]]];
    }

    private static function certificateBackupDir(string $domain): string
    {
        return "/var/backups/cloud-manager/certs/{$domain}";
    }

    /**
     * Records that the site serves an uploaded certificate (or Let's Encrypt
     * again). Only after nginx accepted the new files.
     */
    public function setCertificateSource(stdClass $site, string $source, ?\DateTimeInterface $expiresAt): void
    {
        DB::table('sites')->where('id', $site->id)->update(['cert_source' => $source, 'cert_expires_at' => $expiresAt, 'cert_auto_renew' => true, 'updated_at' => now()]);
    }

    /** Just the web server's part of the setup: the config, its check and the reload. */
    public function vhostRecipe(stdClass $site): array
    {
        return ['name' => "web-server-{$site->name}", 'steps' => $this->vhostSteps($site)];
    }

    /** The nginx config for a site, as it is written to its server. */
    public function generatedVhost(stdClass $site): string
    {
        return $this->vhost($site);
    }

    /** What a site's request limits are when its owner has set none. */
    public const DEFAULT_UPLOAD_MB = 64;

    public const DEFAULT_TIMEOUT_S = 60;

    public const JOB_FREQUENCIES = [
        'every_minute' => 'Every minute', 'every_five' => 'Every five minutes', 'every_fifteen' => 'Every fifteen minutes',
        'hourly' => 'Hourly', 'daily' => 'Daily at', 'weekly' => 'Weekly (Sunday) at', 'monthly' => 'Monthly (the 1st) at', 'custom' => 'Custom (cron expression)',
    ];

    /** @return list<array<string, mixed>> */
    public static function scheduledJobs(stdClass $site): array
    {
        $pings = DB::table('heartbeats')->where('site_id', $site->id ?? 0)->whereNotNull('scheduled_job_id')->pluck('last_ping_at', 'scheduled_job_id');

        return DB::table('scheduled_jobs')->where('site_id', $site->id ?? 0)->orderBy('id')->get()
            ->map(fn ($j) => (array) $j + ['next' => self::nextRuns((string) $j->cron, 1)[0] ?? '', 'monitored' => $pings->has($j->id), 'pinged' => $pings[$j->id] ?? null])->all();
    }

    /** The cron expression a frequency comes to. */
    public static function cronFor(string $frequency, string $at, string $custom): string
    {
        if (! isset(self::JOB_FREQUENCIES[$frequency])) {
            throw new InvalidArgumentException('Choose how often the job runs.');
        }
        if ($frequency === 'custom') {
            $custom = trim(preg_replace('/\s+/', ' ', $custom) ?? '');
            if (count(explode(' ', $custom)) !== 5 || ! CronExpression::isValidExpression($custom)) {
                throw new InvalidArgumentException("'{$custom}' is not a cron expression of five fields, such as */15 8-18 * * 1-5.");
            }

            return $custom;
        }
        if (in_array($frequency, ['daily', 'weekly', 'monthly'], true)) {
            if (! preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $at, $m)) {
                throw new InvalidArgumentException('Give the time as HH:MM, in UTC.');
            }
            [$h, $min] = [(int) $m[1], (int) $m[2]];

            return match ($frequency) {
                'daily' => "{$min} {$h} * * *",
                'weekly' => "{$min} {$h} * * 0",
                'monthly' => "{$min} {$h} 1 * *",
            };
        }

        return ['every_minute' => '* * * * *', 'every_five' => '*/5 * * * *', 'every_fifteen' => '*/15 * * * *', 'hourly' => '0 * * * *'][$frequency];
    }

    /** The next times a cron expression is due, in UTC. @return list<string> */
    public static function nextRuns(string $cron, int $count = 5): array
    {
        try {
            return array_map(fn (\DateTimeInterface $d) => $d->format('D j M H:i').' UTC',
                (new CronExpression($cron))->getMultipleRunDates($count, new \DateTimeImmutable('now', new \DateTimeZone('UTC')), false, false, 'UTC'));
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Adds a job, or changes job $id. Refused if its fields are not usable;
     * saved as pending until the operation that writes the cron file has run.
     *
     * @param  array{name: string, command: string, frequency: string, at: string, cron: string, everywhere: bool}  $in
     */
    public function saveJob(stdClass $site, int $id, array $in): int
    {
        \App\Lib\Containers\ContainerApps::refuse($site, 'scheduler');
        $name = trim($in['name']);
        $command = trim($in['command']);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Give the job a name of up to 60 characters.');
        }
        if ($command === '' || mb_strlen($command) > 500 || preg_match('/[\x00-\x1f]/', $command)) {
            throw new InvalidArgumentException('The command must be one line of up to 500 characters.');
        }
        $cron = self::cronFor($in['frequency'], $in['at'], $in['cron']);
        $row = ['name' => $name, 'command' => $command, 'frequency' => $in['frequency'], 'at' => in_array($in['frequency'], ['daily', 'weekly', 'monthly'], true) ? $in['at'] : null,
            'cron' => $cron, 'everywhere' => $in['everywhere'], 'state' => 'pending', 'error' => null, 'updated_at' => now()];

        if ($id > 0) {
            if (! DB::table('scheduled_jobs')->where('id', $id)->where('site_id', $site->id)->update($row)) {
                throw new InvalidArgumentException('That job no longer exists.');
            }

            return $id;
        }

        return (int) DB::table('scheduled_jobs')->insertGetId($row + ['site_id' => $site->id, 'created_at' => now()]);
    }

    public function setJobPaused(stdClass $site, int $id, bool $paused): void
    {
        DB::table('scheduled_jobs')->where('id', $id)->where('site_id', $site->id)->update(['paused' => $paused, 'state' => 'pending', 'error' => null]);
    }

    public function deleteJob(stdClass $site, int $id): void
    {
        DB::table('scheduled_jobs')->where('id', $id)->where('site_id', $site->id)->delete();
    }

    /** The most a setup script may be, in bytes. */
    public const SCRIPT_MAX = 50000;

    /**
     * The application's server setup script: its newest version, or none.
     *
     * @return array{version: int, content: string}
     */
    public static function serverScript(stdClass $site): array
    {
        $row = DB::table('site_scripts')->where('site_id', $site->id ?? 0)->orderByDesc('version')->first();

        return ['version' => (int) ($row->version ?? 0), 'content' => (string) ($row->content ?? '')];
    }

    /**
     * Keeps a new version of the script (an empty one removes it). Saving what
     * is already the newest changes nothing. Returns the version now newest.
     */
    public function saveServerScript(stdClass $site, string $content, string $actor): int
    {
        \App\Lib\Containers\ContainerApps::refuse($site, 'server script');
        if (strlen($content) > self::SCRIPT_MAX + 1000 || str_contains($content, "\0")) {
            throw new InvalidArgumentException('The script is at most '.self::SCRIPT_MAX.' bytes of text.');
        }
        $content = rtrim(str_replace("\r\n", "\n", $content))."\n";
        $content = trim($content) === '' ? '' : $content;
        if (strlen($content) > self::SCRIPT_MAX) {
            throw new InvalidArgumentException('The script is at most '.self::SCRIPT_MAX.' bytes of text.');
        }
        $current = self::serverScript($site);
        if ($content === $current['content']) {
            return $current['version'];
        }
        $version = $current['version'] + 1;
        DB::table('site_scripts')->insert(['site_id' => $site->id, 'version' => $version, 'content' => $content, 'actor' => $actor, 'created_at' => now()]);
        $this->audit->record($site->organization_id, Actor::system($actor), 'site.script_saved', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['version' => $version, 'bytes' => strlen($content)]);

        return $version;
    }

    /** @return list<array{version: int, actor: string, at: string, bytes: int}> newest first */
    public static function serverScriptVersions(stdClass $site, int $limit = 10): array
    {
        return DB::table('site_scripts')->where('site_id', $site->id ?? 0)->orderByDesc('version')->limit($limit)->get()
            ->map(fn ($r) => ['version' => (int) $r->version, 'actor' => (string) $r->actor, 'at' => (string) $r->created_at, 'bytes' => strlen((string) $r->content)])->all();
    }

    /** One saved version's text, or null. */
    public static function serverScriptVersion(stdClass $site, int $version): ?string
    {
        $content = DB::table('site_scripts')->where('site_id', $site->id)->where('version', $version)->value('content');

        return $content === null ? null : (string) $content;
    }

    /** Turns the Laravel scheduler on or off. Takes effect when the operation that writes the cron entry has run. */
    public function setScheduler(stdClass $site, bool $on): stdClass
    {
        if ($on) {
            \App\Lib\Containers\ContainerApps::refuse($site, 'scheduler');
        }
        DB::table('sites')->where('id', $site->id)->update(['scheduler' => $on]);

        return DB::table('sites')->where('id', $site->id)->first();
    }

    /**
     * The tasks the application's code schedules, as last read: command,
     * cron expression, description and when each is next due. Empty until read.
     *
     * @return list<array{command: string, expression: string, description: string, next: string}>
     */
    public static function scheduleTasks(stdClass $site): array
    {
        return json_decode((string) ($site->schedule_tasks ?? '[]'), true) ?: [];
    }

    /**
     * `artisan schedule:list` as tasks. Reads --json when it was given, and
     * the printed table otherwise: "  0 * * * *  php artisan inspire ... Next Due: 1 hour from now".
     *
     * @return list<array{command: string, expression: string, description: string, next: string}>
     */
    public static function parseSchedule(string $output): array
    {
        $json = json_decode(trim($output), true);
        if (is_array($json)) {
            return array_values(array_map(fn ($t) => [
                'command' => (string) ($t['command'] ?? ''),
                'expression' => (string) ($t['expression'] ?? ''),
                'description' => (string) ($t['description'] ?? ''),
                'next' => (string) ($t['next_due_date_human'] ?? $t['next_due_date'] ?? ''),
            ], array_filter($json, 'is_array')));
        }

        $tasks = [];
        foreach (preg_split('/\R/', $output) ?: [] as $line) {
            if (preg_match('/^\s*(\S+\s+\S+\s+\S+\s+\S+\s+\S+)\s+(.+?)\s+\.{2,}\s*Next Due:\s*(.+?)\s*$/', $line, $m)) {
                $tasks[] = ['command' => $m[2], 'expression' => $m[1], 'description' => '', 'next' => $m[3]];
            }
        }

        return $tasks;
    }

    /**
     * A site's web server settings, with the platform's defaults where it has set none.
     *
     * @return array{upload_mb: int, timeout_s: int, snippet: string, custom: array{upload_mb: bool, timeout_s: bool}}
     */
    public function nginx(stdClass $site): array
    {
        return [
            'upload_mb' => (int) ($site->nginx_upload_mb ?? self::DEFAULT_UPLOAD_MB),
            'timeout_s' => (int) ($site->nginx_timeout_s ?? self::DEFAULT_TIMEOUT_S),
            'snippet' => trim((string) ($site->nginx_snippet ?? '')),
            'custom' => ['upload_mb' => ($site->nginx_upload_mb ?? null) !== null, 'timeout_s' => ($site->nginx_timeout_s ?? null) !== null],
        ];
    }

    /** Where nginx reads an application's basic-auth users from. */
    public const AUTH_DIR = '/etc/nginx/cm-auth';

    public static function authFile(stdClass $site): string
    {
        return self::AUTH_DIR."/{$site->name}.htpasswd";
    }

    public static function basicAuthOn(stdClass $site): bool
    {
        return ($site->basic_auth_user ?? null) !== null && ($site->basic_auth_hash ?? null) !== null;
    }

    /**
     * Puts one username and password in front of the whole application. Only a
     * bcrypt hash is kept. Not offered behind a load balancer: its health
     * checks would be refused and every server taken out of rotation.
     */
    public function setBasicAuth(stdClass $site, string $user, #[SensitiveParameter] string $password): stdClass
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,32}$/', $user)) {
            throw new InvalidArgumentException('The username is 1 to 32 letters, digits, dots, dashes or underscores.');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new InvalidArgumentException('The password is 8 to 72 characters.');
        }
        if (($site->load_balancer_id ?? null) !== null) {
            throw new InvalidArgumentException('Password protection is not available behind a load balancer: its health checks would be refused.');
        }
        DB::table('sites')->where('id', $site->id)->update(['basic_auth_user' => $user, 'basic_auth_hash' => password_hash($password, PASSWORD_BCRYPT), 'updated_at' => now()]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.basic_auth_set', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['user' => $user]);

        return DB::table('sites')->find($site->id);
    }

    public function clearBasicAuth(stdClass $site): stdClass
    {
        DB::table('sites')->where('id', $site->id)->update(['basic_auth_user' => null, 'basic_auth_hash' => null, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.basic_auth_removed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}");

        return DB::table('sites')->find($site->id);
    }

    /**
     * Saves a site's web server settings; null takes the default. This only
     * records them: an operation writes them to the servers, checked by nginx.
     */
    public function setNginx(stdClass $site, ?int $uploadMb, ?int $timeoutS, ?string $snippet): stdClass
    {
        if ($uploadMb !== null && ($uploadMb < 1 || $uploadMb > 2048)) {
            throw new InvalidArgumentException('The upload limit is between 1 and 2048 MB.');
        }
        if ($timeoutS !== null && ($timeoutS < 5 || $timeoutS > 3600)) {
            throw new InvalidArgumentException('The request timeout is between 5 and 3600 seconds.');
        }
        $snippet = $snippet === null ? null : trim(str_replace("\r\n", "\n", $snippet));
        if ($snippet !== null && $snippet !== '') {
            self::checkSnippet($snippet);
        }
        DB::table('sites')->where('id', $site->id)->update(['nginx_upload_mb' => $uploadMb, 'nginx_timeout_s' => $timeoutS,
            'nginx_snippet' => $snippet === '' ? null : $snippet, 'updated_at' => now()]);

        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.web_server_changed', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['upload_mb' => $uploadMb, 'timeout_s' => $timeoutS, 'snippet_bytes' => strlen((string) $snippet)]);

        return $this->find($site->node_id, $site->name);
    }

    /**
     * A snippet sits inside the site's server block, so it must stay there and
     * must not take over what the platform owns. nginx checks the rest, and the
     * old config is put back if it refuses.
     */
    public static function checkSnippet(string $snippet): void
    {
        if (strlen($snippet) > 10000) {
            throw new InvalidArgumentException('The snippet is longer than 10,000 characters.');
        }
        // Comments and quoted strings out of the way, then walk the braces.
        $code = preg_replace(['/#[^\n]*/', '/"(?:[^"\\\\]|\\\\.)*"|\'(?:[^\'\\\\]|\\\\.)*\'/'], ['', '""'], $snippet);
        if (preg_match('/(^|[\s;{}])(include|load_module|listen|server_name|ssl_certificate|ssl_certificate_key)\s/i', (string) $code, $m)) {
            throw new InvalidArgumentException("{$m[2]} is managed by Unharbored and cannot be set in a snippet.");
        }
        $depth = 0;
        foreach (str_split((string) $code) as $char) {
            $depth += $char === '{' ? 1 : ($char === '}' ? -1 : 0);
            if ($depth < 0) {
                throw new InvalidArgumentException('The snippet closes the server block. Every } needs a { of its own.');
            }
        }
        if ($depth !== 0) {
            throw new InvalidArgumentException('The snippet has a { that is never closed.');
        }
    }

    /**
     * Issues a Let's Encrypt certificate over HTTP-01 through the shared
     * challenge webroot. Guarded on the certificate existing, so re-running
     * it is a no-op; renewal is certbot's own systemd timer, and the deploy
     * hook reloads nginx when a renewed certificate lands.
     *
     * No account email: Let's Encrypt no longer sends expiry mail, and the
     * renewal timer is what keeps certificates current.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function certificateRecipe(stdClass $site): array
    {
        return ['name' => "certificate-{$site->name}", 'steps' => [[
            'id' => 'certbot',
            'type' => 'command',
            'name' => 'certbot',
            'args' => ['certonly', '--webroot', '-w', ServerStack::ACME_WEBROOT,
                '--cert-name', $site->domain, '-d', $site->domain,
                '--non-interactive', '--agree-tos', '--register-unsafely-without-email',
                '--deploy-hook', 'systemctl reload nginx'],
            'unless' => ['test', '-f', "/etc/letsencrypt/live/{$site->domain}/fullchain.pem"],
            'description' => "certificate for {$site->domain}",
        ]]];
    }

    /**
     * Turns certbot's timer off or on for this one certificate: `autorenew` in
     * its renewal conf, under [renewalparams]. The line is removed and written
     * again, so running it twice changes nothing more. It fails if the conf is
     * missing. An explicit `certbot renew --cert-name X --force-renewal` still
     * renews, since certbot checks the force flag before the setting.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function autoRenewRecipe(string $domain, bool $on): array
    {
        $conf = escapeshellarg("/etc/letsencrypt/renewal/{$domain}.conf");
        $edit = escapeshellarg('/^autorenew[[:space:]]*=/d;/^\[renewalparams\]/a autorenew = '.($on ? 'True' : 'False'));
        $header = escapeshellarg('^\[renewalparams\]');

        return ['name' => "certificate-autorenew-{$domain}", 'steps' => [[
            'id' => 'certbot-autorenew',
            'type' => 'command',
            'name' => 'sh',
            'args' => ['-c', "test -f {$conf} && grep -q {$header} {$conf} && sed -i {$edit} {$conf}"],
            'description' => 'automatic renewal of '.$domain.' '.($on ? 'on' : 'off'),
        ]]];
    }

    /**
     * Clears a certbot lineage for the domain only when it has run out (or is
     * within a day of it), so the issue that follows is real. A lineage that
     * is still valid is kept: certificateRecipe then skips issuing, so a retry
     * after a late failure never spends one of Let's Encrypt's duplicate
     * certificates (5 a week) again.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function clearStaleCertificateRecipe(string $domain): array
    {
        $chain = escapeshellarg("/etc/letsencrypt/live/{$domain}/fullchain.pem");

        return ['name' => "clear-stale-certificate-{$domain}", 'steps' => [[
            'id' => 'certbot-clear-stale',
            'type' => 'command',
            'name' => 'sh',
            'args' => ['-c', "if [ -e {$chain} ] && ! openssl x509 -checkend 86400 -noout -in {$chain} >/dev/null 2>&1; then certbot delete --cert-name ".escapeshellarg($domain).' --non-interactive; fi'],
            'description' => "expired certificate for {$domain} cleared",
        ]]];
    }

    /**
     * Marks TLS on and moves APP_URL to https, so generated links and
     * redirects stop pointing at the redirect.
     */
    /**
     * How the certificate's end reads on the site page: "Expires 12 Feb (in 61 days)", amber under 21 days, red under 7.
     *
     * @return array{label: string, class: string, days: ?int}
     */
    public static function certificateStatus(?string $expiresAt): array
    {
        if ($expiresAt === null) {
            return ['label' => 'unknown', 'class' => 'faint', 'days' => null];
        }
        $at = Carbon::parse($expiresAt);
        $days = (int) floor(($at->getTimestamp() - now()->getTimestamp()) / 86400);
        $date = $at->utc()->format('j M');
        if ($days < 0) {
            $ago = abs($days);

            return ['label' => "Expired {$date} ({$ago} ".($ago === 1 ? 'day' : 'days').' ago)', 'class' => 'bad', 'days' => $days];
        }

        return ['label' => "Expires {$date} (".($days === 0 ? 'today' : "in {$days} ".($days === 1 ? 'day' : 'days')).')',
            'class' => $days < 7 ? 'bad' : ($days < 21 ? 'warn' : 'ok'), 'days' => $days];
    }

    public function markTls(stdClass $site): stdClass
    {
        DB::table('sites')->where('id', $site->id)->update(['tls' => true, 'updated_at' => now()]);

        // The user's own variables only: writing the merged text here would copy everything Unharbored injects into them, where it
        // would win over every later change to what is injected (a database attached afterwards would never take effect).
        $env = $this->customEnv($site);
        $updated = preg_replace('#^APP_URL=http://#m', 'APP_URL=https://', $env);
        if ($updated !== null && $updated !== $env) {
            $this->setEnv($site, $updated);
        }
        $url = $this->injected($site)['APP_URL']['value'] ?? null;
        if ($url !== null && str_starts_with($url, 'http://')) {
            $this->setInjected($site, ['APP_URL' => 'https://'.substr($url, 7)], self::GENERATED_SOURCE);
        }

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.tls_enabled',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['domain' => $site->domain],
        );

        return $this->find($site->node_id, $site->name);
    }

    /**
     * Points the site at a new domain. TLS goes off until a certificate for
     * the new name exists, and every occurrence of the old domain in .env
     * (APP_URL, socket URLs and the like) moves with it.
     */
    public function changeDomain(stdClass $site, string $domain): stdClass
    {
        self::validate($site->name, $domain, $site->repository, $site->branch);

        $old = $site->domain;
        DB::table('sites')->where('id', $site->id)->update(['domain' => $domain, 'tls' => false, 'cert_source' => 'letsencrypt', 'cert_expires_at' => null, 'cert_auto_renew' => true, 'updated_at' => now()]);

        // The user's own variables only, for the reason given in markTls; and the address in APP_URL wherever APP_URL lives.
        $env = str_replace($old, $domain, $this->customEnv($site));
        $env = (string) preg_replace('#^APP_URL=https?://.*$#m', "APP_URL=http://{$domain}", $env);
        $this->setEnv($site, $env);
        if (isset($this->injected($site)['APP_URL'])) {
            $this->setInjected($site, ['APP_URL' => "http://{$domain}"], self::GENERATED_SOURCE);
        }

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.domain_changed',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['from' => $old, 'to' => $domain],
        );

        return $this->find($site->node_id, $site->name);
    }

    /**
     * Removes a certificate lineage no vhost serves any more, so certbot's
     * timer stops trying, and failing, to renew it.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function retireCertificateRecipe(string $domain): array
    {
        return ['name' => "retire-certificate-{$domain}", 'steps' => [[
            'id' => 'certbot-delete',
            'type' => 'command',
            'name' => 'certbot',
            'args' => ['delete', '--cert-name', $domain, '--non-interactive'],
            'unless' => ['test', '!', '-e', "/etc/letsencrypt/renewal/{$domain}.conf"],
            'description' => "certificate for {$domain} removed",
        ]]];
    }

    /** What a PHP application's build commands are until it writes its own: Composer, as it was always run. */
    public const DEFAULT_BUILD_SCRIPT = '$COMPOSER install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress';

    /**
     * The application's own build commands, or null while it uses the default.
     *
     * @return array{script: string, node: string}|null
     */
    public static function buildScript(stdClass $site): ?array
    {
        $script = trim((string) ($site->build_script ?? ''));

        return $script === '' ? null : ['script' => $script, 'node' => (string) ($site->node_version ?? '')];
    }

    /**
     * Sets the commands run in each new release in place of the default (empty, or the default text itself, goes back to
     * the default) and the Node version they run under (empty: installed only when the commands use it).
     *
     * @throws InvalidArgumentException
     */
    public function setBuildScript(stdClass $site, string $script, string $node): void
    {
        $script = trim(str_replace(["\r\n", "\r"], "\n", $script));
        $node = strtolower(trim($node));
        if (mb_strlen($script) > 5000) {
            throw new InvalidArgumentException('That is too long; put the steps in a script file in your repository and call it from here.');
        }
        if (preg_match('/[\x00-\x08\x0b-\x1f]/', $script)) {
            throw new InvalidArgumentException('That has characters that are not text.');
        }
        if ($node !== '' && ! Runtimes::validNodeVersion($node)) {
            throw new InvalidArgumentException('The Node version is a number such as 22 or 20.11, or lts; empty installs it only when the commands use it.');
        }
        $default = $script === '' || $script === self::DEFAULT_BUILD_SCRIPT;
        DB::table('sites')->where('id', $site->id)->update(['build_script' => $default ? null : $script, 'node_version' => $default || $node === '' ? null : $node,
            'pending_since' => DB::raw('COALESCE(pending_since, CURRENT_TIMESTAMP)'), 'updated_at' => now()]);
        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.build_script_changed',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['default' => $default, 'lines' => $default ? 0 : substr_count($script, "\n") + 1, 'node' => $node],
        );
    }

    /** Switches the automatic database backups (nightly, and before each deploy's migrations) on or off. A manual backup is always possible. */
    public function setBackups(stdClass $site, bool $on): void
    {
        DB::table('sites')->where('id', $site->id)->update(['backups' => $on, 'updated_at' => now()]);
        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.backups_changed',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['on' => $on],
        );
    }

    /** The kinds of entry Composer reads from an auth.json: each maps a host to its credential. */
    public const COMPOSER_AUTH_TYPES = ['http-basic', 'bearer', 'github-oauth', 'gitlab-oauth', 'gitlab-token', 'gitlab-basic', 'bitbucket-oauth', 'forgejo-token', 'custom-headers'];

    /** The folder Composer is pointed at while it installs, so the credentials stay on this application's own side. */
    public static function composerHome(stdClass $site): string
    {
        return self::root($site).'/composer';
    }

    /** Whether the application has Composer credentials of its own. */
    public function hasOwnComposerAuth(stdClass $site): bool
    {
        return $this->vault->has($site->organization_id, self::composerAuthSecret(self::home($site), $site->name));
    }

    /** What the application's deploy gets: the team's credentials, its project's over them, and its own over those, host by host. */
    public function composerAuth(stdClass $site): ?string
    {
        $own = json_decode((string) $this->vault->get($site->organization_id, self::composerAuthSecret(self::home($site), $site->name)), true);
        $merged = array_replace_recursive(
            $this->sharedComposerAuth($site->organization_id, null),
            $this->sharedComposerAuth($site->organization_id, (int) ($site->project_id ?? 0) ?: null),
            is_array($own) ? $own : [],
        );

        return $merged === [] ? null : json_encode($merged, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    public function hasComposerAuth(stdClass $site): bool
    {
        return $this->composerAuth($site) !== null;
    }

    /** The vault entry holding Composer credentials shared by a whole team (no project) or by one project's applications: an auth.json. */
    private static function sharedComposerSecret(?int $projectId): string
    {
        return $projectId === null ? 'composer:team' : "composer:project:{$projectId}";
    }

    /** @return array<string, array<string, mixed>> the shared auth.json */
    private function sharedComposerAuth(string $organizationId, ?int $projectId): array
    {
        $auth = json_decode((string) $this->vault->get($organizationId, self::sharedComposerSecret($projectId)), true);

        return is_array($auth) ? $auth : [];
    }

    /**
     * The hosts a team (or one of its projects) has credentials for, with the username and never the password.
     *
     * @return list<array{host: string, username: string}>
     */
    public function sharedComposerHosts(string $organizationId, ?int $projectId): array
    {
        $hosts = [];
        foreach ((array) ($this->sharedComposerAuth($organizationId, $projectId)['http-basic'] ?? []) as $host => $login) {
            $hosts[] = ['host' => (string) $host, 'username' => (string) ($login['username'] ?? '')];
        }

        return $hosts;
    }

    /**
     * Keeps a username and password for a package server (Laravel Nova, Spark, Filament, Flux Pro) for every application
     * of the team, or of one project. Saving a host again replaces it. The applications get it with their next deploy.
     * The caller has checked that the project is the organisation's.
     *
     * @throws InvalidArgumentException when the host, username or password is not usable
     */
    public function setSharedComposerCredential(string $organizationId, ?int $projectId, string $host, string $username, #[SensitiveParameter] string $password, Actor $actor): void
    {
        $host = strtolower(trim($host));
        if (! preg_match('/^[a-z0-9]([a-z0-9.-]{0,251}[a-z0-9])?$/', $host)) {
            throw new InvalidArgumentException('The host is a name such as nova.laravel.com, without https:// or a path.');
        }
        if (trim($username) === '' || $password === '' || strlen($username) > 300 || strlen($password) > 300) {
            throw new InvalidArgumentException('Give both the username and the password (the licence key, for most package servers).');
        }
        $auth = $this->sharedComposerAuth($organizationId, $projectId);
        $auth['http-basic'][$host] = ['username' => trim($username), 'password' => $password];
        $this->vault->put($organizationId, self::sharedComposerSecret($projectId), json_encode($auth, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->audit->record(organizationId: $organizationId, actor: $actor, action: 'composer.shared_credential_set', resourceType: 'composer', resourceId: $host, metadata: ['project' => $projectId]);
    }

    /** Forgets the shared credentials for one host; applications stop getting it with their next deploy. */
    public function removeSharedComposerHost(string $organizationId, ?int $projectId, string $host, Actor $actor): void
    {
        $auth = $this->sharedComposerAuth($organizationId, $projectId);
        unset($auth['http-basic'][$host]);
        if (($auth['http-basic'] ?? null) === []) {
            unset($auth['http-basic']);
        }
        $auth === [] ? $this->vault->forget($organizationId, self::sharedComposerSecret($projectId))
            : $this->vault->put($organizationId, self::sharedComposerSecret($projectId), json_encode($auth, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $this->audit->record(organizationId: $organizationId, actor: $actor, action: 'composer.shared_credential_removed', resourceType: 'composer', resourceId: $host, metadata: ['project' => $projectId]);
    }

    /**
     * What is set, without the secrets: each type with the hosts it is for, to show once the credentials are saved.
     *
     * @return list<string>
     */
    public function composerAuthSummary(stdClass $site): array
    {
        $auth = json_decode((string) $this->vault->get($site->organization_id, self::composerAuthSecret(self::home($site), $site->name)), true);

        return is_array($auth) ? array_map(fn ($type) => $type.': '.implode(', ', array_keys((array) $auth[$type])), array_keys($auth)) : [];
    }

    /**
     * Keeps the application's Composer credentials (the text of an auth.json), or forgets them when given nothing.
     * They reach the servers on the next deploy.
     *
     * @throws InvalidArgumentException when it is not an auth.json
     */
    public function setComposerAuth(stdClass $site, ?string $json): void
    {
        $json = trim((string) $json);
        $name = self::composerAuthSecret(self::home($site), $site->name);
        if ($json === '') {
            $this->vault->forget($site->organization_id, $name);
        } else {
            $auth = json_decode($json, true);
            if (! is_array($auth) || $auth === [] || array_is_list($auth)) {
                throw new InvalidArgumentException('That is not an auth.json: it is a JSON object such as {"github-oauth": {"github.com": "…"}}.');
            }
            foreach ($auth as $type => $hosts) {
                if (! in_array($type, self::COMPOSER_AUTH_TYPES, true) || ! is_array($hosts) || $hosts === [] || array_is_list($hosts)) {
                    throw new InvalidArgumentException('"'.$type.'" is not something Composer reads here. Each entry is a type (one of '.implode(', ', self::COMPOSER_AUTH_TYPES).') holding the hosts it is for.');
                }
            }
            $this->vault->put($site->organization_id, $name, json_encode($auth, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        }
        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.composer_auth_changed',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['set' => $json !== ''],
        );
    }

    /** Which branch of the repository the application deploys. The caller has checked that it exists. */
    public function setBranch(stdClass $site, string $branch): stdClass
    {
        self::validate($site->name, $site->domain, $site->repository, $branch);
        DB::table('sites')->where('id', $site->id)->update(['branch' => $branch, 'updated_at' => now()]);
        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.branch_changed',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['from' => $site->branch, 'to' => $branch],
        );

        return $this->find($site->node_id, $site->name);
    }

    /**
     * How the node fetches code, and whether a push deploys.
     */
    public function setGitSource(stdClass $site, string $source, bool $pushToDeploy): stdClass
    {
        if (! in_array($source, ['deploy_key', 'github_app', 'https'], true)) {
            throw new InvalidArgumentException("Unknown git source {$source}.");
        }

        DB::table('sites')->where('id', $site->id)->update([
            'git_source' => $source,
            'push_to_deploy' => $pushToDeploy,
            'updated_at' => now(),
        ]);

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.git_source_changed',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['source' => $source, 'push_to_deploy' => $pushToDeploy],
        );

        return $this->find($site->node_id, $site->name);
    }

    /**
     * Replaces or appends KEY=value lines in the user's custom variables,
     * leaving every other line as it was. For values the user chose (given when
     * an application is created); what Unharbored derives from resources goes
     * through setInjected instead.
     *
     * @param  array<string, string>  $values
     */
    public function setEnvValues(stdClass $site, array $values): void
    {
        $env = $this->customEnv($site);

        foreach ($values as $key => $value) {
            $line = "{$key}={$value}";
            $pattern = '/^'.preg_quote($key, '/').'=.*$/m';
            $env = preg_match($pattern, $env) === 1
                ? (string) preg_replace($pattern, str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $env)
                : rtrim($env, "\n")."\n{$line}\n";
        }

        $this->setEnv($site, $env, 'set '.implode(', ', array_keys($values)));
    }

    /**
     * Keeps the new contents as a version of their own. The first time a
     * site with no history changes, what it had is kept first, as version 1.
     */
    private function recordEnvVersion(stdClass $site, #[SensitiveParameter] string $previous, #[SensitiveParameter] string $contents, string $reason): void
    {
        $latest = (int) DB::table('site_env_versions')->where('site_id', $site->id)->max('version');
        if ($previous !== '' && ! DB::table('site_env_versions')->where('site_id', $site->id)->where('layer', 'custom')->exists()) {
            $this->storeEnvVersion($site, $latest + 1, $previous, 'as it was before history was kept', []);
            $latest++;
        }
        $this->storeEnvVersion($site, $latest + 1, $contents, mb_substr($reason, 0, 250), self::envChanges($previous, $contents));
        $this->pruneEnvVersions($site);
    }

    /** Keeps the newest versions of both layers; the snapshots of older custom ones are deleted with them. */
    private function pruneEnvVersions(stdClass $site): void
    {
        foreach (DB::table('site_env_versions')->where('site_id', $site->id)->orderByDesc('version')->skip(self::ENV_VERSIONS_KEPT)->take(1000)->get() as $old) {
            $this->vault->forget($site->organization_id, self::envVersionSecret($site, (int) $old->version));
            DB::table('site_env_versions')->where('id', $old->id)->delete();
        }
    }

    /** @param  array<string, list<string>>  $changes */
    private function storeEnvVersion(stdClass $site, int $version, #[SensitiveParameter] string $contents, string $reason, array $changes): void
    {
        $this->vault->put($site->organization_id, self::envVersionSecret($site, $version), $contents);
        DB::table('site_env_versions')->insert(['site_id' => $site->id, 'version' => $version, 'layer' => 'custom', 'reason' => $reason,
            'changes' => json_encode($changes), 'created_at' => now()]);
    }

    /**
     * Which keys were added, removed or changed: names only, since the
     * values are secrets.
     *
     * @return array{added: list<string>, removed: list<string>, changed: list<string>}
     */
    public static function envChanges(#[SensitiveParameter] string $before, #[SensitiveParameter] string $after): array
    {
        $parse = function (string $env): array {
            preg_match_all('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/m', $env, $m, PREG_SET_ORDER);

            return array_column($m, 2, 1);
        };
        $a = $parse($before);
        $b = $parse($after);

        return [
            'added' => array_values(array_keys(array_diff_key($b, $a))),
            'removed' => array_values(array_keys(array_diff_key($a, $b))),
            'changed' => array_values(array_keys(array_filter(array_intersect_key($b, $a), fn ($v, $k) => $a[$k] !== $v, ARRAY_FILTER_USE_BOTH))),
        ];
    }

    /**
     * The site's saved versions, newest first.
     *
     * @return list<array{version: int, layer: string, reason: string, changes: array<string, list<string>>, at: string}>
     */
    public function envVersions(stdClass $site): array
    {
        return DB::table('site_env_versions')->where('site_id', $site->id)->orderByDesc('version')->get()
            ->map(fn ($v) => ['version' => (int) $v->version, 'layer' => (string) ($v->layer ?? 'custom'), 'reason' => $v->reason,
                'changes' => json_decode((string) $v->changes, true) ?: [], 'at' => (string) $v->created_at])->all();
    }

    /**
     * Puts an earlier version of the custom variables back; that becomes the
     * newest version, so it can be undone too. An injected version has nothing
     * to restore: Unharbored rewrites those values from the resources.
     */
    public function restoreEnvVersion(stdClass $site, int $version): void
    {
        $contents = DB::table('site_env_versions')->where('site_id', $site->id)->where('version', $version)->where('layer', 'custom')->exists()
            ? $this->vault->get($site->organization_id, self::envVersionSecret($site, $version))
            : null;
        if ($contents === null) {
            throw new InvalidArgumentException("There is no version {$version} of {$site->name}'s .env.");
        }
        $this->setEnv($site, $contents, "restored version {$version}");
    }

    private static function envVersionSecret(stdClass $site, int $version): string
    {
        return self::envSecret(self::home($site), $site->name).":v{$version}";
    }

    /**
     * @return list<array{name: string, command: string, processes: int, port?: ?int, path?: ?string}>
     */
    public static function daemons(stdClass $site): array
    {
        return json_decode((string) ($site->daemons ?? '[]'), true) ?: [];
    }

    /** Ports a background process may not take: the ones the platform and the usual services use. */
    private const RESERVED_PORTS = [1433, 2049, 3306, 5432, 5672, 6379, 9000, 9200, 9443, 11211, 27017];

    /** Paths on the site's own domain that belong to something else. */
    private const RESERVED_PATHS = ['app', 'apps', '.well-known', 'index.php', 'up', 'storage', 'build'];

    /**
     * Adds or replaces a background process. The command runs as the deploy
     * user from the live release; a leading "php " means the platform's PHP.
     *
     * With a port the process is given PORT and "{port}" in its command, and
     * with a path as well, nginx passes that path on the site's own domain to
     * it, WebSockets included, so a browser reaches it over the site's
     * certificate and the port itself stays on loopback.
     */
    public function setDaemon(stdClass $site, string $name, string $command, int $processes = 1, ?int $port = null, ?string $path = null): stdClass
    {
        \App\Lib\Containers\ContainerApps::refuse($site, 'background processes');
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $name) || in_array($name, ['worker', 'reverb'], true)) {
            throw new InvalidArgumentException('Daemon name must be lowercase letters, digits and dashes, and not "worker" or "reverb".');
        }
        // The command lands in a supervisor ini file. A newline would let it
        // add directives of its own, such as running as root.
        if (trim($command) === '' || strlen($command) > 500 || preg_match('/[\r\n\0]/', $command)) {
            throw new InvalidArgumentException('Daemon command must be a single line of at most 500 characters.');
        }
        if ($processes < 1 || $processes > 20) {
            throw new InvalidArgumentException('Processes must be between 1 and 20.');
        }
        $path = $path !== null ? '/'.trim(trim($path), '/') : null;
        if ($path === '/') {
            $path = null;
        }
        if ($path !== null && $port === null) {
            throw new InvalidArgumentException('A public path needs the port the process listens on.');
        }
        if ($port !== null) {
            $this->checkPort($site, $name, $port);
            if ($processes !== 1) {
                throw new InvalidArgumentException('A process that listens on a port runs once: a second would find it taken.');
            }
        }
        if ($path !== null) {
            $this->checkPath($site, $name, $path);
        }
        if (str_contains($command, '{port}') && $port === null) {
            throw new InvalidArgumentException('The command uses {port}, so give the port.');
        }

        $daemons = array_values(array_filter(self::daemons($site), fn ($d) => $d['name'] !== $name));
        $daemons[] = ['name' => $name, 'command' => trim($command), 'processes' => $processes] + ($port !== null ? ['port' => $port, 'path' => $path] : []);

        return $this->saveDaemons($site, $daemons, 'site.daemon_set', ['daemon' => $name, 'command' => trim($command), 'processes' => $processes, 'port' => $port, 'path' => $path]);
    }

    /** A port on loopback that nothing else on the server uses: not a service's, not the platform's band, not another application's. */
    private function checkPort(stdClass $site, string $name, int $port): void
    {
        if ($port < 1024 || $port > 65535 || in_array($port, self::RESERVED_PORTS, true) || Runtimes::reservedPort($port)) {
            throw new InvalidArgumentException('Pick a port from 1024 to 65535, not one a service uses and not 20000 to 23999, which Unharbored keeps for itself.');
        }
        $home = self::home($site);
        foreach (DB::table('sites')->where('node_id', $home)->get(['id', 'name', 'daemons']) as $other) {
            foreach (self::daemons($other) as $d) {
                if (($d['port'] ?? null) === $port && ! ((int) $other->id === (int) $site->id && $d['name'] === $name)) {
                    throw new InvalidArgumentException("Port {$port} is already used by {$other->name}'s {$d['name']} on this server.");
                }
            }
        }
    }

    /** The same site with its runtime settings forgotten, so what is set next replaces them rather than adding to them. */
    public function clearRuntime(stdClass $site): stdClass
    {
        $site = clone $site;
        $site->runtime_config = null;

        return $site;
    }

    /**
     * Sets what the application is and how it builds and starts. Changes are checked and merged into
     * what is stored; the next deploy builds as set, and the web server is rewritten by the caller.
     *
     * @param  array<string, mixed>  $changes
     */
    public function setRuntime(stdClass $site, string $runtime, array $changes = []): stdClass
    {
        \App\Lib\Containers\ContainerApps::refuse($site, 'runtime');
        if ($runtime !== Runtimes::PHP && ! isset(Runtimes::PRESETS[$runtime])) {
            throw new InvalidArgumentException('Pick Laravel, Node, Go or a static site.');
        }
        $kept = $runtime === Runtimes::runtime($site) ? (json_decode((string) ($site->runtime_config ?? 'null'), true) ?: []) : [];
        $config = $runtime === Runtimes::PHP ? [] : array_replace($kept, Runtimes::validate($runtime, $changes));
        if (isset($config['port'])) {
            $this->checkPort($site, '', (int) $config['port']);
        }

        // A new application that is not Laravel has none of Laravel's defaults injected: no APP_KEY, no SQLite.
        if ($runtime !== Runtimes::PHP && Runtimes::has($site, 'framework-defaults') && $site->current_release === null) {
            $this->vault->put($site->organization_id, self::injectedSecret($site->node_id, $site->name), '{}');
        }

        DB::table('sites')->where('id', $site->id)->update([
            'runtime' => $runtime,
            'runtime_config' => $config === [] ? null : json_encode($config),
            'updated_at' => now(),
        ]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.runtime_changed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['runtime' => $runtime]);

        return DB::table('sites')->where('id', $site->id)->first();
    }

    /**
     * Moves the application to another folder of its repository. Nothing on the server changes until
     * the next deploy, which builds there, links the shared files there and rewrites the web server.
     *
     * @throws InvalidArgumentException when it is not a folder inside the repository
     */
    public function setRootDir(stdClass $site, string $dir): stdClass
    {
        $dir = self::cleanRootDir($dir);
        if ($dir === self::rootDir($site)) {
            return $site;
        }
        DB::table('sites')->where('id', $site->id)->update(['root_dir' => $dir === '' ? null : $dir, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.root_dir_changed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['root_dir' => $dir]);

        return DB::table('sites')->where('id', $site->id)->first();
    }

    /** A path on the site's domain that is the process's alone: one to three plain segments, nothing the app or platform serves. */
    private function checkPath(stdClass $site, string $name, string $path): void
    {
        if (! preg_match('#^(/[a-z0-9][a-z0-9_-]{0,40}){1,3}$#', $path) || in_array(explode('/', $path)[1], self::RESERVED_PATHS, true)) {
            throw new InvalidArgumentException('The path is one to three lowercase segments such as /game-ws, and not app, apps, up, storage or build.');
        }
        foreach (self::daemons($site) as $d) {
            if (($d['path'] ?? null) === $path && $d['name'] !== $name) {
                throw new InvalidArgumentException("{$path} already goes to {$d['name']}.");
            }
        }
    }

    public function removeDaemon(stdClass $site, string $name): stdClass
    {
        $daemons = array_values(array_filter(self::daemons($site), fn ($d) => $d['name'] !== $name));

        return $this->saveDaemons($site, $daemons, 'site.daemon_removed', ['daemon' => $name]);
    }

    /**
     * Turns Reverb on: credentials and server settings in .env, broadcasting
     * through it, and a flag the vhost and supervisor configuration follow.
     * Existing credentials are kept, so re-running never breaks clients.
     */
    public function enableReverb(stdClass $site): stdClass
    {
        \App\Lib\Containers\ContainerApps::refuse($site, 'reverb');
        $env = $this->env($site);
        $existing = static fn (string $key): ?string => preg_match('/^'.$key.'=(.+)$/m', $env, $m) ? trim($m[1]) : null;
        $port = (string) self::reverbPort($site);

        $this->setInjected($site, [
            'BROADCAST_CONNECTION' => 'reverb',
            'REVERB_APP_ID' => $existing('REVERB_APP_ID') ?? (string) random_int(100000, 999999),
            'REVERB_APP_KEY' => $existing('REVERB_APP_KEY') ?? bin2hex(random_bytes(10)),
            'REVERB_APP_SECRET' => $existing('REVERB_APP_SECRET') ?? bin2hex(random_bytes(20)),
            // Laravel publishes to Reverb over loopback; browsers reach it
            // through nginx on the site's own domain and certificate.
            'REVERB_HOST' => '127.0.0.1',
            'REVERB_PORT' => $port,
            'REVERB_SCHEME' => 'http',
            'REVERB_SERVER_HOST' => '127.0.0.1',
            'REVERB_SERVER_PORT' => $port,
        ], 'Reverb');

        DB::table('sites')->where('id', $site->id)->update(['reverb' => true, 'updated_at' => now()]);
        $this->markPending($site);

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.reverb_enabled',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['port' => (int) $port],
        );

        return $this->find($site->node_id, $site->name);
    }

    public function disableReverb(stdClass $site): stdClass
    {
        $this->setInjected($site, ['BROADCAST_CONNECTION' => 'log'], 'Reverb');
        DB::table('sites')->where('id', $site->id)->update(['reverb' => false, 'updated_at' => now()]);
        $this->markPending($site);

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.reverb_disabled',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
        );

        return $this->find($site->node_id, $site->name);
    }

    /**
     * Each site's Reverb listens on its own loopback port, derived from the
     * site id so two sites on one node can never collide.
     */
    /**
     * Where the load balancer checks /up. Its health checks carry no site
     * name, and port 80 refuses requests without one, so each balanced site
     * also answers on a port of its own.
     */
    public static function healthPort(stdClass $site): int
    {
        return 21000 + (int) $site->id;
    }

    public static function reverbPort(stdClass $site): int
    {
        return 20000 + (int) $site->id;
    }

    public function setWorkers(stdClass $site, int $workers): stdClass
    {
        if ($workers > 0) {
            \App\Lib\Containers\ContainerApps::refuse($site, 'queue workers');
        }
        if ($workers < 0 || $workers > 20) {
            throw new InvalidArgumentException('Workers must be between 0 and 20.');
        }

        DB::table('sites')->where('id', $site->id)->update(['queue_workers' => $workers, 'updated_at' => now()]);
        $this->markPending($site);

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: 'site.workers_set',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['workers' => $workers],
        );

        return $this->find($site->node_id, $site->name);
    }

    /** Saved, but only a deploy puts it on the node. */
    public function markPending(stdClass $site): void
    {
        DB::table('sites')->where('id', $site->id)->whereNull('pending_since')->update(['pending_since' => now()]);
    }

    /**
     * @param  list<array<string, mixed>>  $daemons
     * @param  array<string, mixed>  $metadata
     */
    private function saveDaemons(stdClass $site, array $daemons, string $action, array $metadata): stdClass
    {
        DB::table('sites')->where('id', $site->id)->update([
            'daemons' => $daemons === [] ? null : json_encode($daemons),
            'updated_at' => now(),
        ]);
        $this->markPending($site);

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: Actor::system('control-plane'),
            action: $action,
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: $metadata,
        );

        return $this->find($site->node_id, $site->name);
    }

    /** When the site last moved onto its managed database, from the audit log; null if never. */
    public static function attachedAt(stdClass $site): ?Carbon
    {
        $at = DB::table('audit_records')->where('organization_id', $site->organization_id)->where('action', 'site.database_attached')
            ->where('resource_id', "{$site->node_id}/{$site->name}")->max('recorded_at');

        return $at !== null ? Carbon::parse($at) : null;
    }

    /**
     * Every server the site runs on, primary first.
     *
     * @return list<string>
     */
    public static function nodes(stdClass $site): array
    {
        return array_values(array_unique([$site->node_id, ...DB::table('site_nodes')->where('site_id', $site->id)->orderBy('id')->pluck('node_id')->all()]));
    }

    /** The same site, pointed at one of its servers. */
    public static function on(stdClass $site, string $nodeId): stdClass
    {
        $copy = clone $site;
        $copy->home_node_id = self::home($site);
        $copy->node_id = $nodeId;

        return $copy;
    }

    /**
     * Takes a site off a server it no longer runs on: nginx stops serving it
     * and its processes and schedule stop, then its directory there is
     * deleted. Only used for a server that is not the site's primary.
     *
     * With $keepData the directory is left in place: the application is no
     * longer served, but its releases, uploads and SQLite file survive for
     * whoever restores or removes them later.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function removalRecipe(stdClass $site, bool $keepData = false): array
    {
        $steps = [
            ['id' => 'vhost', 'type' => 'absent', 'path' => "/etc/nginx/sites-enabled/{$site->name}.conf"],
            ['id' => 'auth-file', 'type' => 'absent', 'path' => self::authFile($site)],
            ['id' => 'reload-nginx', 'type' => 'service', 'name' => 'nginx', 'state' => 'reloaded'],
            // An uploaded private key, and the copy kept aside during an upload, do not outlive the site that used them.
            ...$this->retireCustomCertificateRecipe($site->domain)['steps'],
            ['id' => 'supervisor-conf', 'type' => 'absent', 'path' => "/etc/supervisor/conf.d/cm-{$site->name}.conf"],
            ['id' => 'supervisor-update', 'type' => 'command', 'name' => 'supervisorctl', 'args' => ['update'], 'description' => 'supervisor stops the site\'s programs'],
            ['id' => 'scheduler', 'type' => 'absent', 'path' => "/etc/cron.d/cm-{$site->name}"],
        ];

        if (Runtimes::isContainer($site)) {
            array_push($steps, ...\App\Lib\Containers\AppRecipes::removal($site->name, $keepData, \App\Lib\Containers\ContainerApps::imageIds($site)));
        }

        if (! $keepData) {
            // Releases, and shared/ with it: anything uploaded to this server's
            // local disk was only ever seen by requests that landed here.
            $steps[] = ['id' => 'files', 'type' => 'absent', 'path' => self::root($site)];
        }

        return ['name' => "site-remove-{$site->name}", 'steps' => $steps];
    }

    /** The site's primary server, which its secrets are filed under, whichever server a copy points at. */
    private static function home(stdClass $site): string
    {
        return $site->home_node_id ?? $site->node_id;
    }

    /** The PHP branch the site runs on, falling back to the platform default. */
    public static function phpVersion(stdClass $site): string
    {
        $version = (string) ($site->php_version ?? '');

        return ServerStack::supportsPhp($version) ? $version : ServerStack::PHP_VERSION;
    }

    /** Where supervisor writes a site's process logs: owned by root, with nothing the application can write above it. */
    public const SUPERVISOR_LOG_ROOT = '/var/log/cloud-manager';

    public static function supervisorLogDir(stdClass $site): string
    {
        return self::SUPERVISOR_LOG_ROOT."/{$site->name}";
    }

    public static function root(stdClass $site): string
    {
        return '/home/'.ServerStack::DEPLOY_USER."/{$site->name}";
    }

    /**
     * A folder inside the repository, checked: relative, no "..", plain
     * characters. Empty for the top of the repository.
     *
     * @throws InvalidArgumentException when it is not a folder inside the repository
     */
    public static function cleanRootDir(string $dir): string
    {
        $dir = trim(trim($dir), '/');
        if ($dir === '' || $dir === '.') {
            return '';
        }
        if (! preg_match('#^[A-Za-z0-9._@+-]+(/[A-Za-z0-9._@+-]+)*$#', $dir) || in_array('..', explode('/', $dir), true) || in_array('.', explode('/', $dir), true)) {
            throw new InvalidArgumentException('The root folder is a path inside the repository, like apps/web: letters, numbers and . _ - @ + only, no .. and no leading slash.');
        }

        return $dir;
    }

    /** The application's folder inside the repository, or empty for the top. */
    public static function rootDir(stdClass $site): string
    {
        return trim((string) ($site->root_dir ?? ''), '/');
    }

    /** A path inside a release (or current) moved into the application's folder: the same path when it is at the top. */
    public static function appDir(stdClass $site, string $base): string
    {
        $dir = self::rootDir($site);

        return $dir === '' ? $base : rtrim($base, '/').'/'.$dir;
    }

    /** Where the application runs from: the live release, inside its folder when it has one. */
    public static function current(stdClass $site): string
    {
        return self::appDir($site, self::root($site).'/current');
    }

    public static function keyPath(stdClass $site): string
    {
        return '/home/'.ServerStack::DEPLOY_USER."/.ssh/{$site->name}_deploy_key";
    }

    /**
     * Every value here reaches a shell-free argv or a file path on a root
     * agent, so the formats are strict. A branch beginning with "-" would be
     * read by git as an option.
     */
    public static function validate(string $name, string $domain, ?string $repository, string $branch): void
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,39}$/', $name)) {
            throw new InvalidArgumentException('Site name must be lowercase letters, digits and dashes, up to 40 characters.');
        }
        if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
            throw new InvalidArgumentException("Not a valid domain: {$domain}");
        }
        if ($repository === null) {
            return; // an image application: no repository, no branch to check
        }
        try {
            $canonical = GitRepo::parse($repository);
        } catch (InvalidArgumentException) {
            $canonical = null;
        }
        // Stored exactly as git takes it: the SSH form, or an https:// address, ending .git.
        if ($canonical === null || ! in_array($repository, [$canonical->sshUrl(), $canonical->httpsUrl()], true) || (! $canonical->viaSsh && ! str_starts_with($repository, 'https://'))) {
            throw new InvalidArgumentException('Repository must be an SSH address like git@host:owner/repo.git, or an https:// address ending .git');
        }
        if (! preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]{0,99}$#', $branch) || str_contains($branch, '..')) {
            throw new InvalidArgumentException("Not a valid branch name: {$branch}");
        }
    }

    /**
     * Set when the application's creation is waiting at its "access" step: the public key to add to the
     * repository host (or, once it is added, what is wrong with the branch), and which repository, so the console can say it wherever the application shows.
     *
     * @return array{key: string, host: string, path: string, operationId: int, siteId: int, problem: ?string}|null
     */
    public static function awaitingDeployKey(stdClass $site): ?array
    {
        if ($site->deploy_key_public === null || $site->repository === null) {
            return null;
        }
        $op = DB::table('operations')->where('organization_id', $site->organization_id)->where('site_id', $site->id)
            ->where('kind', 'app_create')->where('status', 'running')->orderByDesc('id')->first();
        if ($op === null || \App\Lib\Operations\Present::currentStage($op) !== 'access') {
            return null;
        }
        $repo = \App\Lib\Git\GitRepo::parse($site->repository);

        return [
            'key' => trim((string) $site->deploy_key_public), 'host' => $repo->host, 'path' => $repo->path, 'operationId' => (int) $op->id, 'siteId' => (int) $site->id,
            // Set once the host has accepted the key but the branch is not in the repository.
            'problem' => (json_decode((string) $op->meta, true) ?: [])['branch_problem'] ?? null,
        ];
    }

    /** The private half of the application's deploy key, for checking that the host has accepted it. */
    public function deployKey(stdClass $site): ?string
    {
        return $this->vault->get($site->organization_id, self::deployKeySecret(self::home($site), $site->name));
    }

    /** Pins the SSH host key of a repository that is not on GitHub: a known_hosts line. */
    public function setGitHostKey(stdClass $site, string $line): void
    {
        if (! preg_match('/^\S+ (ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp\d+) [A-Za-z0-9+\/=]+$/', trim($line))) {
            throw new InvalidArgumentException('The SSH host key is not a known_hosts line.');
        }
        DB::table('sites')->where('id', $site->id)->update(['git_host_key' => trim($line), 'updated_at' => now()]);
    }

    /** The secret in the application's webhook address, made on first use. Anyone who has it can ask for a deploy of the configured branch, nothing more. */
    public function webhookSecret(stdClass $site): string
    {
        $name = 'git-webhook:'.self::home($site).':'.$site->name;
        $secret = $this->vault->get($site->organization_id, $name);
        if ($secret === null) {
            $secret = bin2hex(random_bytes(20));
            $this->vault->put($site->organization_id, $name, $secret);
        }

        return $secret;
    }

    /** Gives $to the same way into the repository as $from: its deploy key, or the GitHub App. */
    public function copyGitAccess(stdClass $from, stdClass $to): void
    {
        $key = $this->vault->get($from->organization_id, self::deployKeySecret(self::home($from), $from->name));
        if ($key !== null) {
            $this->vault->put($to->organization_id, self::deployKeySecret(self::home($to), $to->name), $key);
        }
        DB::table('sites')->where('id', $to->id)->update(['deploy_key_public' => $from->deploy_key_public, 'git_source' => $from->git_source, 'git_host_key' => $from->git_host_key ?? null, 'updated_at' => now()]);
    }

    /** Removes the site's record and every secret it had: what is on its servers is removed first, elsewhere. */
    public function forget(stdClass $site): void
    {
        foreach ([self::deployKeySecret(self::home($site), $site->name), 'git-webhook:'.self::home($site).':'.$site->name, self::envSecret(self::home($site), $site->name),
            self::composerAuthSecret(self::home($site), $site->name), self::injectedSecret(self::home($site), $site->name), Previews::overridesSecret($site), \App\Lib\Containers\ContainerApps::registrySecret($site)] as $name) {
            $this->vault->forget($site->organization_id, $name);
        }
        foreach (DB::table('site_env_versions')->where('site_id', $site->id)->pluck('version') as $version) {
            $this->vault->forget($site->organization_id, self::envVersionSecret($site, (int) $version));
        }
        DB::table('site_env_versions')->where('site_id', $site->id)->delete();
        DB::table('site_nodes')->where('site_id', $site->id)->delete();
        DB::table('sites')->where('id', $site->id)->delete();
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.deleted', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}");
    }

    /**
     * Deletes the site's record but keeps its secrets and version history, so
     * the data left on disk and its database can still be found and used.
     * An archive row records where it is.
     *
     * @param  list<string>  $servers
     */
    public function archive(stdClass $site, array $servers): void
    {
        DB::table('site_archives')->insert([
            'organization_id' => $site->organization_id,
            'original_site_id' => $site->id,
            'name' => $site->name,
            'domain' => $site->domain,
            'node_id' => $site->node_id,
            'servers' => json_encode(array_values($servers)),
            'directory' => self::root($site),
            'database_cluster_id' => $site->database_cluster_id,
            'database_name' => $site->database_cluster_id !== null ? str_replace('-', '_', $site->name) : null,
            'cache_cluster_id' => $site->cache_cluster_id,
            'bucket_id' => $site->bucket_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('site_nodes')->where('site_id', $site->id)->delete();
        DB::table('sites')->where('id', $site->id)->delete();
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.archived', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['servers' => $servers, 'directory' => self::root($site)]);
    }

    /** Deletes an archive's record and every secret it kept. Its files and database are removed first, elsewhere. */
    public function forgetArchive(object $archive): void
    {
        $node = (string) $archive->node_id;
        foreach (["site:{$node}:{$archive->name}:deploy_key", "site:{$node}:{$archive->name}:env", "site:{$node}:{$archive->name}:env:injected", "site:{$node}:{$archive->name}:registry", "site:{$archive->original_site_id}:preview_env"] as $secret) {
            $this->vault->forget($archive->organization_id, $secret);
        }
        foreach (DB::table('site_env_versions')->where('site_id', $archive->original_site_id)->pluck('version') as $version) {
            $this->vault->forget($archive->organization_id, "site:{$node}:{$archive->name}:env:v{$version}");
        }
        DB::table('site_env_versions')->where('site_id', $archive->original_site_id)->delete();
        DB::table('site_archives')->where('id', $archive->id)->delete();
        $this->audit->record($archive->organization_id, Actor::system('control-plane'), 'site.archive_deleted', resourceType: 'site',
            resourceId: "{$node}/{$archive->name}");
    }

    private static function deployKeySecret(string $nodeId, string $name): string
    {
        return "site:{$nodeId}:{$name}:deploy_key";
    }

    private static function composerAuthSecret(string $nodeId, string $name): string
    {
        return "site:{$nodeId}:{$name}:composer_auth";
    }

    private static function envSecret(string $nodeId, string $name): string
    {
        return "site:{$nodeId}:{$name}:env";
    }

    /** Where the injected layer is kept: what Unharbored sets from the resources a site uses. */
    private static function injectedSecret(string $nodeId, string $name): string
    {
        return self::envSecret($nodeId, $name).':injected';
    }

    /**
     * The user's own variables, enough to boot a Laravel application. The
     * database, session and cache settings are not here: they are injected
     * (defaultInjected), so attaching a cluster later replaces them rather than
     * being overridden by a copy the user never wrote.
     */
    private const GENERATED_SOURCE = 'Made with the application';

    /**
     * What every application is given when it is made: its name, environment,
     * key, address and logging. Injected, not the user's, so their own
     * variables are only ever what they typed.
     *
     * @return array<string, array{value: string, source: string}>
     */
    private function generated(string $name, string $domain): array
    {
        $values = [
            'APP_NAME' => $name, 'APP_ENV' => 'production', 'APP_KEY' => 'base64:'.base64_encode(random_bytes(32)), 'APP_DEBUG' => 'false',
            'APP_URL' => "http://{$domain}", 'LOG_CHANNEL' => 'stack', 'LOG_STACK' => 'single', 'LOG_LEVEL' => 'info',
        ];

        return array_map(fn ($v) => ['value' => $v, 'source' => self::GENERATED_SOURCE], $values);
    }

    /**
     * An application from before this: the same values sat in the user's own
     * variables, as if typed. Moves the ones that are what we would have made
     * (any APP_KEY, the rest by value) to injected. What the server is given
     * does not change; only who owns it does, so a later change to the
     * address reaches APP_URL and a value that differs stays the user's.
     *
     * @return list<string> the names moved
     */
    public function moveGeneratedDefaults(stdClass $site): array
    {
        $this->splitEnvLayers($site);
        $generated = array_map(fn ($e) => $e['value'], $this->generated($site->name, $site->domain));
        $matches = fn (string $key, string $value): bool => match ($key) {
            'APP_KEY' => str_starts_with($value, 'base64:'),
            'APP_URL' => in_array($value, ["http://{$site->domain}", "https://{$site->domain}"], true),
            default => isset($generated[$key]) && $generated[$key] === $value,
        };

        $before = $this->customEnv($site);
        $injected = $this->injected($site);
        $moved = [];
        $kept = [];
        foreach (preg_split('/\R/', $before) ?: [] as $line) {
            if (preg_match('/^([A-Z_]+)=(.*)$/', $line, $m) && isset($generated[$m[1]]) && $matches($m[1], trim($m[2], " \"'")) && ! isset($injected[$m[1]])) {
                $injected[$m[1]] = ['value' => trim($m[2], " \"'"), 'source' => self::GENERATED_SOURCE];
                $moved[] = $m[1];

                continue;
            }
            $kept[] = $line;
        }
        if ($moved === []) {
            return [];
        }
        $after = trim((string) preg_replace("/\n{3,}/", "\n\n", implode("\n", $kept)), "\n");
        $after = $after === '' ? '' : $after."\n";

        $this->vault->put($site->organization_id, self::injectedSecret(self::home($site), $site->name), (string) json_encode($injected));
        $this->vault->put($site->organization_id, self::envSecret(self::home($site), $site->name), $after);
        $this->recordEnvVersion($site, $before, $after, 'moved what was made with the application to injected: '.implode(', ', $moved));
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.env_split', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['moved' => $moved]);

        return $moved;
    }

    /**
     * Until a database or cache is attached the site runs on SQLite, kept in
     * shared/ so it survives releases.
     *
     * @return array<string, array{value: string, source: string}>
     */
    private function defaultInjected(string $name): array
    {
        $database = '/home/'.ServerStack::DEPLOY_USER."/{$name}/shared/database.sqlite";
        $source = 'Default: SQLite on the server';

        return array_map(fn ($v) => ['value' => $v, 'source' => $source], [
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => $database,
            'SESSION_DRIVER' => 'database',
            'CACHE_STORE' => 'database',
            'QUEUE_CONNECTION' => 'database',
        ]);
    }

    /**
     * Port 80 always answers HTTP-01 challenges, so a certificate can be
     * issued before TLS is on and renewed after. Once TLS is on, everything
     * else on port 80 redirects.
     */
    private function vhost(stdClass $site): string
    {
        $acme = ServerStack::ACME_WEBROOT;
        $challenge = <<<CONF
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        root {$acme};
        default_type text/plain;
    }
CONF;

        // Reverb's socket, and each background process that is given a public path, on the site's own domain.
        $reverb = $this->reverbProxy($site).$this->daemonProxies($site);
        $primary = self::home($site) === $site->node_id;

        // On an Incus host, visitors without IPv6 arrive through the host's ingress: HTTP with X-Forwarded-For, HTTPS
        // on its own port with a PROXY header. Only the ingress's address is believed, so nobody else can claim to be
        // someone. Direct IPv6 visitors keep using 80 and 443 as before.
        $ingress = \App\Lib\Providers\Incus\Ingress::sourceFor($site);
        $ingressTls = '';
        if ($ingress !== null) {
            $challenge .= "\n\n    set_real_ip_from {$ingress};\n    real_ip_header X-Forwarded-For;";
            $ingressTls = "\n    listen [::]:".\App\Lib\Providers\Incus\Ingress::PROXY_PORT." ssl http2 proxy_protocol;\n    set_real_ip_from {$ingress};\n    real_ip_header proxy_protocol;";
        }

        // Behind a load balancer, HTTPS ends at the balancer and every server
        // serves the app on port 80. Let's Encrypt's challenge may land on any
        // of them, so the others pass it to the primary, which holds the
        // certificate. The balancer reaches servers over the private network,
        // where nothing else can claim to be it.
        if (($site->load_balancer_id ?? null) !== null) {
            if (! $primary) {
                $challenge = <<<CONF
    location ^~ /.well-known/acme-challenge/ {
        auth_basic off;
        proxy_pass http://{$this->privateAddress($site)};
        proxy_set_header Host \$host;
    }
CONF;
            }
            $balanced = $challenge.<<<'CONF'


    set_real_ip_from 10.0.0.0/8;
    real_ip_header X-Forwarded-For;
CONF.$reverb;
            $https = (bool) ($site->tls ?? false) ? "\n        fastcgi_param HTTPS on;\n        fastcgi_param SERVER_PORT 443;" : '';
            $out = "# Managed by cloud-manager. Changes here are overwritten on deploy.\n"
                .$this->appServer($site, "    listen 80;\n    listen [::]:80;\n    listen ".self::healthPort($site).';', $balanced, $https);
            // The primary keeps answering HTTPS itself, for anyone whose DNS
            // still points at it.
            if ($primary && (bool) ($site->tls ?? false)) {
                [$chain, $key] = self::certificateFiles($site);
                $out .= $this->appServer($site, "    listen 443 ssl http2;\n    listen [::]:443 ssl http2;\n    ssl_certificate {$chain};\n    ssl_certificate_key {$key};\n    ssl_protocols TLSv1.2 TLSv1.3;", $reverb);
            }

            return $out;
        }

        // An extra server has no certificate of its own: until a load
        // balancer fronts the site, it answers on port 80 only.
        if (! (bool) ($site->tls ?? false) || ! $primary) {
            return "# Managed by cloud-manager. Changes here are overwritten on deploy.\n"
                .$this->appServer($site, "    listen 80;\n    listen [::]:80;", $challenge.$reverb);
        }

        [$chain, $key] = self::certificateFiles($site);
        $tls = <<<CONF
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    ssl_certificate {$chain};
    ssl_certificate_key {$key};
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_session_cache shared:SSL:10m;
    add_header Strict-Transport-Security "max-age=31536000" always;{$ingressTls}
CONF;

        return <<<CONF
# Managed by cloud-manager. Changes here are overwritten on deploy.
server {
    listen 80;
    listen [::]:80;
    server_name {$site->domain};

{$challenge}

    location / {
        return 301 https://\$host\$request_uri;
    }
}

CONF.$this->appServer($site, $tls, $reverb);
    }

    /** nginx for each background process with a public path: the exact path and everything under it, WebSockets allowed, the prefix removed. */
    private function daemonProxies(stdClass $site): string
    {
        $out = '';
        foreach (self::daemons($site) as $d) {
            if (($d['port'] ?? null) === null || ($d['path'] ?? null) === null) {
                continue;
            }
            $upstream = 'http://127.0.0.1:'.(int) $d['port'].'/';
            $headers = <<<CONF
        proxy_pass {$upstream};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 3600s;
CONF;
            $out .= "\n\n    # background process {$d['name']}\n    location = {$d['path']} {\n{$headers}\n    }\n    location ^~ {$d['path']}/ {\n{$headers}\n    }";
        }

        return $out;
    }

    /**
     * Reverb's client socket (/app) and HTTP API (/apps) on the site's own
     * domain, so browsers get wss:// on the site's certificate and the
     * Reverb port itself is never exposed beyond loopback.
     */
    private function reverbProxy(stdClass $site): string
    {
        if (! (bool) ($site->reverb ?? false)) {
            return '';
        }

        $port = self::reverbPort($site);
        $out = '';
        foreach (['/app/', '/apps/'] as $path) {
            $out .= <<<CONF


    location ^~ {$path} {
        proxy_pass http://127.0.0.1:{$port};
        proxy_http_version 1.1;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "Upgrade";
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_read_timeout 3600s;
    }
CONF;
        }

        return $out;
    }

    /** The primary's address on the private network, for the other servers to reach it. */
    private function privateAddress(stdClass $site): string
    {
        $home = self::home($site);
        $attributes = json_decode((string) DB::table('resources')->where('organization_id', $site->organization_id)->where('type', 'server')
            ->where('slug', strstr($home, '.', true) ?: $home)->value('attributes'), true) ?: [];

        return (string) ($attributes['private_ipv4'] ?? $attributes['ipv4'] ?? throw new InvalidArgumentException("No address recorded for {$home}."));
    }

    private function appServer(stdClass $site, string $listen, string $extra, string $fastcgi = ''): string
    {
        $root = self::root($site);
        $appCurrent = self::current($site);
        $php = self::phpVersion($site);

        // $realpath_root rather than $document_root: current is a symlink that
        // moves on every deploy, and PHP-FPM and OPcache must see the release
        // path or they keep serving the previous one.
        // A preview is never for search engines.
        $robots = ($site->parent_site_id ?? null) !== null ? "\n    add_header X-Robots-Tag \"noindex, nofollow\" always;" : '';

        // The site's own settings: nginx's request body limit, and a snippet of
        // the user's, kept between markers so it is easy to find in the file.
        $web = $this->nginx($site);
        // One access log and one error log for this site, so the Logs tab and watch mode can show
        // its requests and its errors alone. nginx's own logrotate rule covers *.log in that folder.
        $limits = "\n    access_log /var/log/nginx/{$site->name}.access.log;\n    error_log /var/log/nginx/{$site->name}.error.log;"
            ."\n    client_max_body_size {$web['upload_mb']}m;"
            .(self::basicAuthOn($site) ? "\n    auth_basic \"Restricted\";\n    auth_basic_user_file ".self::authFile($site).';' : '');
        $snippet = $web['snippet'] === '' ? '' : "\n\n    # ---- your snippet ----\n".preg_replace('/^(?=.)/m', '    ', $web['snippet'])."\n    # ---- end of your snippet ----";
        $timeout = "\n        fastcgi_read_timeout {$web['timeout_s']}s;";

        if (! Runtimes::has($site, 'fpm')) {
            return Runtimes::server($site, $listen, $extra, $snippet, $limits, $robots);
        }

        return <<<CONF
server {
{$listen}
    server_name {$site->domain};
    root {$appCurrent}/public;
    index index.php;
    charset utf-8;{$robots}{$limits}{$snippet}

{$extra}

    location / {
        try_files \$uri \$uri/ /index.php?\$query_string;
    }

    # The front controller is the only PHP that ever executes. Any other
    # .php path goes to Laravel as a route, so a stray script in public/
    # cannot run, and packages that route URLs ending in .php still work.
    location = /index.php {
        fastcgi_pass unix:/run/php/php{$php}-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME \$realpath_root\$fastcgi_script_name;
        fastcgi_param DOCUMENT_ROOT \$realpath_root;{$fastcgi}{$timeout}
    }

    location ~ \.php\$ {
        rewrite ^ /index.php last;
    }

    location ~ /\.(?!well-known).* {
        deny all;
    }
}

CONF;
    }
}
