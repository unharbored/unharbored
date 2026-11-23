<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Agent\AgentRelease;
use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\Present;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Incus\IncusHost;
use App\Lib\Providers\Incus\Ingress;
use App\Lib\Providers\Providers;
use App\Lib\Providers\ProviderServer;
use App\Lib\Providers\SelfHosted;
use App\Lib\Scaling\ServerScaler;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Sites;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the Servers page shows: each server's card with its state, what blocks
 * removing it, its access settings and charts, plus the Droplets and retained
 * application data that need attention. Read-only; the actions live in the
 * Servers controllers.
 */
final class ServersPage
{
    /** Chart ranges: minutes covered and seconds averaged into each point. */
    private const CHARTS = ['1h' => [60, 60], '24h' => [1440, 720], '7d' => [10080, 5040]];

    /**
     * The nodes the gateway holds right now, and whether it answered.
     *
     * @return array{0: Collection<string, array<string, mixed>>, 1: bool}
     */
    public function connected(): array
    {
        try {
            return [collect(app(GatewayClient::class)->connectedNodes())->keyBy('node_id'), true];
        } catch (\Throwable) {
            return [collect(), false];
        }
    }

    /**
     * The newest sample, as percentages, and CPU and memory over the chosen
     * range as points for a 240 by 48 chart, averaged into at most 120
     * buckets by the database. Null until the agent has reported.
     *
     * @return array<string, mixed>|null
     */
    public function metrics(string $nodeId, string $range): ?array
    {
        $last = DB::table('node_metrics')->where('organization_id', Org::id())->where('node_id', $nodeId)->orderByDesc('collected_at')->first();
        if ($last === null) {
            return null;
        }
        [$minutes, $bucket] = self::CHARTS[$range] ?? self::CHARTS['1h'];
        $since = now()->subMinutes($minutes);
        $rows = DB::table('node_metrics')->where('organization_id', Org::id())->where('node_id', $nodeId)->where('collected_at', '>=', $since)
            ->selectRaw($this->bucketSql().' AS b, AVG(cpu) AS cpu, AVG(100.0 * (mem_total - mem_available) / '.$this->greatestSql('mem_total', '1').') AS mem, MAX(cpu) AS peak', [$bucket])
            ->groupBy('b')->orderBy('b')->get();
        $start = $since->getTimestamp();
        $span = max(1, $minutes * 60);
        $line = fn (string $field) => $rows->map(fn ($r) => round(240 * min(1, max(0, ($r->b * $bucket - $start) / $span)), 1).','.round(48 - 48 * min(100, (float) $r->{$field}) / 100, 1))->implode(' ');
        $pct = fn ($used, $total) => $total > 0 ? (int) round(100 * $used / $total) : null;

        return [
            'cpu' => (int) round((float) $last->cpu),
            'mem' => $pct($last->mem_total - $last->mem_available, $last->mem_total),
            'disk' => $pct($last->disk_total - $last->disk_free, $last->disk_total),
            'diskFree' => number_format($last->disk_free / 1073741824, 1).' GB free',
            'cpuLine' => $rows->count() > 1 ? $line('cpu') : null,
            'memLine' => $rows->count() > 1 ? $line('mem') : null,
            'peak' => $rows->count() > 0 ? (int) round((float) $rows->max('peak')) : null,
            'ago' => Present::ago($last->collected_at),
        ];
    }

    /** The name of an archived application whose data a server still holds, if any. */
    public function archivedOn(string $organizationId, string $nodeIdOrSlug): ?string
    {
        $slug = strstr($nodeIdOrSlug, '.', true) ?: $nodeIdOrSlug;
        foreach (DB::table('site_archives')->where('organization_id', $organizationId)->get(['name', 'node_id', 'servers']) as $archive) {
            foreach ([(string) $archive->node_id, ...(json_decode((string) $archive->servers, true) ?: [])] as $server) {
                if ((strstr((string) $server, '.', true) ?: (string) $server) === $slug) {
                    return (string) $archive->name;
                }
            }
        }

        return null;
    }

