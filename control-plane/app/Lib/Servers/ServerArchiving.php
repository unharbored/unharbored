<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Providers\Incus\IncusHost;
use App\Lib\Sites\SiteArchived;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Archiving a server or a database cluster, the same idea as archiving an application (Sites\Archiving): Unharbored
 * stops managing it, nothing is sent to the machine or the provider, and it keeps running. Restoring is a record only.
 *
 * One source of truth for each: `nodes.archived_at` for a server (its provider-made `server` resource is not flagged)
 * and `resources.archived_at` for a database cluster. A self-run cluster is a server too, so the two always change
 * together: archiving either archives both, restoring either restores both.
 *
 * Archiving a server archives its applications (and their previews) with it, all with the same `archived_at`, and
 * restoring it restores only those: an application archived on its own earlier stays archived. It is refused while an
 * application on another server, or an autoscale pool, a preview or an Incus host, depends on it.
 * Archiving a cluster is refused while any application that is not archived still uses it.
 *
 * Writes are refused in Operations::create (nodeId) and the `site-active` middleware (route parameters node and
 * cluster); the background commands filter `archived_at` where they pick their servers.
 */
final class ServerArchiving
{
    public function __construct(private readonly AuditLog $audit) {}

    /** @throws InvalidArgumentException when it cannot be archived (the message says what to do first) */
    public function archiveServer(string $organizationId, string $nodeId, Actor $actor): void
    {
        $node = $this->node($organizationId, $nodeId);
        if ($node->archived_at !== null) {
            return;
        }
        $slug = self::slug($nodeId);
        $this->assertCanArchive($node, $slug);

        $at = now();
        $apps = DB::table('sites')->where('organization_id', $organizationId)->where('node_id', $nodeId)->whereNull('parent_site_id')->whereNull('archived_at')->pluck('id')->all();
        $clusters = $this->clustersOn($organizationId, $slug)->whereNull('archived_at')->all();
        DB::transaction(function () use ($organizationId, $nodeId, $at, $apps, $clusters): void {
            DB::table('nodes')->where('organization_id', $organizationId)->where('node_id', $nodeId)->update(['archived_at' => $at, 'updated_at' => $at]);
            DB::table('sites')->whereNull('archived_at')->where(fn ($q) => $q->whereIn('id', $apps)->orWhereIn('parent_site_id', $apps))
                ->update(['archived_at' => $at, 'updated_at' => $at]);
            DB::table('resources')->whereIn('id', array_column($clusters, 'id'))->update(['archived_at' => $at, 'updated_at' => $at]);
        });

        $names = DB::table('sites')->whereIn('id', $apps)->orderBy('name')->pluck('name')->all();
        $this->audit->record($organizationId, $actor, 'server.archived', resourceType: 'server', resourceId: $nodeId, metadata: ['applications' => $names]);
        foreach ($clusters as $cluster) {
            $this->audit->record($organizationId, $actor, 'database_cluster.archived', resourceType: 'database_cluster', resourceId: $cluster->slug, metadata: ['server' => $slug]);
        }
    }

    /** Brings back the server and what was archived with it, and nothing on the machine changes. */
    public function restoreServer(string $organizationId, string $nodeId, Actor $actor): void
    {
        $node = $this->node($organizationId, $nodeId);
        if ($node->archived_at === null) {
            return;
        }
        $at = $node->archived_at;
        $slug = self::slug($nodeId);
        $clusters = $this->clustersOn($organizationId, $slug)->where('archived_at', $at)->all();
        DB::transaction(function () use ($organizationId, $nodeId, $at, $clusters): void {
            DB::table('nodes')->where('organization_id', $organizationId)->where('node_id', $nodeId)->update(['archived_at' => null, 'updated_at' => now()]);
            DB::table('sites')->where('organization_id', $organizationId)->where('archived_at', $at)
                ->where(fn ($q) => $q->where('node_id', $nodeId)->orWhereIn('parent_site_id', DB::table('sites')->where('node_id', $nodeId)->select('id')))
                ->update(['archived_at' => null, 'updated_at' => now()]);
            DB::table('resources')->whereIn('id', array_column($clusters, 'id'))->update(['archived_at' => null, 'updated_at' => now()]);
        });
        $this->audit->record($organizationId, $actor, 'server.restored', resourceType: 'server', resourceId: $nodeId);
        foreach ($clusters as $cluster) {
            $this->audit->record($organizationId, $actor, 'database_cluster.restored', resourceType: 'database_cluster', resourceId: $cluster->slug, metadata: ['server' => $slug]);
        }
    }

