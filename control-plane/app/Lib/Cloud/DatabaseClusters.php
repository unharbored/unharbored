<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\DigitalOcean\Client;
use App\Lib\DigitalOcean\Exceptions\RequestFailed;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Providers\ProviderScope;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use App\Lib\Servers\ServerStack;
use App\Lib\Sites\Sites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Managed database clusters in the organisation's DigitalOcean account, and
 * attaching sites to them.
 *
 * Each site gets a database and a user of its own on the cluster, never the
 * admin account, so one site's credentials reach only its own data. The
 * cluster's firewall admits each attached site's server and nothing else.
 *
 * Creating follows the Droplet provisioner's discipline: the resource is
 * declared before any network call, the create carries an idempotency key,
 * and a create with no usable answer is recorded as orphaned, not failed,
 * because a billable cluster may exist.
 */
final class DatabaseClusters
{
    /** What the console offers. DigitalOcean's own names on the left. */
    public const ENGINES = ['pg' => 'PostgreSQL', 'mysql' => 'MySQL', 'valkey' => 'Valkey'];

    public const SIZES = [
        'db-s-1vcpu-1gb' => ['label' => '1 vCPU · 1 GB · 10 GB disk', 'monthly' => 15],
        'db-s-1vcpu-2gb' => ['label' => '1 vCPU · 2 GB · 25 GB disk', 'monthly' => 30],
        'db-s-2vcpu-4gb' => ['label' => '2 vCPU · 4 GB · 38 GB disk', 'monthly' => 60],
    ];

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ResourceManager $resources,
        private readonly Sites $sites,
        private readonly AuditLog $audit,
        private readonly GatewayClient $gateway,
    ) {}

    /** A cluster Unharbored runs on a server of its own is DatabaseServers' to handle; this class is DigitalOcean's. */
    private function selfRun(): DatabaseServers
    {
        return app(DatabaseServers::class);
    }

    /** Starts creating a cluster. It takes DigitalOcean several minutes to come online; refresh() follows it. */
    public function create(string $organizationId, string $slug, string $engine, string $region, string $size): ResourceRecord
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $slug)) {
            throw new InvalidArgumentException('Name a cluster with lowercase letters, digits and dashes.');
        }
        if (! isset(self::ENGINES[$engine]) || ! isset(self::SIZES[$size])) {
            throw new InvalidArgumentException('Pick an engine and size from the list.');
        }

        $actor = Actor::system('control-plane');
        $resource = $this->resources->declare($organizationId, 'database_cluster', $slug, [
            'engine' => $engine, 'region' => $region, 'size' => $size,
        ]);
        if ($resource->state === ResourceState::Deleted) {
            throw new InvalidArgumentException("{$slug} was the name of a destroyed cluster. Choose another name.");
        }
        if ($resource->state !== ResourceState::Pending && $resource->state !== ResourceState::Failed) {
            throw new InvalidArgumentException("There is already a cluster called {$slug}.");
        }

        $client = $this->credentials->clientFor($organizationId, "Creating database cluster {$slug}", $actor);
        $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: "Creating database cluster {$slug}", actor: $actor);

        try {
            $response = $client->post('databases', [
                'name' => "cm-{$slug}",
                'engine' => $engine,
                'region' => $region,
                'size' => $size,
                'num_nodes' => 1,
                'tags' => ['cloud-manager', "org:{$organizationId}", "resource:{$slug}"],
            ], $resource->idempotencyKey);
        } catch (RequestFailed $e) {
            $uncertain = $e->status === null || $e->status >= 500;
            $this->resources->transition($resource, $uncertain ? ResourceState::Orphaned : ResourceState::Failed,
                reason: $uncertain ? 'Create did not return a usable answer; a cluster may exist' : 'DigitalOcean refused the create',
                error: $e->getMessage(), actor: $actor);

            throw new RuntimeException('Cluster creation failed: '.$e->getMessage(), previous: $e);
        }

        $id = $response['database']['id'] ?? null;
        if ($id === null) {
            $this->resources->transition($resource, ResourceState::Orphaned, reason: 'Create returned no cluster id', actor: $actor);

            throw new RuntimeException('DigitalOcean accepted the create but returned no cluster id.');
        }

        return $this->resources->recordExternalId($resource, (string) $id);
    }

    /** Engines that can be brought in: Valkey has one password for the whole cluster, so it is only ever made here. */
    public const ADOPTABLE = ['pg', 'mysql'];

    /**
     * The online PostgreSQL and MySQL clusters in the organisation's DigitalOcean account that Unharbored is not
     * tracking and did not make (its own carry the cloud-manager tag and belong to reconciliation).
     *
     * @return list<array{id: string, name: string, engine: string, version: ?string, region: ?string, size: ?string, nodes: int}>
     */
    public function discover(string $organizationId): array
    {
        $client = $this->credentials->clientFor($organizationId, 'Listing database clusters that can be brought in', Actor::system('control-plane'));
        $known = DB::table('resources')->where('organization_id', $organizationId)->where('type', 'database_cluster')
            ->where('state', '!=', 'deleted')->whereNotNull('external_id')->pluck('external_id')->map(fn ($id) => (string) $id)->all();
        $found = [];
        foreach ($client->paginate('databases', 'databases') as $db) {
            if (! in_array($db['engine'] ?? null, self::ADOPTABLE, true) || ($db['status'] ?? null) !== 'online'
                || in_array((string) ($db['id'] ?? ''), $known, true) || in_array('cloud-manager', $db['tags'] ?? [], true)) {
                continue;
            }
            $found[] = ['id' => (string) $db['id'], 'name' => (string) ($db['name'] ?? $db['id']), 'engine' => (string) $db['engine'], 'version' => $db['version'] ?? null,
                'region' => $db['region'] ?? null, 'size' => $db['size'] ?? null, 'nodes' => (int) ($db['num_nodes'] ?? 1)];
        }

        return $found;
    }

    /**
     * Brings a cluster the organisation already has into Unharbored, so sites can be attached to it. Nothing at
     * DigitalOcean changes: no tag is added, no user, database or firewall rule touched. It is marked as not made
     * here, so destroying it only forgets it.
     */
    public function adopt(string $organizationId, string $clusterId): ResourceRecord
    {
        $actor = Actor::system('control-plane');
        $client = $this->credentials->clientFor($organizationId, 'Bringing in a database cluster', $actor);
        try {
            $db = $client->get('databases/'.rawurlencode($clusterId))['database'] ?? [];
        } catch (RequestFailed $e) {
            throw new InvalidArgumentException($e->status === 404 ? 'That cluster is not in your DigitalOcean account.' : 'DigitalOcean could not be asked about that cluster: '.$e->getMessage());
        }
        if (($db['id'] ?? null) === null || ! in_array($db['engine'] ?? null, self::ADOPTABLE, true)) {
            throw new InvalidArgumentException('Only PostgreSQL and MySQL clusters can be brought in.');
        }
        if (($db['status'] ?? null) !== 'online') {
            throw new InvalidArgumentException('That cluster is not online yet.');
        }
        if (DB::table('resources')->where('organization_id', $organizationId)->where('type', 'database_cluster')->where('state', '!=', 'deleted')->where('external_id', (string) $db['id'])->exists()) {
            throw new InvalidArgumentException('That cluster is already here.');
        }

        $base = trim(substr((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) ($db['name'] ?? 'cluster'))), 0, 26), '-') ?: 'cluster';
        $slug = $base;
        for ($n = 2; DB::table('resources')->where('organization_id', $organizationId)->where('type', 'database_cluster')->where('slug', $slug)->exists(); $n++) {
            $slug = "{$base}-{$n}";
        }
        $ca = base64_decode((string) ($client->get('databases/'.rawurlencode($clusterId).'/ca')['ca']['certificate'] ?? ''), true) ?: null;
        $resource = $this->resources->declare($organizationId, 'database_cluster', $slug, [
            'engine' => $db['engine'], 'region' => $db['region'] ?? null, 'size' => $db['size'] ?? null, 'version' => $db['version'] ?? null,
            'host' => $db['connection']['host'] ?? null, 'port' => $db['connection']['port'] ?? null, 'ca_pem' => $ca,
            'adopted' => true, 'adopted_name' => $db['name'] ?? null,
        ]);
        $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: 'Bringing in an existing cluster', actor: $actor);
        $resource = $this->resources->recordExternalId($resource, (string) $db['id']);
        $resource = $this->resources->transition($resource, ResourceState::Active, reason: 'Existing cluster brought in; nothing was changed at DigitalOcean', actor: $actor);
        $this->audit->record($organizationId, $actor, 'database_cluster.adopted', resourceType: 'database_cluster', resourceId: $slug,
            metadata: ['cluster' => $db['name'] ?? null, 'engine' => $db['engine']]);

        return $resource;
    }

    /** True for a cluster that was brought in rather than made here. */
    public static function isAdopted(ResourceRecord $cluster): bool
    {
        return ($cluster->attributes['adopted'] ?? false) === true;
    }

    /**
     * Reads the cluster's state from DigitalOcean. Online: becomes active,
     * with its address and CA recorded (neither is secret). The admin
     * password is never stored; sites get users of their own.
     */
    public function refresh(ResourceRecord $resource): ResourceRecord
    {
        if (DatabaseServers::isSelfRun($resource)) {
            return $resource;
        }
        $actor = Actor::system('control-plane');
        $client = $this->credentials->clientFor($resource->organizationId, "Checking database cluster {$resource->slug}", $actor);
        $db = $client->get("databases/{$resource->externalId}")['database'] ?? [];

        if (($db['status'] ?? null) !== 'online' || $resource->state === ResourceState::Active) {
            return $resource;
        }

        $ca = base64_decode((string) ($client->get("databases/{$resource->externalId}/ca")['ca']['certificate'] ?? ''), true) ?: null;
        $resource = $this->resources->mergeAttributes($resource, [
            'host' => $db['connection']['host'] ?? null,
            'port' => $db['connection']['port'] ?? null,
            'version' => $db['version'] ?? null,
            'ca_pem' => $ca,
        ]);

        return $this->resources->transition($resource, ResourceState::Active, reason: 'Cluster is online', actor: $actor);
    }

    /**
     * Gives the site a database and user on the cluster, admits its server
     * through the cluster firewall, and writes the connection into its .env.
     * That .env change, like any other, reaches the server on the next deploy.
     *
     * @return list<string> What was done, for the operation log.
     */
    public function attach(stdClass $site, ResourceRecord $cluster): array
    {
        if ($cluster->state !== ResourceState::Active) {
            throw new InvalidArgumentException("Cluster {$cluster->slug} is not online yet.");
        }
        ProviderScope::assertSame((string) ($site->provider ?? 'digitalocean'), $cluster->provider, "Cluster {$cluster->slug}");
        if (DatabaseServers::isSelfRun($cluster)) {
            $this->assertDatabaseIsOurs((object) ((array) $site + ['database_cluster_id' => $cluster->id]), str_replace('-', '_', (string) $site->name));

            return $this->selfRun()->attach($site, $cluster);
        }
        $rule = $this->ruleFor($site->organization_id, $site->node_id);

        $actor = Actor::system('control-plane');
        $client = $this->credentials->clientFor($site->organization_id, "Attaching {$site->name} to {$cluster->slug}", $actor);
        $cid = $cluster->externalId;
        $name = str_replace('-', '_', $site->name);
        $done = [];
        $this->assertDatabaseIsOurs((object) ((array) $site + ['database_cluster_id' => $cluster->id]), $name);
        if (self::isAdopted($cluster) && ($site->database_cluster_id ?? null) !== $cluster->id) {
            $this->assertNothingCalledOnAdopted($client, $cid, $cluster, $name);
        }

        // Both kinds of cluster admit the site's server first: the grant
        // below runs from it.
        $done[] = $this->admit($client, $cid, $rule);

        if ($cluster->attributes['engine'] === 'valkey') {
            return [...$done, ...$this->attachCache($site, $cluster, $client)];
        }

        // Re-running an attach must not fail on what the first run made.
        try {
            $client->post("databases/{$cid}/dbs", ['name' => $name]);
            $done[] = "database {$name} created";
        } catch (RequestFailed $e) {
            if ($e->status !== 422 && $e->status !== 409) {
                throw $e;
            }
            $done[] = "database {$name} already exists";
        }

        // A fresh password every attach: re-attaching is also how a site's
        // database password is rotated.
        try {
            $client->delete("databases/{$cid}/users/{$name}");
        } catch (RequestFailed $e) {
            if ($e->status !== 404) {
                throw $e;
            }
        }
        $user = $client->post("databases/{$cid}/users", ['name' => $name])['user'] ?? [];
        $password = (string) ($user['password'] ?? throw new RuntimeException('DigitalOcean created the user but returned no password.'));
        $done[] = "user {$name} created";

        // DigitalOcean creates the database owned by its admin account, and
        // from PostgreSQL 15 an ordinary user cannot create tables in its
        // public schema: the first real deploy failed exactly there. Grant
        // the site's user its own database and nothing else.
        $engine = (string) $cluster->attributes['engine'];
        $admin = $client->get("databases/{$cid}")['database']['connection'] ?? [];
        $this->grant($site, $cluster, $engine, $name, (string) ($admin['user'] ?? 'doadmin'), (string) ($admin['password'] ?? ''));
        $done[] = "user {$name} owns what it needs in database {$name}";

        $this->sites->setInjected($site, [
            'DB_CONNECTION' => $engine === 'pg' ? 'pgsql' : 'mysql',
            'DB_HOST' => (string) $cluster->attributes['host'],
            'DB_PORT' => (string) $cluster->attributes['port'],
            'DB_DATABASE' => $name,
            'DB_USERNAME' => $name,
            'DB_PASSWORD' => $password,
            // Managed clusters refuse unencrypted connections. Postgres needs
            // only the mode; MySQL needs the CA, which the site setup writes.
            // The one the other engine needs is removed, not left behind.
            'DB_SSLMODE' => $engine === 'pg' ? 'require' : null,
            'PGSSLROOTCERT' => null,
            'MYSQL_ATTR_SSL_CA' => $engine === 'mysql' ? Sites::root($site).'/shared/database-ca.crt' : null,
        ], "Database cluster {$cluster->slug}");
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster->id, 'database_mode' => 'cluster', 'updated_at' => now()]);
        $done[] = '.env points at the cluster; deploy to switch';
        if (($over = $this->sites->overriddenKeys($site, Sites::INJECTABLE['database'])) !== []) {
            $done[] = 'your own variables override '.implode(', ', $over).', so those keep their value; remove the overrides to use the cluster';
        }

        $this->audit->record($site->organization_id, $actor, 'site.database_attached', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['cluster' => $cluster->slug, 'database' => $name]);

        return $done;
    }

    /**
     * A cluster that was brought in already holds other people's databases and users. Attaching makes a database and a
     * user named for the site and resets that user's password, so it must never meet one that already exists.
     */
    private function assertNothingCalledOnAdopted(Client $client, string $cid, ResourceRecord $cluster, string $name): void
    {
        $taken = array_map(fn ($d) => $d['name'] ?? null, $client->get("databases/{$cid}/dbs")['dbs'] ?? []);
        $users = array_map(fn ($u) => $u['name'] ?? null, $client->get("databases/{$cid}/users")['users'] ?? []);
        if (in_array($name, $taken, true) || in_array($name, $users, true)) {
            throw new InvalidArgumentException("{$cluster->slug} already has a database or user called {$name}, and it was not made here, so it is not touched. Give the application another name or use a different cluster.");
        }
    }

    /**
     * Refuses to touch a database that another application of this organisation
     * maps to: the name on the cluster is the application's name, and two
     * applications must never share one (audit 2026-11-08, finding 7).
     */
    private function assertDatabaseIsOurs(stdClass $site, string $name): void
    {
        $other = DB::table('sites')->where('organization_id', $site->organization_id)->where('id', '!=', $site->id)
            ->where('database_cluster_id', $site->database_cluster_id ?? null)->pluck('name')
            ->first(fn ($n) => str_replace('-', '_', (string) $n) === $name);
        if ($other !== null) {
            throw new InvalidArgumentException("The database {$name} also belongs to {$other}; it is not touched.");
        }
    }

    /**
     * Stops managing the site's database. The database and its data stay on
     * the cluster, and the connection already in the site's .env keeps
     * working, so a deploy is not needed and nothing breaks. The user can
     * replace it with their own credentials, attach another cluster, or move
     * to SQLite afterwards.
     */
    public function detach(stdClass $site, string $actor): void
    {
        if (($site->database_cluster_id ?? null) === null) {
            throw new InvalidArgumentException("{$site->name} has no managed database attached.");
        }
        $cluster = DB::table('resources')->where('id', $site->database_cluster_id)->first(['slug']);
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => null, 'updated_at' => now()]);
        // The connection is kept, as the user's now: Unharbored stops managing it.
        $this->sites->releaseInjected($site, Sites::INJECTABLE['database']);
        $this->audit->record($site->organization_id, Actor::system($actor), 'site.database_detached', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['cluster' => $cluster->slug ?? null,
                'note' => 'the database and its data stay on the cluster; the app keeps the connection in its .env']);
    }

    /**
     * The reverse of attach() for a site whose database is its own and
     * disposable, a preview: its database and user are dropped. Never called
     * for a site whose data matters, nor a preview sharing its parent's.
     *
     * Pass $siteOwned for a normal application being deleted with its data:
     * its database and user on the cluster are its own, so they go too.
     *
     * @return list<string>
     */
    public function dropDatabase(stdClass $site, bool $siteOwned = false): array
    {
        if (! $siteOwned && (($site->parent_site_id ?? null) === null || ($site->preview_database ?? null) === 'shared')) {
            throw new InvalidArgumentException("{$site->name}'s database is not a preview's own; it is not dropped.");
        }
        if ($siteOwned && ($site->database_cluster_id ?? null) === null) {
            return [];
        }
        $own = $site->database_cluster_id !== null ? $this->resources->findById((int) $site->database_cluster_id) : null;
        if ($own !== null && DatabaseServers::isSelfRun($own)) {
            $this->assertDatabaseIsOurs($site, str_replace('-', '_', (string) $site->name));

            return $this->selfRun()->dropDatabase($site, $own);
        }
        $cid = $site->database_cluster_id !== null ? DB::table('resources')->where('id', $site->database_cluster_id)->value('external_id') : null;
        if ($cid === null) {
            return [];
        }
        $name = str_replace('-', '_', $site->name);
        $this->assertDatabaseIsOurs($site, $name);
        $client = $this->credentials->clientFor($site->organization_id, "Dropping {$site->name}'s database", Actor::system('control-plane'));
        $done = [];
        foreach (["users/{$name}" => "user {$name} dropped", "dbs/{$name}" => "database {$name} dropped"] as $path => $line) {
            try {
                $client->delete("databases/{$cid}/{$path}");
                $done[] = $line;
            } catch (RequestFailed $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }

        return $done;
    }

    /**
     * Lets one more of the site's servers through the firewall of every
     * cluster the site uses, for a server being added to the site.
     *
     * @return list<string> What was done, for the operation log.
     */
    public function admitServer(stdClass $site, string $nodeId): array
    {
        $clusters = array_values(array_unique(array_filter([$site->database_cluster_id ?? null, $site->cache_cluster_id ?? null])));
        if ($clusters === []) {
            return [];
        }
        $done = [];
        $rule = $client = null;
        foreach ($clusters as $id) {
            $record = $this->resources->findById((int) $id);
            if ($record !== null && DatabaseServers::isSelfRun($record)) {
                $done[] = $this->selfRun()->admitServer($site, $nodeId, $record);

                continue;
            }
            $rule ??= $this->ruleFor($site->organization_id, $nodeId);
            $client ??= $this->credentials->clientFor($site->organization_id, "Admitting {$nodeId} for {$site->name}", Actor::system('control-plane'));
            $cid = (string) DB::table('resources')->where('id', $id)->value('external_id');
            $done[] = $this->admit($client, $cid, $rule);
        }

        return $done;
    }

    /**
     * How a cluster firewall names a server: a Droplet by id, which survives
     * its address changing; any other server by the IPv4 address it enrolled
     * from, which is the address it reaches the cluster from too. If that
     * address changes, the server loses the cluster until admitted again.
     *
     * @return array{type: string, value: string}
     */
    private function ruleFor(string $organizationId, string $nodeId): array
    {
        $droplet = DB::table('resources')->where('organization_id', $organizationId)->where('type', 'server')
            ->where('slug', strstr($nodeId, '.', true) ?: $nodeId)->value('external_id');
        if ($droplet !== null) {
            return ['type' => 'droplet', 'value' => (string) $droplet];
        }
        $ip = DB::table('enrollment_grants')->where('node_id', $nodeId)->whereNotNull('consumed_by_ip')->orderByDesc('id')->value('consumed_by_ip');
        if ($ip === null || filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            throw new InvalidArgumentException("{$nodeId} is not a Droplet and enrolled from no public IPv4 address, so the cluster firewalls cannot name it.");
        }

        return ['type' => 'ip_addr', 'value' => $ip];
    }

    /**
     * The reverse of admitServer, once a server stops serving a site: each of
     * the site's clusters stops admitting the Droplet, unless another site on
     * that server still uses the cluster.
     *
     * @return list<string>
     */
    public function releaseServer(stdClass $site, string $nodeId): array
    {
        $done = [];
        foreach (array_values(array_unique(array_filter([$site->database_cluster_id ?? null, $site->cache_cluster_id ?? null]))) as $id) {
            $neighbour = DB::table('sites')->where('id', '!=', $site->id)
                ->where(fn ($q) => $q->where('database_cluster_id', $id)->orWhere('cache_cluster_id', $id))
                ->where(fn ($q) => $q->where('node_id', $nodeId)
                    ->orWhereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id')))
                ->value('name');
            $cluster = DB::table('resources')->where('id', $id)->first(['slug', 'external_id']);
            if ($neighbour !== null) {
                $done[] = "{$cluster->slug} still admits it: {$neighbour} uses that cluster there";

                continue;
            }
            $record = $this->resources->findById((int) $id);
            if ($record !== null && DatabaseServers::isSelfRun($record)) {
                try {
                    $done[] = $this->selfRun()->releaseServer($site, $nodeId, $record);
                } catch (InvalidArgumentException) {
                }

                continue;
            }
            try {
                $rule = $this->ruleFor($site->organization_id, $nodeId);
            } catch (InvalidArgumentException) {
                continue;
            }
            $what = ($rule['type'] === 'droplet' ? 'droplet ' : '').$rule['value'];
            $client = $this->credentials->clientFor($site->organization_id, "Releasing {$nodeId} from {$site->name}", Actor::system('control-plane'));
            $rules = array_map(fn ($r) => ['type' => $r['type'], 'value' => (string) $r['value']],
                $client->get("databases/{$cluster->external_id}/firewall")['rules'] ?? []);
            $kept = array_values(array_filter($rules, fn ($r) => $r !== $rule));
            if (count($kept) === count($rules)) {
                $done[] = "{$cluster->slug} did not admit {$what}";

                continue;
            }
            $client->put("databases/{$cluster->external_id}/firewall", ['rules' => $kept]);
            $done[] = "{$cluster->slug} no longer admits {$what}";
        }

        return $done;
    }

    /**
     * The firewall's rules are replaced whole, so read, add, write back.
     *
     * @param  array{type: string, value: string}  $rule
     */
    private function admit(Client $client, string $cid, array $rule): string
    {
        $rules = array_map(fn ($r) => ['type' => $r['type'], 'value' => (string) $r['value']],
            $client->get("databases/{$cid}/firewall")['rules'] ?? []);
        $what = ($rule['type'] === 'droplet' ? 'droplet ' : '').$rule['value'];
        if (in_array($rule, $rules, true)) {
            return "firewall already admits {$what}";
        }
        $rules[] = $rule;
        $client->put("databases/{$cid}/firewall", ['rules' => $rules]);

        return "firewall admits {$what}";
    }

    /**
     * Cache, sessions and queue on Valkey. DigitalOcean's managed Valkey has
     * one password, not a user per site, so sites sharing a cluster are kept
     * apart by key prefix only: weaker than the per-site database users, and
     * said so in the console.
     *
     * @return list<string>
     */
    private function attachCache(stdClass $site, ResourceRecord $cluster, Client $client): array
    {
        $conn = $client->get("databases/{$cluster->externalId}")['database']['connection'] ?? [];
        $password = (string) ($conn['password'] ?? throw new RuntimeException('DigitalOcean returned no Valkey password.'));
        $prefix = str_replace('-', '_', $site->name).'_';

        $this->sites->setInjected($site, [
            'REDIS_CLIENT' => 'phpredis',
            // tls:// in the host is how phpredis is told to use TLS without the
            // app's redis config needing a scheme option.
            'REDIS_HOST' => 'tls://'.$cluster->attributes['host'],
            'REDIS_PORT' => (string) $cluster->attributes['port'],
            'REDIS_USERNAME' => (string) ($conn['user'] ?? 'default'),
            'REDIS_PASSWORD' => $password,
            'REDIS_PREFIX' => $prefix,
            'CACHE_PREFIX' => $prefix,
            'CACHE_STORE' => 'redis',
            'SESSION_DRIVER' => 'redis',
            'QUEUE_CONNECTION' => 'redis',
        ], "Cache cluster {$cluster->slug}");
        DB::table('sites')->where('id', $site->id)->update(['cache_cluster_id' => $cluster->id, 'cache_mode' => 'cluster', 'updated_at' => now()]);

        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.cache_attached', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['cluster' => $cluster->slug, 'prefix' => $prefix]);

        return ["cache, sessions and queue on {$cluster->slug} under prefix {$prefix}", '.env points at the cluster; deploy to switch'];
    }

    /**
     * A new database and user on the site's cluster, besides the site's own,
     * for a clone. Refused if either name is taken; the user owns that
     * database and nothing else.
     *
     * @return array{engine: string, host: string, port: int, database: string, username: string, password: string}
     */
    public function createDatabase(stdClass $site, ResourceRecord $cluster, string $name): array
    {
        if (! preg_match('/^[a-z][a-z0-9_]{1,40}$/', $name)) {
            throw new InvalidArgumentException('A database name is lower case letters, digits and underscores, starting with a letter.');
        }
        if (DatabaseServers::isSelfRun($cluster)) {
            return $this->selfRun()->createDatabase($site, $cluster, $name);
        }
        $client = $this->credentials->clientFor($site->organization_id, "Creating database {$name} on {$cluster->slug}", Actor::system('control-plane'));
        $cid = $cluster->externalId;
        $taken = array_column($client->get("databases/{$cid}/dbs")['dbs'] ?? [], 'name');
        $users = array_column($client->get("databases/{$cid}/users")['users'] ?? [], 'name');
        if (in_array($name, $taken, true) || in_array($name, $users, true)) {
            throw new InvalidArgumentException("{$cluster->slug} already has a database or user called {$name}.");
        }

        $client->post("databases/{$cid}/dbs", ['name' => $name]);
        try {
            $user = $client->post("databases/{$cid}/users", ['name' => $name])['user'] ?? [];
            $password = (string) ($user['password'] ?? throw new RuntimeException('DigitalOcean created the user but returned no password.'));
            $engine = (string) $cluster->attributes['engine'];
            $admin = $client->get("databases/{$cid}")['database']['connection'] ?? [];
            $this->grant($site, $cluster, $engine, $name, (string) ($admin['user'] ?? 'doadmin'), (string) ($admin['password'] ?? ''));
        } catch (\Throwable $e) {
            $this->discardDatabase($site, $cluster, $name);
            throw $e;
        }

        return ['engine' => $engine === 'pg' ? 'pgsql' : 'mysql', 'host' => (string) $cluster->attributes['host'], 'port' => (int) $cluster->attributes['port'],
            'database' => $name, 'username' => $name, 'password' => $password];
    }

    /** Removes a database and user made by createDatabase(), such as after a failed clone. Best effort. */
    public function discardDatabase(stdClass $site, ResourceRecord $cluster, string $name): void
    {
        if (DatabaseServers::isSelfRun($cluster)) {
            $this->selfRun()->discardDatabase($site, $cluster, $name);

            return;
        }
        try {
            $client = $this->credentials->clientFor($site->organization_id, "Removing database {$name} from {$cluster->slug}", Actor::system('control-plane'));
            foreach (["databases/{$cluster->externalId}/dbs/{$name}", "databases/{$cluster->externalId}/users/{$name}"] as $path) {
                try {
                    $client->delete($path);
                } catch (RequestFailed $e) {
                    if ($e->status !== 404) {
                        throw $e;
                    }
                }
            }
        } catch (\Throwable) {
        }
    }

    /**
     * Runs the grant from the site's server: the only machine the cluster
     * firewall admits. The admin password reaches the node in a root-only
     * file, never an argument, and the file is removed whether or not the
     * grant worked. It is never stored by the control plane.
     */
    private function grant(stdClass $site, ResourceRecord $cluster, string $engine, string $name, string $adminUser, #[\SensitiveParameter] string $adminPassword): void
    {
        if ($adminPassword === '') {
            throw new RuntimeException('DigitalOcean did not return the admin credentials needed to grant the site its database.');
        }
        $script = '/root/.cm-db-grant.php';
        $caFile = '/root/.cm-db-grant-ca.crt';
        try {
            $result = $this->gateway->run($site->organization_id, $site->node_id, 'apply_recipe', ['spec' => [
                'name' => 'database-grant',
                'steps' => [
                    ['id' => 'ca', 'type' => 'file', 'path' => $caFile, 'content_b64' => base64_encode((string) ($cluster->attributes['ca_pem'] ?? '')), 'mode' => '0600'],
                    ['id' => 'script', 'type' => 'file', 'path' => $script, 'mode' => '0600', 'sensitive' => true,
                        'content_b64' => base64_encode(self::grantScript($engine, (string) $cluster->attributes['host'], (int) $cluster->attributes['port'], $name, $adminUser, $adminPassword, $caFile))],
                    ['id' => 'grant', 'type' => 'command', 'name' => ServerStack::phpBinary(ServerStack::defaultPhpFor($site->node_id)), 'args' => [$script], 'description' => "grant {$name} its database"],
                    ['id' => 'forget', 'type' => 'absent', 'path' => $script],
                ],
            ]], timeoutSeconds: 60, reason: "Grant {$site->name} its database");
        } finally {
            // The recipe stops at a failed step; never leave the password behind.
            try {
                $this->gateway->run($site->organization_id, $site->node_id, 'apply_recipe', ['spec' => ['name' => 'database-grant-cleanup', 'steps' => [
                    ['id' => 'forget', 'type' => 'absent', 'path' => $script],
                    ['id' => 'forget-ca', 'type' => 'absent', 'path' => $caFile],
                ]]], timeoutSeconds: 30);
            } catch (\Throwable) {
            }
        }
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException('Granting the site its database failed: '.($result['error'] ?? 'no detail'));
        }
    }

    /** A tiny PHP program that connects as admin over TLS and grants one database to one user. */
    public static function grantScript(string $engine, string $host, int $port, string $name, string $adminUser, #[\SensitiveParameter] string $adminPassword, string $caFile): string
    {
        if (! preg_match('/^[a-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException("Refusing to grant on a database called {$name}.");
        }
        $q = fn (string $v): string => var_export($v, true);

        if ($engine === 'pg') {
            $dsn = $q("pgsql:host={$host};port={$port};dbname={$name};sslmode=require");
            $sql = ['GRANT ALL PRIVILEGES ON DATABASE "'.$name.'" TO "'.$name.'"', 'GRANT ALL ON SCHEMA public TO "'.$name.'"'];
            $options = '[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]';
        } else {
            $dsn = $q("mysql:host={$host};port={$port};dbname={$name}");
            $sql = ['GRANT ALL PRIVILEGES ON `'.$name.'`.* TO \''.$name.'\'@\'%\''];
            $options = '[PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::MYSQL_ATTR_SSL_CA => '.$q($caFile).']';
        }

        $statements = implode("\n", array_map(fn ($s) => '$pdo->exec('.$q($s).');', $sql));

        return "<?php\n"
            .'$pdo = new PDO('.$dsn.', '.$q($adminUser).', '.$q($adminPassword).', '.$options.");\n"
            .$statements."\n"
            ."echo \"granted\\n\";\n";
    }

    /**
     * DigitalOcean's own daily backups of the cluster, newest first, with
     * created_at exactly as DigitalOcean gives it: that string, and only
     * that, is what a restore may name.
     *
     * $since leaves out backups taken before then: a site's database is
     * not in a backup older than its attachment.
     *
     * @return list<array{created_at: string, gb: float}>
     */
    public function backups(ResourceRecord $cluster, ?\DateTimeInterface $since = null): array
    {
        // A server we run has no provider-side backups: the nightly site dumps are what protect it.
        if (DatabaseServers::isSelfRun($cluster)) {
            return [];
        }
        $client = $this->credentials->clientFor($cluster->organizationId, "Listing backups of {$cluster->slug}", Actor::system('control-plane'));
        $out = array_map(fn ($b) => ['created_at' => (string) $b['created_at'], 'gb' => (float) ($b['size_gigabytes'] ?? 0)],
            $client->get("databases/{$cluster->externalId}/backups")['backups'] ?? []);
        usort($out, fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return array_values(array_filter($out, fn ($b) => $since === null || Carbon::parse($b['created_at'])->gte($since)));
    }

    /**
     * A new cluster made from one of $cluster's daily backups, which must
     * be named by its created_at exactly as backups() gave it. It costs what
     * any cluster of its size costs while it exists, so whoever asks for
     * one destroys it when done.
     */
    public function fork(ResourceRecord $cluster, string $backupCreatedAt, string $slug): ResourceRecord
    {
        if (! in_array($backupCreatedAt, array_column($this->backups($cluster), 'created_at'), true)) {
            throw new InvalidArgumentException("{$cluster->slug} has no backup from {$backupCreatedAt}.");
        }
        $at = Carbon::parse($backupCreatedAt);
        $actor = Actor::system('control-plane');
        $resource = $this->resources->declare($cluster->organizationId, 'database_cluster', $slug, [
            'engine' => $cluster->attributes['engine'], 'region' => $cluster->attributes['region'], 'size' => $cluster->attributes['size'],
            'fork_of' => $cluster->slug, 'at' => $at->format(DATE_ATOM),
        ]);
        if ($resource->state !== ResourceState::Pending) {
            throw new InvalidArgumentException("There is already a cluster called {$slug}.");
        }
        $client = $this->credentials->clientFor($cluster->organizationId, "Restoring {$cluster->slug} as of {$at->format('Y-m-d H:i')} UTC", $actor);
        $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: "Copy of {$cluster->slug} as of ".$at->format(DATE_ATOM), actor: $actor);
        $id = $client->post('databases', [
            'name' => "cm-{$slug}",
            'engine' => $cluster->attributes['engine'],
            'version' => $cluster->attributes['version'] ?? null,
            'region' => $cluster->attributes['region'],
            'size' => $cluster->attributes['size'],
            'num_nodes' => 1,
            'tags' => ['cloud-manager', "org:{$cluster->organizationId}", "resource:{$slug}", 'temporary'],
            'backup_restore' => ['database_name' => "cm-{$cluster->slug}", 'backup_created_at' => $at->format('Y-m-d\TH:i:s\Z')],
        ], $resource->idempotencyKey)['database']['id'] ?? throw new RuntimeException('DigitalOcean accepted the restore but returned no cluster id.');

        return $this->resources->recordExternalId($resource, (string) $id);
    }

    /** Destroys a cluster no site uses. Its data goes with it. */
    public function destroy(ResourceRecord $cluster): void
    {
        if (DatabaseServers::isSelfRun($cluster)) {
            $this->selfRun()->destroy($cluster);

            return;
        }
        $users = DB::table('sites')->where('database_cluster_id', $cluster->id)->orWhere('cache_cluster_id', $cluster->id)->pluck('name')->all();
        if ($users !== []) {
            throw new InvalidArgumentException("{$cluster->slug} is used by ".implode(', ', $users).'. Detach them first.');
        }

        $actor = Actor::system('control-plane');
        if (self::isAdopted($cluster)) {
            // Not ours to destroy: forget it and leave it, and everything on it, as it is at DigitalOcean.
            $cluster = $this->resources->transition($cluster, ResourceState::Deleting, reason: 'Forgotten; it was brought in, so it is not destroyed', actor: $actor);
            $this->resources->transition($cluster, ResourceState::Deleted, reason: 'Forgotten, not destroyed', actor: $actor);

            return;
        }
        if ($cluster->externalId !== null) {
            $client = $this->credentials->clientFor($cluster->organizationId, "Destroying database cluster {$cluster->slug}", $actor);
            try {
                $client->delete("databases/{$cluster->externalId}");
            } catch (RequestFailed $e) {
                if ($e->status !== 404) {
                    throw $e;
                }
            }
        }
        $cluster = $this->resources->transition($cluster, ResourceState::Deleting, reason: 'Destroy requested', actor: $actor);
        $this->resources->transition($cluster, ResourceState::Deleted, reason: 'Destroyed', actor: $actor);
    }
}