    /**
     * One card per server.
     *
     * @param  Collection<string, array<string, mixed>>  $connected
     * @return list<array<string, mixed>>
     */
    public function nodes(string $chart, Collection $connected, ?string $only = null): array
    {
        $serving = app(AgentRelease::class)->version();
        $membership = $this->poolMembership();
        $nodes = [];
        foreach (DB::table('nodes')->where('organization_id', Org::id())->when($only !== null, fn ($q) => $q->where('node_id', $only))->orderBy('node_id')->get() as $node) {
            $slug = strstr($node->node_id, '.', true) ?: $node->node_id;
            $resource = DB::table('resources')->where('organization_id', $node->organization_id)->where('slug', $slug)->first();
            $attrs = json_decode((string) ($resource->attributes ?? '{}'), true) ?: [];
            $ops = DB::table('operations')->where('organization_id', Org::id())->where('node_id', $node->node_id)->whereNull('site_id')->orderByDesc('id')->limit(4)->get();
            $live = $connected->get($node->node_id);
            $sites = $this->sitesOn($node->node_id);
            $busy = $ops->contains(fn ($op) => in_array($op->status, ['queued', 'running'], true));
            $poolMember = DB::table('site_nodes')->where('node_id', $node->node_id)->whereNotNull('autoscale_pool_id')->exists();
            $resourcePool = $resource !== null && $resource->type === 'server'
                ? ((json_decode((string) ($resource->attributes ?? ''), true) ?: [])['pool'] ?? null) : null;
            // A pool marker on a droplet can outlive the pool. Only a pool
            // that still exists is a reason to keep the server.
            $poolIsLive = $resourcePool !== null
                && DB::table('resources')->where('organization_id', Org::id())->where('id', $resourcePool)->where('state', '!=', 'deleted')->exists();
            $droplet = $resource !== null && $resource->type === 'server' ? $resource->external_id : null;
            $stale = $resource !== null && $resource->type === 'server' && $resource->state === 'deleted';
            $archived = $this->archivedOn($node->organization_id, $node->node_id);

            $blockers = [];
            if ($sites->isNotEmpty()) {
                $blockers[] = 'Hosts '.$sites->pluck('name')->join(', ').'. Delete those applications first.';
            }
            if ($busy) {
                $blockers[] = 'An operation is running on this server.';
            }
            if ($poolMember || $poolIsLive) {
                $blockers[] = 'Managed by an autoscale pool. Stop autoscaling that application instead.';
            }
            if ($archived !== null) {
                $blockers[] = "Holds retained data for {$archived}. Delete that retained data first.";
            }
            $dbCluster = $resource !== null && $resource->type === 'server' ? ((json_decode((string) ($resource->attributes ?? ''), true) ?: [])['database_cluster'] ?? null) : null;
            if ($dbCluster !== null && DB::table('resources')->where('organization_id', Org::id())->where('type', 'database_cluster')->where('slug', $dbCluster)->where('state', '!=', 'deleted')->exists()) {
                $blockers[] = "It is the database server of cluster {$dbCluster}. Destroy the cluster on the Databases page instead.";
            }
            $role = $node->role ?? null;
            $hosted = $role === IncusHost::ROLE ? IncusHost::servers($node->organization_id) : [];
            if ($hosted !== []) {
                $blockers[] = (count($hosted) === 1 ? '1 server still lives' : count($hosted).' servers still live').' on this Incus host ('.implode(', ', $hosted).'). Destroy '.(count($hosted) === 1 ? 'it' : 'them').' first.';
            }
            if ($role === 'ingress') {
                $blockers[] = 'The IPv4 ingress belongs to the Incus host and goes when the host is removed.';
            }
            $destroyBlockers = $blockers;
            if ($droplet === null) {
                $destroyBlockers[] = 'Not a Droplet this platform created.';
            }
            if ($stale) {
                $destroyBlockers[] = 'Already deleted in DigitalOcean; use Remove stale entry.';
            }

            $nodes[] = [
                'id' => $node->node_id,
                'name' => $slug,
                'role' => $role,
                'hosted' => $hosted,
                'connected' => $live !== null,
                'since' => $live ? Present::ago($live['connected_at'] ?? null) : null,
                'lastSeen' => Present::ago($node->last_seen_at),
                'facts' => implode(' · ', array_filter([ProviderServer::publicAddress($attrs), $attrs['region'] ?? null, $attrs['size'] ?? null, $node->os, $node->arch])),
                'ip' => ProviderServer::publicAddress($attrs),
                'compute' => $this->compute($resource, $attrs),
                'pool' => $membership[$node->node_id] ?? null,
                'agent' => $node->agent_version,
                'upgrade' => $node->agent_version !== null && $node->agent_version !== $serving,
                'phpWarnings' => $this->phpWarnings($node->node_id),
                'access' => $this->access($node->organization_id, $node->node_id, ProviderServer::publicAddress($attrs)),
                'tips' => $this->tips($live !== null, $busy, $blockers, $destroyBlockers, $node->agent_version),
                'sites' => $sites->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
                'ops' => $ops->map(fn ($op) => ['id' => $op->id, 'title' => $op->title, 'status' => $op->status, 'ago' => Present::ago($op->created_at)])->all(),
                'busy' => $busy,
                'droplet' => $droplet,
                'stale' => $stale,
                'php' => array_values(array_unique([ServerStack::PHP_VERSION,
                    ...DB::table('server_php_versions')->where('node_id', $node->node_id)->pluck('version')->all()])),
                'phpPatches' => json_decode((string) ($node->php_patches ?? ''), true) ?: [],
                'services' => DB::table('server_services')->where('node_id', $node->node_id)->where('state', 'installed')->pluck('service')->all(),
                'metrics' => $this->metrics($node->node_id, $chart),
                'blockers' => $blockers,
                'destroyBlockers' => $destroyBlockers,
            ];
        }

        return $nodes;
    }

