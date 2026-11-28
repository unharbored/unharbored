<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Cloud\DatabaseServers;
use App\Lib\Providers\Incus\IncusHost;
use App\Lib\Providers\Providers;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Sites\SiteBackups;
use App\Lib\Sites\Sites;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Everything that lives under a server, itemised, for the one "Delete server" flow.
 *
 * Read-only. Each item says what deleting it does, whether that stops or keeps a bill, and the choices that make sense.
 * Blockers are things the person must resolve first: they are never deleted for them. The server's page shows this list
 * and the server_delete operation runs it, calling of() again when it runs so the list it acts on is the current one.
 *
 * Covered: applications (archived ones too) with their previews, load balancer, autoscale pool and database, retained
 * data of deleted applications, the database cluster that is this server, the MySQL/PostgreSQL/Valkey installed on it.
 * Not itemised, because they have no life apart from the server or the application: uploads on its disk, scheduled jobs,
 * certificates, firewall rules, backup records. Mentioned in the text of the item they belong to.
 */
final class Underneath
{
    /**
     * @param  ?int  $exceptOperation  the server_delete operation that is asking, so it does not count as "busy"
     * @return array{server: string, provider: ?string, providerMade: bool, items: list<array<string, mixed>>, blockers: list<string>, summary: array{server: string, stops: list<string>, keeps: list<string>}}
     */
    public static function of(string $nodeId, ?int $exceptOperation = null): array
    {
        $node = DB::table('nodes')->where('node_id', $nodeId)->first()
            ?? throw new InvalidArgumentException("No server {$nodeId}.");
        $org = (string) $node->organization_id;
        $slug = strstr($nodeId, '.', true) ?: $nodeId;
        $resource = DB::table('resources')->where('organization_id', $org)->where('type', 'server')->where('slug', $slug)->first();
        $attributes = json_decode((string) ($resource->attributes ?? ''), true) ?: [];
        $providerMade = $resource !== null && $resource->external_id !== null && $resource->state !== 'deleted';
        $providerLabel = $providerMade ? self::providerLabel($resource->provider ?? null) : null;

        $items = [];
        $blockers = [];
        $stops = [];
        $keeps = [];

        if (DB::table('operations')->where('node_id', $nodeId)->whereIn('status', ['queued', 'running'])
            ->when($exceptOperation !== null, fn ($q) => $q->where('id', '!=', $exceptOperation))->exists()) {
            $blockers[] = "An operation is running on {$slug}. Wait for it to finish.";
        }
        if (($node->role ?? null) === 'ingress') {
            $blockers[] = 'The IPv4 ingress belongs to the Incus host; it goes when the host is deleted.';
        }
        if (($node->role ?? null) === IncusHost::ROLE && ($hosted = IncusHost::servers($org)) !== []) {
            $blockers[] = 'These servers live on this Incus host: '.implode(', ', $hosted).'. Delete them first.';
        }
        $cluster = null;
        if (isset($attributes['database_cluster'])) {
            $cluster = DB::table('resources')->where('organization_id', $org)->where('type', 'database_cluster')
                ->where('slug', $attributes['database_cluster'])->where('state', '!=', 'deleted')->first();
        }
        if (isset($attributes['pool']) && DB::table('resources')->where('organization_id', $org)->where('id', $attributes['pool'])->where('state', '!=', 'deleted')->exists()) {
            $blockers[] = "{$slug} belongs to an autoscale pool. Stop autoscaling that application instead.";
        }

        // Applications, archived ones included.
        $sites = DB::table('sites')->whereNull('parent_site_id')->where('organization_id', $org)
            ->where(fn ($q) => $q->where('node_id', $nodeId)->orWhereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id')))
            ->orderBy('name')->get();
        $backupsOn = $sites->isNotEmpty() && app(SiteBackups::class)->enabled();
        foreach ($sites as $site) {
            if (DB::table('site_nodes')->where('site_id', $site->id)->where('node_id', $nodeId)->whereNotNull('autoscale_pool_id')->exists()) {
                $blockers[] = "{$slug} is a server of {$site->name}'s autoscale pool. Stop autoscaling {$site->name} instead.";

                continue;
            }
            if (DB::table('operations')->where('site_id', $site->id)->whereIn('status', ['queued', 'running'])
                ->when($exceptOperation !== null, fn ($q) => $q->where('id', '!=', $exceptOperation))->exists()) {
                $blockers[] = "An operation is running for {$site->name}. Wait for it to finish.";
            }
            $items[] = self::application($site, $nodeId, $slug, $cluster, $backupsOn, $stops, $keeps);
        }

        // Retained data of deleted applications.
        foreach (DB::table('site_archives')->where('organization_id', $org)->orderBy('name')->get() as $archive) {
            $servers = [(string) $archive->node_id, ...(json_decode((string) $archive->servers, true) ?: [])];
            $here = in_array($slug, array_map(fn ($s) => strstr($s, '.', true) ?: $s, $servers), true);
            if (! $here) {
                if ($cluster !== null && (int) $archive->database_cluster_id === (int) $cluster->id) {
                    $blockers[] = "Retained data for {$archive->name} (kept on another server) has its database on {$cluster->slug}. Delete that retained data first.";
                }

                continue;
            }
            $items[] = self::archive($archive, $stops, $keeps);
        }

        // The database server this machine is.
        if ($cluster !== null) {
            $items[] = self::cluster($cluster, $sites, $nodeId, $blockers);
        }

        // Database and cache engines installed on the machine.
        foreach (DB::table('server_services')->where('node_id', $nodeId)->where('state', 'installed')->pluck('service') as $service) {
            $users = $sites->filter(fn ($s) => in_array("local:{$service}", [$s->database_mode ?? null, $s->cache_mode ?? null], true))->pluck('name')->all();
            $items[] = [
                'key' => "service:{$service}", 'type' => 'local_service', 'name' => LocalServices::label($service),
                'does' => $providerMade
                    ? 'Installed on this server; it and everything in it goes with the server.'
                    : 'Installed on this machine; it and what is in it stay on the machine, only Unharbored stops managing it.',
                'detail' => $users === [] ? 'No application uses it.' : 'Used by '.implode(', ', $users).'; their databases are erased with them.',
                'costs' => false, 'costNote' => null, 'choices' => [],
            ];
        }

        $server = $providerMade
            ? "{$slug} is destroyed at {$providerLabel}."
            : "{$slug} is disconnected: its agent is uninstalled and its certificate revoked. The machine keeps running.";
        if ($node->archived_at !== null) {
            $server .= ' It is archived: it is brought back under management first (a record only) so that this can run.';
        }
        if ($providerMade) {
            $stops[] = "{$slug} at {$providerLabel} stops billing.";
        } else {
            $keeps[] = "{$slug} keeps running and billing wherever it is hosted; it is not destroyed.";
        }

        return [
            'server' => $slug, 'provider' => $providerLabel, 'providerMade' => $providerMade,
            'items' => $items, 'blockers' => array_values(array_unique($blockers)),
            'summary' => ['server' => $server, 'stops' => array_values(array_unique($stops)), 'keeps' => array_values(array_unique($keeps))],
        ];
    }

    /** @param list<string> $stops @param list<string> $keeps @return array<string, mixed> */
    private static function application(object $site, string $nodeId, string $slug, ?object $cluster, bool $backupsOn, array &$stops, array &$keeps): array
    {
        $also = array_values(array_filter(array_map(fn ($n) => strstr($n, '.', true) ?: $n, array_diff(Sites::nodes($site), [$nodeId]))));
        $previews = DB::table('sites')->where('parent_site_id', $site->id)->orderBy('name')->pluck('name')->all();
        $does = ["Erases its files and uploads on {$slug}, stops its web server, workers and schedule, and removes its certificate, secrets and record."];
        $costNote = [];
        if ($site->archived_at !== null) {
            array_unshift($does, 'Archived: it is not managed by Unharbored but still runs on this server. It is brought back under management only to be deleted.');
        }
        if ($previews !== []) {
            $does[] = 'Its previews are deleted first: '.implode(', ', $previews).'.';
        }
        if ($also !== []) {
            $does[] = 'It also runs on '.implode(', ', $also).'; deleting it removes it there too.';
        }
        if ($site->database_mode === 'cluster' && $site->database_cluster_id !== null) {
            $name = DB::table('resources')->where('id', $site->database_cluster_id)->value('slug');
            if ($cluster !== null && (int) $cluster->id === (int) $site->database_cluster_id) {
                $does[] = "Its database goes with the database server {$cluster->slug}.";
            } elseif ($name !== null) {
                $does[] = "Its database is dropped from {$name}; that cluster stays.";
                $keeps[] = "{$name} keeps running and billing.";
                $costNote[] = "{$name} keeps billing";
            }
        } elseif (str_starts_with((string) $site->database_mode, 'local:')) {
            $does[] = 'Its database on this server is erased with it.';
        }
        if ($site->load_balancer_id !== null) {
            $does[] = 'Its load balancer is deleted.';
            $stops[] = "{$site->name}'s load balancer stops billing.";
            $costNote[] = 'load balancer stops billing';
        }
        if ($site->autoscale_pool_id !== null) {
            $does[] = 'Its autoscale pool and the servers in it are deleted.';
            $stops[] = "{$site->name}'s autoscale pool servers stop billing.";
        }
        if ($site->bucket_id !== null) {
            $adopted = (json_decode((string) DB::table('resources')->where('id', $site->bucket_id)->value('attributes'), true)['adopted'] ?? false) === true;
            if ($adopted) {
                $does[] = 'Its bucket is yours already: it and its files are left as they are.';
            } else {
                $does[] = 'Its Spaces bucket, every upload in it and its key are deleted.';
                $stops[] = "{$site->name}'s Spaces bucket stops billing.";
            }
        }

        $choices = [['value' => 'erase', 'label' => 'Delete it and erase its data']];
        if ($backupsOn) {
            array_unshift($choices, ['value' => 'backup', 'label' => 'Back up its database to the backup bucket first, then delete it and erase its data']);
        }
        if ($site->node_id !== $nodeId && $also !== []) {
            // Only a secondary server of the application: it can just stop using this one.
            $choices[] = ['value' => 'detach', 'label' => "Keep the application; only remove it from {$slug}"];
        }

        return [
            'key' => "app:{$site->id}", 'type' => $site->archived_at !== null ? 'archived_application' : 'application', 'name' => $site->name,
            'does' => implode(' ', $does),
            'detail' => 'Deleting an application and keeping its files on the server makes no sense when the server is deleted, so the choice is to erase it or to take its database backup first.',
            'costs' => $costNote !== [], 'costNote' => $costNote === [] ? null : implode('; ', $costNote),
            'choices' => $choices,
        ];
    }

    /** @param list<string> $stops @param list<string> $keeps @return array<string, mixed> */
    private static function archive(object $archive, array &$stops, array &$keeps): array
    {
        $does = "Permanently deletes the kept files at {$archive->directory} and the stored secrets of {$archive->name}.";
        $note = null;
        if ($archive->database_cluster_id !== null) {
            $name = DB::table('resources')->where('id', $archive->database_cluster_id)->value('slug');
            $does .= " Its database is dropped from {$name}.";
            $keeps[] = "{$name} keeps running and billing.";
            $note = "{$name} keeps billing";
        }
        if ($archive->bucket_id !== null) {
            $does .= ' Its Spaces bucket is kept.';
            $keeps[] = "{$archive->name}'s Spaces bucket keeps billing until you delete it at the provider.";
            $note = trim(($note ? "{$note}; " : '').'its bucket keeps billing');
        }

        return [
            'key' => "archive:{$archive->id}", 'type' => 'retained_data', 'name' => $archive->name,
            'does' => $does, 'detail' => 'Data kept from an application that was deleted without erasing it. The server is going away, so it goes too.',
            'costs' => $note !== null, 'costNote' => $note,
            'choices' => [['value' => 'erase', 'label' => 'Erase it']],
        ];
    }

    /** @param list<string> $blockers @return array<string, mixed> */
    private static function cluster(object $cluster, $sites, string $nodeId, array &$blockers): array
    {
        $own = DatabaseServers::isOwnServer(ResourceRecord::fromRow($cluster));
        $local = $sites->pluck('id')->all();
        foreach (DB::table('sites')->where('database_cluster_id', $cluster->id)->whereNotIn('id', $local)->get(['name', 'node_id']) as $user) {
            $blockers[] = "{$user->name} on ".(strstr((string) $user->node_id, '.', true) ?: $user->node_id)." uses the database on {$cluster->slug}. Move it to another database or delete it first.";
        }

        return [
            'key' => "cluster:{$cluster->id}", 'type' => 'database_server', 'name' => $cluster->slug,
            'does' => $own
                ? 'This server is the database server. Unharbored forgets the cluster and removes its agent; the databases on the machine are left in place.'
                : 'This server is the database server. Every database on it is destroyed with it.',
            'detail' => 'No dump is taken for you here. Back up the databases you want to keep before you delete this.',
            'costs' => false, 'costNote' => null,
            'choices' => [['value' => 'erase', 'label' => $own ? 'Forget the cluster' : 'Destroy it and its databases']],
        ];
    }

    private static function providerLabel(?string $provider): string
    {
        $providers = app(Providers::class);

        return $providers->has((string) ($provider ?? 'digitalocean')) ? $providers->get((string) ($provider ?? 'digitalocean'))->label() : (string) $provider;
    }
}
