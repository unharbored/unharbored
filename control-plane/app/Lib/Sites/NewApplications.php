<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Projects;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Git\GitRemote;
use App\Lib\Git\GitRepo;
use App\Lib\Git\GitSources;
use App\Lib\Operations\Operations;
use App\Lib\Operations\RunOperation;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Provider;
use App\Lib\Providers\Providers;
use App\Lib\Providers\ProviderServer;
use App\Lib\Providers\Shapes;
use App\Lib\Servers\LocalServices;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A new application from the wizard: one choice of single server or
 * autoscaling, fixed from then on, and everything it needs made in order by
 * one app_create operation, each step an operation of its own:
 *
 *   server   a new Droplet, created and its stack installed (when asked for)
 *   address  <name>.<server ip>.sslip.io, until a domain of its own is added
 *   database / cache / storage   attached when chosen
 *   deploy, https
 *   balancer, balancer-address, balancer-https, redeploy (for the new
 *            APP_URL), pool   autoscaling only
 *
 * The site is recorded at once, so the variables typed into the wizard go
 * straight into its vault-held .env and the application shows while it is
 * being made.
 */
final class NewApplications
{
    public const BALANCER_MONTHLY = 12;

    public const BUCKET_MONTHLY = 5;

    public function __construct(
        private readonly Sites $sites,
        private readonly Operations $operations,
        private readonly AuditLog $audit,
        private readonly Providers $providers,
    ) {}