    /**
     * One row per server for the list: its state, provider and size with the
     * catalog's price, an hour of CPU and memory, and the pool it belongs to.
     *
     * @param  Collection<string, array<string, mixed>>  $connected
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $connected): array
    {
        $serving = app(AgentRelease::class)->version();
        $membership = $this->poolMembership();
        $rows = [];
        foreach (DB::table('nodes')->where('organization_id', Org::id())->orderBy('node_id')->get() as $node) {
            $slug = strstr($node->node_id, '.', true) ?: $node->node_id;
            $resource = DB::table('resources')->where('organization_id', $node->organization_id)->where('slug', $slug)->first();
            $attrs = json_decode((string) ($resource->attributes ?? '{}'), true) ?: [];
            $live = $connected->get($node->node_id);
            $metrics = $this->metrics($node->node_id, '1h');
            $rows[] = [
                'id' => $node->node_id,
                'name' => $slug,
                'connected' => $live !== null,
                'since' => $live ? Present::ago($live['connected_at'] ?? null) : null,
                'lastSeen' => Present::ago($node->last_seen_at),
                'stale' => $resource !== null && $resource->type === 'server' && $resource->state === 'deleted',
                'compute' => $this->compute($resource, $attrs),
                'agent' => $node->agent_version,
                'upgrade' => $node->agent_version !== null && $node->agent_version !== $serving,
                'apps' => $this->sitesOn($node->node_id)->count(),
                'role' => $node->role ?? null,
                'metrics' => $metrics === null ? null : array_intersect_key($metrics, array_flip(['cpu', 'mem', 'cpuLine', 'memLine'])),
                'pool' => $membership[$node->node_id] ?? null,
            ];
        }

        return $rows;
    }

    /**
     * The autoscale pools, for the group rows of the list.
     *
     * @return list<array<string, mixed>>
     */
    public function pools(): array
    {
        $out = [];
        foreach (DB::table('sites')->where('organization_id', Org::id())->whereNotNull('autoscale_pool_id')->orderBy('name')->get() as $site) {
            $pool = DB::table('resources')->where('organization_id', Org::id())->where('id', $site->autoscale_pool_id)->first();
            if ($pool === null) {
                continue;
            }
            $attrs = json_decode((string) $pool->attributes, true) ?: [];
            $load = ServerScaler::manages($site) ? app(ServerScaler::class)->load($site, ServerScaler::WINDOW_UP_MINUTES) : null;
            $out[] = [
                'site' => ['id' => (int) $site->id, 'name' => $site->name],
                'name' => $pool->slug,
                'engine' => ServerScaler::manages($site) ? 'scaler' : 'native',
                'min' => (int) ($attrs['min'] ?? 1),
                'max' => (int) ($attrs['max'] ?? 1),
                'extra' => DB::table('site_nodes')->where('site_id', $site->id)->where('autoscale_pool_id', $site->autoscale_pool_id)->count(),
                'cpu' => $load === null ? null : (int) round($load['avg'] * 100),
                'target' => (int) round(100 * (float) ($attrs['cpu'] ?? 0.7)),
                'paused' => isset($attrs['paused_at']),
            ];
        }

        return $out;
    }