    /** @throws InvalidArgumentException */
    public function archiveCluster(string $organizationId, int $clusterId, Actor $actor): void
    {
        $cluster = $this->cluster($organizationId, $clusterId);
        if ($cluster->archived_at !== null) {
            return;
        }
        if ($cluster->state !== 'active') {
            throw new InvalidArgumentException("{$cluster->slug} is not up ({$cluster->state}), so it cannot be archived. Destroy it, or wait for it.");
        }
        if (($users = $this->liveSitesUsing($cluster)) !== []) {
            throw new InvalidArgumentException("{$cluster->slug} is still used by ".implode(', ', $users).'. Archive or detach them first.');
        }
        if (($node = $this->serverOf($cluster)) !== null) {
            $this->archiveServer($organizationId, $node->node_id, $actor);

            return;
        }
        DB::table('resources')->where('id', $cluster->id)->update(['archived_at' => now(), 'updated_at' => now()]);
        $this->audit->record($organizationId, $actor, 'database_cluster.archived', resourceType: 'database_cluster', resourceId: $cluster->slug);
    }

    public function restoreCluster(string $organizationId, int $clusterId, Actor $actor): void
    {
        $cluster = $this->cluster($organizationId, $clusterId);
        if ($cluster->archived_at === null) {
            return;
        }
        $node = $this->serverOf($cluster);
        if ($node !== null && $node->archived_at !== null) {
            $this->restoreServer($organizationId, $node->node_id, $actor);
        }
        if (DB::table('resources')->where('id', $cluster->id)->whereNotNull('archived_at')->exists()) {
            DB::table('resources')->where('id', $cluster->id)->update(['archived_at' => null, 'updated_at' => now()]);
            $this->audit->record($organizationId, $actor, 'database_cluster.restored', resourceType: 'database_cluster', resourceId: $cluster->slug);
        }
    }

    public static function isNodeArchived(string $nodeId): bool
    {
        return DB::table('nodes')->where('node_id', $nodeId)->whereNotNull('archived_at')->exists();
    }

    /** @throws SiteArchived */
    public static function refuseIfNodeArchived(string $nodeId): void
    {
        if (self::isNodeArchived($nodeId)) {
            throw new SiteArchived(self::slug($nodeId));
        }
    }

    /** @throws SiteArchived */
    public static function refuseIfClusterArchived(int $clusterId): void
    {
        $slug = DB::table('resources')->where('id', $clusterId)->whereNotNull('archived_at')->value('slug');
        if ($slug !== null) {
            throw new SiteArchived((string) $slug);
        }
    }

    /**
     * Whether an application brought back under management would have an archived server or cluster under it.
     *
     * @throws InvalidArgumentException
     */
    public static function assertCanHost(object $site): void
    {
        if (self::isNodeArchived((string) $site->node_id)) {
            throw new InvalidArgumentException(self::slug((string) $site->node_id).' is archived, and so is where '.$site->name.' runs. Restore the server first.');
        }
        foreach (array_filter([$site->database_cluster_id ?? null, $site->cache_cluster_id ?? null]) as $id) {
            if (($slug = DB::table('resources')->where('id', $id)->whereNotNull('archived_at')->value('slug')) !== null) {
                throw new InvalidArgumentException("{$slug} is archived, and {$site->name} uses it. Restore the database cluster first.");
            }
        }
    }