    /**
     * The wizard's answers, checked and filled in. Throws
     * InvalidArgumentException naming the field at fault.
     *
     * @param  array<string, mixed>  $in
     * @return array<string, mixed>
     */
    public function plan(string $organizationId, array $in): array
    {
        $name = strtolower(trim((string) ($in['name'] ?? '')));
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $name)) {
            throw new InvalidArgumentException('name: lowercase letters, digits and dashes, up to 30.');
        }
        if (DB::table('sites')->where('organization_id', $organizationId)->where('name', $name)->exists()) {
            throw new InvalidArgumentException("name: there is already an application called {$name}.");
        }
        if (($why = Sites::nameInUse($organizationId, $name)) !== null) {
            throw new InvalidArgumentException("name: {$why}.");
        }
        [$repository, $git] = self::resolveGit($in);
        if (($in['previews'] ?? false) && $git['source'] !== 'github_app') {
            throw new InvalidArgumentException('previews: pull request previews need the GitHub App to read this repository.');
        }
        $branch = trim((string) ($in['branch'] ?? '')) ?: 'main';
        // Where the repository can be read now (public, or through the GitHub App) the branch must be one of its own. A private
        // repository cannot be read until its deploy key is added; that step checks the branch then.
        if ($git['source'] !== 'deploy_key') {
            $known = app(GitSources::class)->branches($organizationId, $git['url'], $git['source']);
            if ($known !== [] && ! in_array($branch, $known, true)) {
                throw new InvalidArgumentException("branch: {$branch} is not a branch of {$repository}. It has: ".implode(', ', array_slice($known, 0, 12)).'.');
            }
        }
        // What it is: Laravel unless chosen otherwise, with the runtime's own settings checked now so a mistake is seen before anything is made.
        $runtime = (string) ($in['runtime'] ?? Runtimes::PHP) ?: Runtimes::PHP;
        try {
            $runtimeConfig = $runtime === Runtimes::PHP ? [] : Runtimes::validate($runtime, (array) ($in['runtime_config'] ?? []));
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('runtime: '.$e->getMessage());
        }
        try {
            $rootDir = Sites::cleanRootDir((string) ($in['root_dir'] ?? ''));
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException('root_dir: '.$e->getMessage());
        }
        $compute = (string) ($in['compute'] ?? 'single');
        if (! in_array($compute, ['single', 'autoscale'], true)) {
            throw new InvalidArgumentException('compute: single server or autoscaling.');
        }

        // One provider for everything this application owns; set now, never changed.
        $providerId = (string) ($in['provider'] ?? 'digitalocean');
        if (! array_key_exists($providerId, $this->providers->connected($organizationId))) {
            throw new InvalidArgumentException('provider: pick a provider that is connected.');
        }
        $provider = $this->providers->get($providerId);
        $catalog = $provider->catalog();
        if (($why = Shapes::lockedBecause($provider, $compute)) !== null) {
            throw new InvalidArgumentException("compute: {$why}");
        }

        $server = $in['server'] ?? [];
        if (($server['mode'] ?? 'new') === 'existing' || $provider->method() === 'ssh') {
            $node = (string) ($server['node'] ?? '');
            if (! in_array($node, array_column(self::servers($organizationId, $providerId), 'id'), true)) {
                throw new InvalidArgumentException('server: pick one of the servers listed.');
            }
            $server = ['mode' => 'existing', 'node' => $node];
        } else {
            $region = (string) ($server['region'] ?? $catalog->defaultRegion);
            $size = (string) ($server['size'] ?? array_keys($catalog->sizes)[1] ?? array_key_first($catalog->sizes));
            if (! $catalog->hasRegion($region) || ! $catalog->hasSize($size)) {
                throw new InvalidArgumentException('server: pick a region and size from the list.');
            }
            // The size must be one this region sells; a provider that cannot say is not second-guessed.
            $offered = method_exists($provider, 'availableSizes') ? $provider->availableSizes($organizationId, $region) : null;
            if ($offered !== null && ! in_array($size, $offered, true)) {
                throw new InvalidArgumentException("server: {$provider->label()} does not offer {$size} in {$region}. Pick another size or region.");
            }
            $server = ['mode' => 'new', 'region' => $region, 'size' => $size, 'slug' => $this->freeSlug($organizationId, "{$name}-1")];
            $server['node'] = "{$server['slug']}.{$organizationId}";
        }

        $pool = null;
        if ($compute === 'autoscale') {
            $p = $in['pool'] ?? [];
            $pool = ['min' => (int) ($p['min'] ?? 1), 'max' => (int) ($p['max'] ?? 4), 'cpu' => ((int) ($p['cpu'] ?? 70)) / 100,
                'size' => (string) ($p['size'] ?? ($server['size'] ?? array_keys($catalog->sizes)[2] ?? array_key_first($catalog->sizes)))];
            if ($pool['min'] < 0 || $pool['max'] < max(1, $pool['min']) || $pool['max'] > 20 || $pool['cpu'] < 0.2 || $pool['cpu'] > 0.95 || ! $catalog->hasSize($pool['size'])) {
                throw new InvalidArgumentException('pool: between 0 and 20 servers, max at least min, CPU between 20 and 95%.');
            }
        }

        $clusters = collect(self::clusters($organizationId, $providerId))->keyBy('id');
        // A static site is files nginx serves: no database, cache or uploads to attach.
        $static = $runtime === 'static';
        $database = $static ? 'none' : (string) ($in['database'] ?? 'sqlite');
        $onServer = in_array($database, ['local:pgsql', 'local:mysql'], true);
        if (! $onServer && ! str_starts_with($database, 'new:') && ! in_array($database, ['sqlite', 'none'], true) && ! in_array($clusters[(int) $database]['engine'] ?? null, ['pg', 'mysql'], true)) {
            throw new InvalidArgumentException('database: SQLite, PostgreSQL or MySQL on the server, an existing cluster or a new one, or none.');
        }
        if ($compute === 'autoscale' && ($database === 'sqlite' || $onServer)) {
            throw new InvalidArgumentException('database: autoscaling needs a database every server shares; pick a cluster, or none and bring your own.');
        }
        $cache = $static ? 'none' : (string) ($in['cache'] ?? 'database');
        if (! in_array($cache, ['database', 'none', 'local:valkey', 'new:valkey'], true) && ($clusters[(int) $cache]['engine'] ?? null) !== 'valkey') {
            throw new InvalidArgumentException('cache: the database, Valkey on the server, an existing Valkey cluster or a new one, or none.');
        }
        if ($cache === 'database' && $database === 'none') {
            throw new InvalidArgumentException('cache: the database cannot hold the cache when there is no database. Pick Valkey, or none.');
        }
        if ($cache === 'local:valkey' && $compute !== 'single') {
            throw new InvalidArgumentException('cache: Valkey on the server is for a single server; pick a cluster, or none and bring your own.');
        }
        // A new cluster is made in the server's region, named for the application, and attached once it is online.
        $region = $server['mode'] === 'new' ? $server['region'] : self::serverRegion($organizationId, (string) $server['node']);
        $newDb = str_starts_with($database, 'new:') ? $this->newCluster($organizationId, $provider, substr($database, 4), ['pg', 'mysql'], (string) ($in['database_size'] ?? ''), "{$name}-db", $region, 'database') : null;
        $newCache = $cache === 'new:valkey' ? $this->newCluster($organizationId, $provider, 'valkey', ['valkey'], (string) ($in['cache_size'] ?? ''), "{$name}-cache", $region, 'cache') : null;

        $storage = $static ? 'disk' : (string) ($in['storage'] ?? 'disk');
        if (! in_array($storage, ['disk', 'bucket'], true)) {
            throw new InvalidArgumentException('storage: the server disk or an object storage bucket.');
        }
        if ($storage === 'bucket' && ! in_array('object_storage', $provider->supplies(), true)) {
            throw new InvalidArgumentException("storage: {$provider->label()} does not offer object storage; keep uploads on disk, or set up your own in the app's variables.");
        }

        $env = [];
        foreach ($static ? [] : (array) ($in['env'] ?? []) as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key)) {
                throw new InvalidArgumentException("env: {$key} is not a variable name.");
            }
            if ((string) $value !== '') {
                $env[(string) $key] = (string) $value;
            }
        }

        // Variables of the user's own win over the ones Unharbored injects for what it sets up. That is allowed, but not for an autoscaled
        // application, whose servers could then each be pointed at something only one of them has (a SQLite file, a file cache).
        $set = fn (string $choice, array $keys) => ! in_array($choice, ['sqlite', 'database', 'none'], true) ? array_values(array_intersect(array_keys($env), $keys)) : [];
        $overrides = [...$set($database, Sites::INJECTABLE['database']), ...$set($cache, Sites::INJECTABLE['cache']), ...($storage === 'bucket' ? array_values(array_intersect(array_keys($env), Sites::INJECTABLE['storage'])) : [])];
        $risky = $compute === 'autoscale' ? array_values(array_intersect(array_keys($env), [...Sites::INJECTABLE['database'], ...Sites::INJECTABLE['cache']])) : [];
        if ($risky !== []) {
            throw new InvalidArgumentException('env: '.implode(', ', $risky).' would override what Unharbored sets so every server reaches the same database and cache. Take them out: an autoscaled application cannot use its own.');
        }
        $warnings = $overrides === [] ? [] : ['Your variables '.implode(', ', $overrides).' override what Unharbored sets up for this application, so those settings will not apply. Remove them to use what you chose.'];

        // A private repository waits here until its deploy key has been added on the host.
        $steps = [...($server['mode'] === 'new' ? ['server'] : []), 'address', ...($git['source'] === 'deploy_key' ? ['access'] : []),
            ...($newDb ? ['cluster-db'] : []), ...($newCache ? ['cluster-cache'] : []),
            ...(! in_array($database, ['sqlite', 'none'], true) ? ['database'] : []), ...(! in_array($cache, ['database', 'none'], true) ? ['cache'] : []),
            // An autoscaling app always has a bucket: new servers fetch builds from it.
            ...($storage === 'bucket' || $compute === 'autoscale' ? ['storage'] : []), 'deploy', 'https',
            ...($compute === 'autoscale' ? ['balancer', 'balancer-address', 'balancer-https', 'redeploy', 'pool'] : [])];

        $label = fn (string $id, string $fallback) => isset($clusters[(int) $id]) ? $clusters[(int) $id]['slug'] : $fallback;
        $summary = (match (true) {
            $database === 'sqlite' => 'SQLite',
            $database === 'local:pgsql' => 'PostgreSQL on the server',
            $database === 'local:mysql' => 'MySQL on the server',
            $static => 'no database needed',
            $database === 'none' => 'no database (managed by you)',
            $newDb !== null => 'a new '.DatabaseClusters::ENGINES[$newDb['engine']].' cluster, '.$newDb['slug'],
            default => ($clusters[(int) $database]['engine'] === 'pg' ? 'PostgreSQL' : 'MySQL').' on '.$label($database, ''),
        }).' · '.(match (true) {
            $cache === 'database' => 'cache in the database',
            $cache === 'local:valkey' => 'Valkey on the server',
            $static => 'no cache needed',
            $cache === 'none' => 'no cache (managed by you)',
            $newCache !== null => 'a new Valkey cluster, '.$newCache['slug'],
            default => 'Valkey on '.$label($cache, ''),
        }).($static ? '' : ' · uploads on '.($storage === 'bucket' ? 'an object storage bucket' : 'disk'));

        $project = Projects::resolve($organizationId, (int) ($in['project'] ?? 0) ?: null);

        return ['project' => $project, 'provider' => $providerId, 'warnings' => $warnings, 'summary' => $summary, 'git' => $git, 'name' => $name, 'repository' => $repository, 'branch' => $branch, 'runtime' => $runtime, 'runtime_config' => $runtimeConfig, 'root_dir' => $rootDir, 'compute' => $compute, 'server' => $server, 'pool' => $pool,
            'database' => match (true) {
                $database === 'sqlite' => null, $newDb !== null => ['new' => $newDb], $onServer || $database === 'none' => $database, default => (int) $database
            },
            'cache' => match (true) {
                $cache === 'database' => null, $newCache !== null => ['new' => $newCache], in_array($cache, ['local:valkey', 'none'], true) => $cache, default => (int) $cache
            },
            'storage' => $storage, 'env' => $env, 'previews' => (bool) ($in['previews'] ?? false), 'backups' => ! $static && (bool) ($in['backups'] ?? false), 'steps' => $steps,
            'monthly' => $this->monthly($server, $pool, $storage, $catalog, ($newDb['monthly'] ?? 0) + ($newCache['monthly'] ?? 0))];
    }

    /**
     * Where the code is, from the wizard's answers: a repository on any host (the wizard resolved how it is reached), or a GitHub App
     * repository by name. Shared with the container wizard.
     *
     * @param  array<string, mixed>  $in
     * @return array{0: string, 1: array{url: string, source: string, host: string, fingerprint: ?string}}
     */
    public static function resolveGit(array $in): array
    {
        // Code from a repository on any host (the wizard resolved how it is reached), or, as always, a GitHub App repository by name.
        $given = $in['git'] ?? null;
        if (is_array($given) && trim((string) ($given['url'] ?? '')) !== '') {
            try {
                $repo = GitRepo::parse((string) $given['url']);
            } catch (InvalidArgumentException $e) {
                throw new InvalidArgumentException('repository: '.$e->getMessage());
            }
            $how = (string) ($given['source'] ?? '');
            if (! in_array($how, ['github_app', 'https', 'deploy_key'], true) || ($how === 'github_app' && ! $repo->isGitHub())) {
                throw new InvalidArgumentException('repository: choose the repository again.');
            }
            $repository = $repo->display();
            $git = ['url' => $how === 'https' ? $repo->httpsUrl() : $repo->sshUrl(), 'source' => $how, 'host' => $repo->host, 'fingerprint' => ($given['fingerprint'] ?? null) ?: null];
        } else {
            $repository = trim((string) ($in['repository'] ?? ''));
            if (! preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$#', $repository)) {
                throw new InvalidArgumentException('repository: pick a repository.');
            }
            $git = ['url' => "git@github.com:{$repository}.git", 'source' => 'github_app', 'host' => 'github.com', 'fingerprint' => null];
        }

        return [$repository, $git];
    }

    /**
     * Records the application and queues what makes it.
     *
     * @param  array<string, mixed>  $plan  From plan().
     * @return array{site: int, operation: int}
     */
    public function start(string $organizationId, array $plan, string $actor): array
    {
        // A placeholder name until the server's address is known.
        $domain = "{$plan['name']}.pending.internal";
        $git = $plan['git'];
        // A host that is not GitHub has its SSH host key pinned now, from what it shows, and what you were shown must be what it still shows.
        $hostKey = null;
        if ($git['source'] === 'deploy_key' && $git['host'] !== 'github.com') {
            try {
                $key = app(GitRemote::class)->hostKey(GitRepo::parse($git['url']));
            } catch (\Throwable $e) {
                throw new InvalidArgumentException('repository: '.$e->getMessage());
            }
            if ($git['fingerprint'] !== null && $git['fingerprint'] !== $key['fingerprint']) {
                throw new InvalidArgumentException("repository: {$git['host']}'s SSH host key is now {$key['fingerprint']}, not the {$git['fingerprint']} you were shown. Check it, then choose the repository again.");
            }
            $hostKey = $key['line'];
        }
        $site = $this->sites->create($organizationId, $plan['server']['node'], $plan['name'], $domain, $git['url'], $plan['branch'], $hostKey, $plan['project']);
        if ($plan['runtime'] !== Runtimes::PHP) {
            $site = $this->sites->setRuntime($site, $plan['runtime'], $plan['runtime_config']);
        }
        // Deploy on push: by webhook, or for a public repository we do not own, by looking every five minutes.
        $site = $this->sites->setGitSource($site, $git['source'], true);
        DB::table('sites')->where('id', $site->id)->update(['compute' => $plan['compute'], 'provider' => $plan['provider'],
            'backups' => $plan['backups'], 'pr_previews_base' => $plan['previews'] ? $plan['branch'] : null, 'root_dir' => $plan['root_dir'] === '' ? null : $plan['root_dir'], 'updated_at' => now()]);
        // None: nothing installed, nothing injected; the default SQLite and database-backed settings are dropped.
        foreach (['database' => 'database', 'cache' => 'cache'] as $key => $kind) {
            if ($plan[$key] === 'none') {
                app(LocalServices::class)->detach($site, $kind);
            }
        }
        if ($plan['env'] !== []) {
            $this->sites->setEnvValues($site, $plan['env']);
        }

        $meta = array_diff_key($plan, ['env' => true]) + ['done' => [], 'children' => []];
        $title = "Create {$plan['name']} (".($plan['compute'] === 'autoscale' ? 'autoscaling' : 'single server').')';
        $id = $this->operations->create($organizationId, 'app_create', $title, 'console', $actor, $plan['steps'], $site->id, meta: $meta);
        RunOperation::dispatch($id);

        $this->audit->record($organizationId, Actor::user($actor), 'site.application_created', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: array_diff_key($meta, ['done' => true, 'children' => true, 'steps' => true]));

        return ['site' => (int) $site->id, 'operation' => $id];
    }

    /**
     * Servers a new application can go on: enrolled, not the preview
     * server and not an autoscale pool's member.
     *
     * @return list<array{id: string, name: string}>
     */
    public static function servers(string $organizationId, ?string $provider = null): array
    {
        return DB::table('nodes')->where('organization_id', $organizationId)
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->where(fn ($q) => $q->whereNull('role')->orWhereNotIn('role', ['preview', 'ingress', \App\Lib\Providers\Incus\IncusHost::ROLE]))
            ->whereNotIn('node_id', DB::table('site_nodes')->whereNotNull('autoscale_pool_id')->select('node_id'))
            ->orderBy('node_id')->pluck('node_id')->map(fn ($n) => ['id' => $n, 'name' => strstr($n, '.', true) ?: $n])->all();
    }

    /** @return list<array{id: int, slug: string, engine: string}> Clusters that are up. */
    public static function clusters(string $organizationId, ?string $provider = null): array
    {
        return DB::table('resources')->where('organization_id', $organizationId)->where('type', 'database_cluster')->where('state', 'active')
            ->when($provider !== null, fn ($q) => $q->where('provider', $provider))
            ->orderBy('slug')->get(['id', 'slug', 'attributes'])
            ->map(fn ($r) => ['id' => (int) $r->id, 'slug' => $r->slug, 'engine' => (string) (json_decode((string) $r->attributes, true)['engine'] ?? '')])
            ->filter(fn ($c) => ! str_starts_with($c['slug'], 'pitr-'))->values()->all();
    }

    /** The public address a node answers on: its server's (IPv4, else IPv6), else the one it enrolled from. */
    public static function publicIp(string $organizationId, string $nodeId): ?string
    {
        $attributes = DB::table('resources')->where('organization_id', $organizationId)->where('type', 'server')
            ->where('slug', strstr($nodeId, '.', true) ?: $nodeId)->value('attributes');
        $ip = \App\Lib\Providers\Incus\Ingress::ipv4ForNode($organizationId, $nodeId) ?? ProviderServer::publicAddress(json_decode((string) $attributes, true) ?: []);

        return $ip ?? DB::table('enrollment_grants')->where('node_id', $nodeId)->whereNotNull('consumed_by_ip')->orderByDesc('id')->value('consumed_by_ip');
    }

    /** The free sslip.io name an application on its own server could take now, or null when it has one or sits behind a balancer. */
    public static function freeAddress(object $site): ?string
    {
        if (($site->load_balancer_id ?? null) !== null || str_ends_with((string) $site->domain, '.sslip.io') || ($site->parent_site_id ?? null) !== null) {
            return null;
        }
        $ip = self::publicIp($site->organization_id, $site->node_id);

        return $ip === null ? null : self::sslipDomain($site->name, $ip);
    }

    /** A free sslip.io name for an address: the dots of an IPv4, or the colons of an IPv6, become dashes. */
    public static function sslipDomain(string $name, string $ip): string
    {
        return "{$name}.".str_replace(['.', ':'], '-', $ip).'.sslip.io';
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>|null  $pool
     * @return array{low: int|float, high: int|float}
     */
    private function monthly(array $server, ?array $pool, string $storage, Catalog $catalog, int $clusters = 0): array
    {
        $base = $clusters + ($server['mode'] === 'new' ? $catalog->sizes[$server['size']]['price'] : 0) + ($storage === 'bucket' || $pool !== null ? self::BUCKET_MONTHLY : 0);
        if ($pool === null) {
            return ['low' => $base, 'high' => $base];
        }
        $each = $catalog->sizes[$pool['size']]['price'];

        return ['low' => $base + self::BALANCER_MONTHLY + $pool['min'] * $each, 'high' => $base + self::BALANCER_MONTHLY + $pool['max'] * $each];
    }

    /**
     * A cluster to be made for this application. Names are never reused, even a destroyed cluster's.
     *
     * @param  list<string>  $engines
     * @return array{engine: string, size: string, slug: string, region: string, monthly: int}
     */
    private function newCluster(string $organizationId, Provider $provider, string $engine, array $engines, string $size, string $base, ?string $region, string $field): array
    {
        if (! in_array($engine, $engines, true) || ! in_array("database:{$engine}", $provider->supplies(), true)) {
            throw new InvalidArgumentException("{$field}: {$provider->label()} has no managed ".(DatabaseClusters::ENGINES[$engine] ?? $engine).' to create.');
        }
        $size = $size === '' ? (string) array_key_first(DatabaseClusters::SIZES) : $size;
        if (! isset(DatabaseClusters::SIZES[$size])) {
            throw new InvalidArgumentException("{$field}: pick a cluster size from the list.");
        }
        if ($region === null) {
            throw new InvalidArgumentException("{$field}: the server's region is not known, so a cluster cannot be made beside it. Pick an existing cluster, or create one on the Databases page.");
        }
        $slug = $base;
        for ($i = 2; DB::table('resources')->where('organization_id', $organizationId)->where('type', 'database_cluster')->where('slug', $slug)->exists(); $i++) {
            $slug = "{$base}-{$i}";
        }

        return ['engine' => $engine, 'size' => $size, 'slug' => $slug, 'region' => $region, 'monthly' => DatabaseClusters::SIZES[$size]['monthly']];
    }

    /** The region an existing server was made in, or null when the platform did not make it. */
    public static function serverRegion(string $organizationId, string $nodeId): ?string
    {
        $attributes = DB::table('resources')->where('organization_id', $organizationId)->where('type', 'server')->where('slug', strstr($nodeId, '.', true) ?: $nodeId)->value('attributes');

        return json_decode((string) $attributes, true)['region'] ?? null;
    }

    public static function freeSlug(string $organizationId, string $base): string
    {
        $slug = $base;
        for ($i = 2; DB::table('nodes')->where('node_id', "{$slug}.{$organizationId}")->exists()
            || DB::table('resources')->where('organization_id', $organizationId)->where('slug', $slug)->exists(); $i++) {
            $slug = preg_replace('/-\d+$/', '', $base)."-{$i}";
        }

        return $slug;
    }
}