    /**
     * One pool's page: its settings, its servers, what the scaler has done
     * and how busy the servers are. Null when the application has no pool.
     *
     * @param  Collection<string, array<string, mixed>>  $connected
     * @return array<string, mixed>|null
     */
    public function pool(int $siteId, string $range, Collection $connected): ?array
    {
        $site = DB::table('sites')->where('organization_id', Org::id())->where('id', $siteId)->whereNotNull('autoscale_pool_id')->first();
        $row = $site === null ? null : DB::table('resources')->where('organization_id', Org::id())->where('id', $site->autoscale_pool_id)->first();
        if ($row === null) {
            return null;
        }
        $attrs = json_decode((string) $row->attributes, true) ?: [];
        $managed = ServerScaler::manages($site);
        $scaler = app(ServerScaler::class);
        $joined = DB::table('site_nodes')->where('site_id', $site->id)->pluck('node_id')->all();

        $member = function (string $nodeId, string $slug, bool $primary, string $state, ?string $added) use ($connected, $joined): array {
            $metrics = $this->metrics($nodeId, '1h');

            return ['id' => $nodeId, 'name' => $slug, 'primary' => $primary, 'state' => $state, 'joined' => in_array($nodeId, $joined, true) || $primary,
                'connected' => $connected->has($nodeId), 'cpu' => $metrics['cpu'] ?? null, 'mem' => $metrics['mem'] ?? null, 'added' => $added];
        };
        $primary = $site->node_id;
        $members = [$member($primary, strstr($primary, '.', true) ?: $primary, true, 'active', null)];
        if ($managed) {
            $provisioner = app(ServerProvisioner::class);
            foreach ($scaler->members($scaler->of($site)) as $m) {
                $members[] = $member($provisioner->nodeId($site->organization_id, $m->slug), $m->slug, false, $m->state->value,
                    $m->stateEnteredAt === null ? null : Present::ago($m->stateEnteredAt->toIso8601String()));
            }
        }

        $nodeIds = array_column($members, 'id');
        $primaryResource = DB::table('resources')->where('organization_id', Org::id())->where('slug', strstr($primary, '.', true) ?: $primary)->first();
        $compute = $this->compute($primaryResource, json_decode((string) ($primaryResource->attributes ?? '{}'), true) ?: []);
        $load = $managed ? $scaler->load($site, ServerScaler::WINDOW_UP_MINUTES) : null;
        $lastAction = isset($attrs['last_action_at']) ? Carbon::parse((string) $attrs['last_action_at']) : null;
        $cooldown = (int) ($attrs['cooldown_minutes'] ?? ServerScaler::COOLDOWN_MINUTES);
        $price = $compute['price'];

        return [
            'site' => ['id' => (int) $site->id, 'name' => $site->name],
            'name' => $row->slug,
            'managed' => $managed,
            'compute' => $compute,
            'min' => (int) ($attrs['min'] ?? 1),
            'max' => (int) ($attrs['max'] ?? 1),
            'liveMin' => (int) ($attrs['live_min'] ?? $attrs['min'] ?? 1),
            'target' => (int) round(100 * (float) ($attrs['cpu'] ?? 0.7)),
            'schedule' => $attrs['schedule'] ?? [],
            'cpu' => $load === null ? null : (int) round($load['avg'] * 100),
            'cooling' => $lastAction !== null && $lastAction->addMinutes($cooldown)->isFuture() ? (int) ceil(now()->diffInSeconds($lastAction->addMinutes($cooldown)) / 60) : null,
            'lastAction' => $attrs['last_action'] ?? null,
            'paused' => isset($attrs['paused_at']),
            'pausedBecause' => $attrs['paused_because'] ?? null,
            'failures' => (int) ($attrs['failures'] ?? 0),
            'failureLimit' => ServerScaler::FAILURES_BEFORE_PAUSE,
            'lastFailure' => $attrs['last_failure'] ?? null,
            'balancer' => $site->load_balancer_id !== null,
            'costNow' => $price === null ? null : round($price * count($members), 2),
            'costMax' => $price === null ? null : round($price * ((int) ($attrs['max'] ?? 1) + 1), 2),
            'members' => $members,
            'history' => $this->scalingHistory($site),
            'series' => $this->cpuSeries($nodeIds, $range),
        ];
    }