    private static function slug(string $nodeId): string
    {
        return strstr($nodeId, '.', true) ?: $nodeId;
    }

    private function assertCanArchive(object $node, string $slug): void
    {
        $nodeId = (string) $node->node_id;
        $why = [];
        if (($node->role ?? null) === 'ingress' || ($node->role ?? null) === IncusHost::ROLE) {
            $why[] = "{$slug} is part of an Incus host. Archiving those is not supported: delete it instead.";
        }
        if (DB::table('site_nodes')->where('node_id', $nodeId)->whereNotNull('autoscale_pool_id')->exists()) {
            $why[] = "{$slug} belongs to an autoscale pool. Stop autoscaling that application instead.";
        }
        if (DB::table('operations')->where('node_id', $nodeId)->whereIn('status', ['queued', 'running'])->exists()) {
            $why[] = "An operation is running on {$slug}. Wait for it to finish.";
        }
        $here = DB::table('sites')->where('node_id', $nodeId)->whereNull('parent_site_id')->pluck('id')->all();
        $elsewhere = DB::table('sites')->whereNull('archived_at')->whereNull('parent_site_id')->whereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id'))
            ->where('node_id', '!=', $nodeId)->pluck('name')->all();
        if ($elsewhere !== []) {
            $why[] = "{$slug} is also a server of ".implode(', ', $elsewhere).', which run on others. Remove it from them first.';
        }
        $previews = DB::table('sites')->whereNull('archived_at')->whereNotNull('parent_site_id')->where('node_id', $nodeId)->whereNotIn('parent_site_id', $here)->pluck('name')->all();
        if ($previews !== []) {
            $why[] = "Previews of other applications run on {$slug}: ".implode(', ', $previews).'. Delete them first.';
        }
        foreach ($this->clustersOn((string) $node->organization_id, $slug)->whereNull('archived_at')->all() as $cluster) {
            if (($users = $this->liveSitesUsing($cluster, $nodeId)) !== []) {
                $why[] = "{$slug} is the database server {$cluster->slug}, used by ".implode(', ', $users).' on other servers. Archive or detach them first.';
            }
        }
        if ($why !== []) {
            throw new InvalidArgumentException(implode(' ', $why));
        }
    }

    /**
     * Applications that are not archived and use the cluster, other than those that run on $exceptNode (they go with it).
     *
     * @return list<string>
     */
    private function liveSitesUsing(object $cluster, ?string $exceptNode = null): array
    {
        return DB::table('sites')->whereNull('archived_at')->whereNull('parent_site_id')
            ->where(fn ($q) => $q->where('database_cluster_id', $cluster->id)->orWhere('cache_cluster_id', $cluster->id))
            ->when($exceptNode !== null, fn ($q) => $q->where('node_id', '!=', $exceptNode))->orderBy('name')->pluck('name')->all();
    }

    /** The self-run clusters whose database server is this machine. @return \Illuminate\Support\Collection<int, object> */
    private function clustersOn(string $organizationId, string $slug)
    {
        return DB::table('resources')->where('organization_id', $organizationId)->where('type', 'database_cluster')->where('state', '!=', 'deleted')->get()
            ->filter(fn ($c) => (json_decode((string) $c->attributes, true)['server'] ?? null) === $slug)->values();
    }

    private function serverOf(object $cluster): ?object
    {
        $server = json_decode((string) $cluster->attributes, true)['server'] ?? null;

        return $server === null ? null : DB::table('nodes')->where('organization_id', $cluster->organization_id)->where('node_id', 'like', $server.'.%')->first();
    }

    private function node(string $organizationId, string $nodeId): object
    {
        return DB::table('nodes')->where('organization_id', $organizationId)->where('node_id', $nodeId)->first() ?? abort(404);
    }

    private function cluster(string $organizationId, int $clusterId): object
    {
        return DB::table('resources')->where('organization_id', $organizationId)->where('id', $clusterId)->where('type', 'database_cluster')->where('state', '!=', 'deleted')->first() ?? abort(404);
    }
}