    /**
     * What the scaler and the person did to a pool, newest first, from the
     * audit log: servers added and removed with the reason, pauses, resumes.
     *
     * @return list<array<string, mixed>>
     */
    private function scalingHistory(object $site): array
    {
        $titles = ['site.autoscaled' => null, 'site.autoscaler_paused' => 'Stopped adding servers', 'site.autoscaler_resumed' => 'Resumed',
            'site.autoscale_schedule_added' => 'Busy-hours window added', 'site.autoscale_schedule_removed' => 'Busy-hours window removed'];

        return DB::table('audit_records')->where('organization_id', Org::id())->where('resource_type', 'site')
            ->where('resource_id', "{$site->node_id}/{$site->name}")->whereIn('action', array_keys($titles))
            ->orderByDesc('id')->limit(30)->get(['action', 'actor_label', 'metadata', 'recorded_at'])->map(function ($r) use ($titles) {
                $meta = json_decode((string) $r->metadata, true) ?: [];
                $title = $titles[$r->action] ?? (($meta['action'] ?? '') === 'up' ? 'Added a server' : 'Removed a server');
                $why = $meta['reason'] ?? $meta['because'] ?? null;
                if ($why === null && isset($meta['days'])) {
                    $why = "at least {$meta['min']}, {$meta['days']}, {$meta['from']}:00-{$meta['to']}:00 UTC";
                }

                return ['title' => $title, 'why' => $why, 'kind' => $r->action === 'site.autoscaled' ? ($meta['action'] ?? 'up') : ($r->action === 'site.autoscaler_paused' ? 'bad' : 'info'),
                    'by' => $r->actor_label, 'ago' => Present::ago($r->recorded_at)];
            })->all();
    }

    /**
     * CPU averaged across several servers over the chosen range, as points
     * for a 240 by 48 chart. Null until two buckets exist.
     *
     * @param  list<string>  $nodeIds
     * @return array{cpuLine: string, avg: int, peak: int}|null
     */
    public function cpuSeries(array $nodeIds, string $range): ?array
    {
        [$minutes, $bucket] = self::CHARTS[$range] ?? self::CHARTS['1h'];
        $since = now()->subMinutes($minutes);
        $rows = DB::table('node_metrics')->where('organization_id', Org::id())->whereIn('node_id', $nodeIds)->where('collected_at', '>=', $since)
            ->selectRaw($this->bucketSql().' AS b, AVG(cpu) AS cpu', [$bucket])->groupBy('b')->orderBy('b')->get();
        if ($rows->count() < 2) {
            return null;
        }
        $start = $since->getTimestamp();
        $span = max(1, $minutes * 60);

        return [
            'cpuLine' => $rows->map(fn ($r) => round(240 * min(1, max(0, ($r->b * $bucket - $start) / $span)), 1).','.round(48 - 48 * min(100, (float) $r->cpu) / 100, 1))->implode(' '),
            'avg' => (int) round((float) $rows->avg('cpu')),
            'peak' => (int) round((float) $rows->max('cpu')),
        ];
    }

    /**
     * The applications on a server, as the Servers page lists them.
     *
     * @return Collection<int, object>
     */
    private function sitesOn(string $nodeId): Collection
    {
        return Projects::visibleSites()
            ->where(fn ($q) => $q->where('node_id', $nodeId)
                ->orWhereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id')))
            ->orderBy('name')->get(['id', 'name'])->unique('id')->values();
    }

    /**
     * Servers that belong to an autoscaled application's pool: its primary
     * and every server the pool added.
     *
     * @return array<string, array{site: int, primary: bool}>
     */
    private function poolMembership(): array
    {
        $out = [];
        foreach (DB::table('sites')->where('organization_id', Org::id())->whereNotNull('autoscale_pool_id')->get(['id', 'node_id', 'autoscale_pool_id']) as $site) {
            $out[$site->node_id] = ['site' => (int) $site->id, 'primary' => true];
            foreach (DB::table('site_nodes')->where('site_id', $site->id)->where('autoscale_pool_id', $site->autoscale_pool_id)->pluck('node_id') as $node) {
                $out[$node] = ['site' => (int) $site->id, 'primary' => false];
            }
        }

        return $out;
    }

    /**
     * Where a server runs and what the provider's catalog says it costs.
     * Everything is null for a server the provider list does not know (one
     * you connected yourself).
     *
     * @param  array<string, mixed>  $attrs
     * @return array{provider: ?string, providerLabel: ?string, region: ?string, regionLabel: ?string, size: ?string, sizeLabel: ?string, price: int|float|null}
     */
    private function compute(?object $resource, array $attrs): array
    {
        $provider = $resource->provider ?? null;
        $region = $attrs['region'] ?? null;
        $size = $attrs['size'] ?? null;
        $label = $regionLabel = $sizeLabel = $price = null;
        $providers = app(Providers::class);
        if ($provider !== null && $providers->has($provider)) {
            $p = $providers->get($provider);
            $label = $p->label();
            if ($p->method() === 'api') {
                $catalog = $p->catalog();
                $regionLabel = $catalog->regions[$region] ?? null;
                $sizeLabel = $catalog->sizes[$size]['label'] ?? null;
                $price = $catalog->sizes[$size]['price'] ?? null;
            }
        }
        if (($resource->slug ?? null) === Ingress::SLUG) {
            $regionLabel = 'IPv4 ingress for the host';
        }

        return ['provider' => $provider, 'providerLabel' => $label, 'region' => $region, 'regionLabel' => $regionLabel, 'size' => $size, 'sizeLabel' => $sizeLabel, 'price' => $price];
    }

    /**
     * What has been run on a server: its operations (any application) and
     * the consoles opened on it. Console input is never stored, only that one was opened.
     *
     * @return array{operations: list<array<string, mixed>>, sessions: list<array<string, mixed>>}
     */
    public function commands(string $nodeId): array
    {
        return [
            'operations' => DB::table('operations')->where('organization_id', Org::id())->where('node_id', $nodeId)->orderByDesc('id')->limit(25)
                ->get(['id', 'title', 'status', 'actor', 'created_at'])
                ->map(fn ($op) => ['id' => $op->id, 'title' => $op->title, 'status' => $op->status, 'actor' => $op->actor, 'ago' => Present::ago($op->created_at)])->all(),
            'sessions' => DB::table('console_sessions')->where('organization_id', Org::id())->where('node_id', $nodeId)->orderByDesc('opened_at')->limit(10)->get()
                ->map(fn ($c) => ['id' => $c->id, 'as' => $c->as_user, 'by' => $c->opened_by_name, 'ago' => Present::ago($c->opened_at),
                    'open' => $c->closed_at === null, 'ended' => $c->ended_because])->all(),
        ];
    }

    /** @return list<array<string, mixed>> */
    public function provisioning(): array
    {
        return DB::table('operations')->where('organization_id', Org::id())->where('kind', 'provision')->whereIn('status', ['queued', 'running'])->orderByDesc('id')->get(['id', 'title'])
            ->map(fn ($op) => ['id' => $op->id, 'title' => $op->title])->all();
    }

    /**
     * Droplets the provider still bills that no enrolled server stands for.
     *
     * @return list<array<string, mixed>>
     */
    public function disconnected(): array
    {
        $enrolledSlugs = DB::table('nodes')->where('organization_id', Org::id())->pluck('node_id')->map(fn ($n) => strstr($n, '.', true) ?: $n)->all();
        $busySlugs = DB::table('operations')->where('organization_id', Org::id())->whereIn('status', ['queued', 'running'])->pluck('node_id')
            ->map(fn ($n) => strstr((string) $n, '.', true) ?: (string) $n)->filter()->all();
        $out = [];
        foreach (DB::table('resources')->where('organization_id', Org::id())->where('type', 'server')
            ->whereIn('state', ['active', 'orphaned'])->orderBy('slug')->get() as $r) {
            if ($r->external_id === null || in_array($r->slug, $enrolledSlugs, true)) {
                continue;
            }
            $attrs = json_decode((string) ($r->attributes ?? ''), true) ?: [];
            $why = [];
            if (in_array($r->slug, $busySlugs, true)) {
                $why[] = 'An operation is running for this Droplet.';
            }
            if (! empty($attrs['pool'])) {
                $why[] = 'Managed by an autoscale pool.';
            }
            if (($app = $this->archivedOn($r->organization_id, (string) $r->slug)) !== null) {
                $why[] = "Holds retained data for {$app}. Delete that retained data first.";
            }
            $out[] = ['id' => $r->id, 'name' => $r->slug, 'external' => $r->external_id,
                'facts' => implode(' · ', array_filter([ProviderServer::publicAddress($attrs), $attrs['region'] ?? null, $attrs['size'] ?? null, 'still billing'])),
                'blockers' => $why];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public function archives(): array
    {
        return DB::table('site_archives')->where('organization_id', Org::id())->orderBy('name')->get()->map(fn ($a) => [
            'id' => $a->id, 'name' => $a->name, 'directory' => $a->directory,
            'servers' => implode(', ', array_map(fn ($s) => strstr((string) $s, '.', true) ?: (string) $s, json_decode((string) $a->servers, true) ?: [])),
            'database' => $a->database_name,
        ])->all();
    }

    /** The cloud account's email, in brackets, for the page's introduction; empty when none. */
    public function accountNote(): string
    {
        $email = DB::table('cloud_credentials')->where('organization_id', Org::id())->whereNull('revoked_at')->value('account_email');

        return $email ? " ({$email})" : '';
    }

    /** @return list<array{id: int, name: string, type: string, default: bool}> */
    public function newKeys(): array
    {
        return DB::table('ssh_keys')->where('organization_id', Org::id())->where('name', 'not like', '% (removed)')->orderBy('id')->get(['id', 'name', 'type', 'install_on_new_servers'])
            ->map(fn ($k) => ['id' => (int) $k->id, 'name' => $k->name, 'type' => $k->type, 'default' => (bool) $k->install_on_new_servers])->all();
    }

    /**
     * Each connected provider that creates servers, with its regions and priced sizes.
     *
     * @return array<string, array<string, mixed>>
     */
    public function catalogs(): array
    {
        $out = [];
        foreach (app(Providers::class)->connected(Org::id()) as $p) {
            if ($p->method() === 'api') {
                $c = $p->catalog();
                $out[$p->id()] = ['label' => $p->label(), 'region' => $c->defaultRegion, 'size' => array_keys($c->sizes)[0] ?? '', 'regions' => $c->regions,
                    'sizes' => array_map(Catalog::sizeLabel(...), $c->sizes),
                    'billed' => ! $p instanceof SelfHosted,
                    'capacity' => $p instanceof SelfHosted ? $p->capacity(Org::id()) : null,
                    'warning' => $p instanceof SelfHosted ? $p->warning(Org::id()) : null];
            }
        }

        return $out;
    }

    /**
     * What each button on a server's card does, for its tooltip. A button that
     * is disabled says why instead, so the reason is on the button itself.
     *
     * @param  list<string>  $blockers
     * @param  list<string>  $destroyBlockers
     * @return array<string, string>
     */
    private function tips(bool $connected, bool $busy, array $blockers, array $destroyBlockers, ?string $agent): array
    {
        $needsAgent = ! $connected
            ? 'Not connected. The agent is offline, so this server cannot be commanded.'
            : ($busy ? 'An operation is already running on this server. Wait for it to finish.' : null);
        $why = fn (?string $reason, string $does) => $reason === null ? $does : "Unavailable: {$reason}";
        $held = $blockers ? implode(' ', $blockers) : null;

        return [
            'repair' => $why($needsAgent, 'Re-apply the base server software (nginx, PHP-FPM, Composer, git, supervisor) and fix anything that has drifted. Steps already in place change nothing, and your apps and data are not touched.'),
            'disconnect' => $why($held, 'Stop managing this server: its agent is uninstalled and its certificate revoked. The machine keeps running and billing.'),
            'destroy' => $why($destroyBlockers ? implode(' ', array_unique($destroyBlockers)) : null, 'Delete this Droplet in DigitalOcean and stop its billing. This cannot be undone.'),
            'upgrade' => $why($needsAgent, 'Update the agent on this server to the current build. It restarts and reconnects; sites keep serving.'),
            'current' => 'The agent already runs the current build'.($agent ? " ({$agent})." : '.'),
            'rotate' => $why($needsAgent, 'Make a new sudo password and set it on this server. The old one stops working.'),
            'keys' => $why($needsAgent, 'Install this SSH key on the server, or take it off.'),
            'removeStale' => $why($held, 'Forget this record and revoke its certificate. The Droplet is already gone in DigitalOcean, so nothing is sent to the machine.'),
        ];
    }

    /**
     * Where an application's nginx upload limit and its server's PHP limit
     * disagree: the smaller of the two is what an upload can actually be.
     *
     * @return array<string, list<string>> branch => messages
     */
    private function phpWarnings(string $nodeId): array
    {
        $out = [];
        foreach (Projects::visibleSites()->where('node_id', $nodeId)->get() as $site) {
            $version = Sites::phpVersion($site);
            $php = PhpSettings::uploadLimitMb(PhpSettings::for($nodeId, $version));
            $web = app(Sites::class)->nginx($site)['upload_mb'];
            if (abs($php - $web) > 0.01) {
                $out[$version][] = $site->name.': nginx accepts '.$web.' MB, PHP accepts '.rtrim(rtrim(number_format($php, 1), '0'), '.').' MB. The smaller one wins.';
            }
        }

        return $out;
    }

    /**
     * What a server's login user shows: its name, how to reach it, and each
     * of the organisation's keys with where it stands on this server.
     *
     * @return array<string, mixed>
     */
    private function access(string $organizationId, string $nodeId, ?string $ip): array
    {
        $state = DB::table('server_ssh_keys')->where('node_id', $nodeId)->pluck('state', 'ssh_key_id');
        $user = AdminAccess::userFor($nodeId);
        $address = $ip ?? NewApplications::publicIp($organizationId, $nodeId);

        return [
            'user' => $user,
            'ready' => DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $nodeId)->whereNotNull('admin_user')->exists(),
            'ssh' => $address !== null ? "ssh {$user}@{$address}" : null,
            'hardened' => (bool) DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $nodeId)->value('ssh_hardened'),
            'hardenBlock' => AdminAccess::hardenBlock($nodeId),
            'firewall' => Firewall::settings($nodeId),
            'tailnet' => Tailscale::info($nodeId),
            'tailscaleKey' => DB::table('cloud_credentials')->where('organization_id', $organizationId)->where('provider', Tailscale::PROVIDER)->whereNull('revoked_at')->exists(),
            'keys' => DB::table('ssh_keys')->where('organization_id', $organizationId)->orderBy('id')->get(['id', 'name', 'type', 'fingerprint'])
                ->filter(fn ($k) => ! str_ends_with((string) $k->name, ' (removed)') || isset($state[$k->id]))
                ->map(fn ($k) => ['id' => (int) $k->id, 'name' => $k->name, 'type' => $k->type, 'state' => $state[$k->id] ?? 'none'])->values()->all(),
        ];
    }

    /**
     * The chart bucket a sample falls in: whole seconds since the epoch, divided by the bucket length (one ? binding).
     * The stored time is read as it is, UTC, with no time zone applied, on both databases.
     */
    private function bucketSql(): string
    {
        return DB::connection()->getDriverName() === 'mysql'
            ? "CAST(TIMESTAMPDIFF(SECOND, '1970-01-01 00:00:00', collected_at) / ? AS SIGNED)"
            : "CAST(strftime('%s', collected_at) / ? AS INTEGER)";
    }

    /** The larger of two values: SQLite's two-argument MAX is GREATEST on MySQL. */
    private function greatestSql(string $a, string $b): string
    {
        return (DB::connection()->getDriverName() === 'mysql' ? 'GREATEST' : 'MAX')."({$a}, {$b})";
    }
}
