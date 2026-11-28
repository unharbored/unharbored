<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\DigitalOcean\Client;
use App\Lib\DigitalOcean\Exceptions\RequestFailed;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\ProviderScope;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * A DigitalOcean Droplet Autoscale Pool that adds web servers to a site.
 *
 * The site keeps its fixed primary, which builds, migrates and runs the
 * scheduler. The pool's Droplets are web capacity only: each boots from
 * stock Ubuntu with the agent in its user_data, enrolls with the pool's
 * token (EnrollmentService explains why one token is safe), and is then
 * joined to the site: stack, the current release's build from the site's
 * bucket, and only then the balancer and the database firewalls. Nothing
 * on a pool member is built there (ADR 0006).
 *
 * DigitalOcean decides when members come and go. reconcile() notices the
 * ones it has removed.
 */
final class AutoscalePools
{
    /** The public half of a key nobody holds. DigitalOcean requires a key on pool Droplets; this one opens nothing. */
    public const KEY_NAME = 'cloud-manager-pool (no private key exists)';

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ResourceManager $resources,
        private readonly AuditLog $audit,
        private readonly EnrollmentService $enrollment,
        private readonly CloudInit $cloudInit,
        private readonly ServerProvisioner $droplets,
    ) {}

    /**
     * @param  array{min: int, max: int, size: string, cpu: float}  $spec
     */
    public function create(stdClass $site, array $spec): ResourceRecord
    {
        ProviderScope::assertDigitalOcean($site, 'Autoscale pools');
        // The balancer spreads traffic to new members, the bucket carries each
        // build to them, and every member must see the same data.
        foreach (['load_balancer_id' => 'a load balancer', 'bucket_id' => 'a Spaces bucket (new servers fetch each build from it)', 'database_cluster_id' => 'a managed database'] as $column => $what) {
            if (($site->{$column} ?? null) === null) {
                throw new InvalidArgumentException("{$site->name} needs {$what} before it can autoscale: a server DigitalOcean adds must find everything it needs off the server.");
            }
        }
        $region = $this->region($site);
        $slug = "{$site->name}-pool";
        $resource = $this->resources->declare($site->organization_id, 'autoscale_pool', $slug, ['region' => $region, 'site_id' => $site->id] + $spec);
        if ($resource->externalId !== null) {
            return $resource;
        }

        $actor = Actor::system('control-plane');
        $client = $this->client($site, "Creating an autoscale pool for {$site->name}");
        $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: "Creating an autoscale pool for {$site->name}", actor: $actor);
        DB::table('enrollment_grants')->where('autoscale_pool_id', $resource->id)->delete();
        $token = $this->enrollment->issuePoolToken($site->organization_id, $resource->id, $slug, $actor);
        try {
            $pool = $client->post('droplets/autoscale', [
                'name' => "cm-{$slug}",
                'config' => ['min_instances' => $spec['min'], 'max_instances' => $spec['max'],
                    'target_cpu_utilization' => $spec['cpu'], 'cooldown_minutes' => 5],
                'droplet_template' => [
                    'region' => $region,
                    'size' => $spec['size'],
                    // The base image when there is one: the stack is already on it.
                    'image' => app(BaseImages::class)->imageFor($site->organization_id, $region),
                    // Strings: the pool API is strictly typed, and an integer id
                    // is refused as a malformed body.
                    'ssh_keys' => [(string) $this->platformKey($client)],
                    'tags' => $this->droplets->tags($site->organization_id, $slug),
                    'user_data' => $this->cloudInit->build(
                        nodeId: "pool:{$slug}",
                        enrollmentToken: $token,
                        caCertificatePem: $this->enrollment->serverTrustFor($site->organization_id),
                        hostname: null,
                    ),
                    // Scaling on CPU reads DigitalOcean's own agent.
                    'with_droplet_agent' => true,
                ],
            ], $resource->idempotencyKey)['autoscale_pool'] ?? [];
        } catch (RequestFailed $e) {
            $uncertain = $e->status === null || $e->status >= 500;
            $this->resources->transition($resource, $uncertain ? ResourceState::Orphaned : ResourceState::Failed,
                reason: $uncertain ? 'Create did not return a usable answer; a pool may exist' : 'DigitalOcean refused the create',
                error: $e->getMessage(), actor: $actor);

            throw new RuntimeException('Autoscale pool creation failed: '.$e->getMessage(), previous: $e);
        }

        $resource = $this->resources->recordExternalId($resource, (string) ($pool['id'] ?? throw new RuntimeException('DigitalOcean returned no pool id.')));
        $resource = $this->resources->transition($resource, ResourceState::Active, reason: 'Autoscale pool created', actor: $actor);
        DB::table('sites')->where('id', $site->id)->update(['autoscale_pool_id' => $resource->id, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, $actor, 'site.autoscale_pool_created', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['pool' => $resource->externalId] + $spec);

        return $resource;
    }

    /**
     * New limits. DigitalOcean replaces the configuration whole.
     *
     * A scheduled change moves only the minimum DigitalOcean is given
     * (live_min); the minimum someone set stays, for outside the windows.
     */
    public function resize(stdClass $site, int $min, int $max, bool $scheduled = false): void
    {
        $pool = $this->of($site);
        $client = $this->client($site, "Resizing the autoscale pool for {$site->name}");
        $live = $client->get("droplets/autoscale/{$pool->externalId}")['autoscale_pool'] ?? [];
        $client->put("droplets/autoscale/{$pool->externalId}", [
            'name' => $live['name'],
            'config' => ['min_instances' => $min, 'max_instances' => $max] + ($live['config'] ?? []),
            'droplet_template' => $live['droplet_template'],
        ]);
        $this->resources->mergeAttributes($pool, $scheduled ? ['live_min' => $min] : ['min' => $min, 'max' => $max, 'live_min' => $min]);
    }

    /** Which days a schedule entry can name. Days are Carbon's: 0 is Sunday. */
    public const DAYS = [
        'every' => [], 'weekdays' => [1, 2, 3, 4, 5], 'weekends' => [0, 6],
        'sun' => [0], 'mon' => [1], 'tue' => [2], 'wed' => [3], 'thu' => [4], 'fri' => [5], 'sat' => [6],
    ];

    /**
     * Keeps at least $min servers from $from to $to o'clock UTC on $days.
     * A window whose end is earlier than its start runs past midnight; one
     * whose start and end are equal runs all day.
     */
    public function addSchedule(stdClass $site, string $days, int $from, int $to, int $min): void
    {
        $pool = $this->of($site);
        if (! array_key_exists($days, self::DAYS) || $from < 0 || $from > 23 || $to < 0 || $to > 23 || $min < 1) {
            throw new InvalidArgumentException('Pick days, hours from 0 to 23, and at least one server.');
        }
        if ($min > (int) $pool->attributes['max']) {
            throw new InvalidArgumentException("At most {$pool->attributes['max']}, the pool's limit. Raise that first.");
        }
        $schedule = $pool->attributes['schedule'] ?? [];
        if (count($schedule) >= 8) {
            throw new InvalidArgumentException('Eight windows at most.');
        }
        $schedule[] = ['days' => $days, 'from' => $from, 'to' => $to, 'min' => $min];
        $this->resources->mergeAttributes($pool, ['schedule' => $schedule]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.autoscale_schedule_added', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: end($schedule));
    }

    public function removeSchedule(stdClass $site, int $index): void
    {
        $pool = $this->of($site);
        $schedule = $pool->attributes['schedule'] ?? [];
        if (! isset($schedule[$index])) {
            return;
        }
        $removed = $schedule[$index];
        unset($schedule[$index]);
        $this->resources->mergeAttributes($pool, ['schedule' => array_values($schedule)]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.autoscale_schedule_removed', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: $removed);
    }

    /**
     * The minimum the pool should have at $now: the largest of the windows
     * open then, or the set minimum outside them, never above the maximum.
     *
     * @param  array<string, mixed>  $attributes  The pool's resource attributes.
     */
    public static function minAt(array $attributes, \DateTimeInterface $now): int
    {
        $now = CarbonImmutable::instance($now)->utc();
        $hour = $now->hour;
        $today = $now->dayOfWeek;
        $open = [];
        foreach ($attributes['schedule'] ?? [] as $w) {
            $days = self::DAYS[$w['days']] ?? [];
            $on = fn (int $day): bool => $days === [] || in_array($day, $days, true);
            $match = match (true) {
                $w['from'] === $w['to'] => $on($today),
                $w['from'] < $w['to'] => $on($today) && $hour >= $w['from'] && $hour < $w['to'],
                // Past midnight: the early hours belong to the day the window started.
                default => ($on($today) && $hour >= $w['from']) || ($on(($today + 6) % 7) && $hour < $w['to']),
            };
            if ($match) {
                $open[] = (int) $w['min'];
            }
        }

        return min($open === [] ? (int) $attributes['min'] : max($open), (int) $attributes['max']);
    }

    /**
     * The minimum DigitalOcean was last given.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function liveMin(array $attributes): int
    {
        return (int) ($attributes['live_min'] ?? $attributes['min']);
    }

    /**
     * The member Droplet that has this public address and has not enrolled,
     * or null. Two calls: the pool's members, and the Droplets carrying the
     * pool's tag with their addresses.
     */
    public function memberAt(int $poolId, string $ip): ?string
    {
        $pool = $this->resources->findById($poolId);
        if ($pool === null || $pool->externalId === null) {
            return null;
        }
        $client = $this->credentials->clientFor($pool->organizationId, "Checking who is enrolling into {$pool->slug}", Actor::system('enrollment'));
        $members = array_map(fn ($m) => (string) $m['droplet_id'], $this->members($client, $pool));
        foreach ($client->paginate('droplets', 'droplets', ['tag_name' => 'resource:'.$pool->slug]) as $droplet) {
            if (! in_array((string) $droplet['id'], $members, true)) {
                continue;
            }
            foreach ($droplet['networks']['v4'] ?? [] as $net) {
                if (($net['type'] ?? null) === 'public' && ($net['ip_address'] ?? null) === $ip) {
                    return (string) $droplet['id'];
                }
            }
        }

        return null;
    }

    public function memberNodeId(int $poolId, string $droplet): string
    {
        $pool = $this->resources->findById($poolId) ?? throw new InvalidArgumentException("No pool {$poolId}.");

        return $this->droplets->nodeId($pool->organizationId, $this->memberSlug($pool, $droplet));
    }

    /** A member has its certificate: record its Droplet and join it to the site. */
    public function enrolled(int $poolId, string $droplet, string $nodeId): void
    {
        $pool = $this->resources->findById($poolId) ?? throw new InvalidArgumentException("No pool {$poolId}.");
        $actor = Actor::system('control-plane');
        $record = $this->resources->declare($pool->organizationId, 'server', $this->memberSlug($pool, $droplet),
            ['region' => $pool->attributes['region'] ?? null, 'size' => $pool->attributes['size'] ?? null, 'pool' => $pool->id]);
        if ($record->state === ResourceState::Pending) {
            $record = $this->resources->transition($record, ResourceState::Provisioning, reason: "Created by autoscale pool {$pool->slug}", actor: $actor);
            $record = $this->resources->recordExternalId($record, $droplet);
            $this->resources->transition($record, ResourceState::Active, reason: 'Enrolled', actor: $actor);
        }
        $site = (int) ($pool->attributes['site_id'] ?? throw new RuntimeException("Pool {$pool->slug} names no site."));
        app(OperationRunner::class)->queuePoolJoin($site, $nodeId);
    }

    /**
     * Servers the pool added that DigitalOcean has since removed, or is
     * removing. They leave the site.
     *
     * @return list<string> node ids
     */
    public function departed(stdClass $site): array
    {
        $pool = $this->of($site);
        $live = [];
        foreach ($this->members($this->client($site, "Listing the members of {$pool->slug}"), $pool) as $m) {
            if (! in_array($m['status'] ?? null, ['deleting', 'off'], true)) {
                $live[] = $this->droplets->nodeId($pool->organizationId, $this->memberSlug($pool, (string) $m['droplet_id']));
            }
        }

        // Joined ones, and ones that enrolled but were removed before joining.
        $known = DB::table('site_nodes')->where('site_id', $site->id)->where('autoscale_pool_id', $pool->id)->pluck('node_id')->all();
        foreach (DB::table('resources')->where('organization_id', $site->organization_id)->where('type', 'server')->where('state', 'active')->get(['slug', 'attributes']) as $r) {
            if ((int) (json_decode((string) $r->attributes, true)['pool'] ?? 0) === $pool->id) {
                $known[] = $this->droplets->nodeId($site->organization_id, $r->slug);
            }
        }

        return array_values(array_diff(array_values(array_unique($known)), $live));
    }

    /**
     * Members that enrolled but whose joining failed more than ten minutes
     * ago, and are still not serving. Joining again is safe: the stack
     * changes nothing the second time, and the release is set up afresh.
     *
     * @return list<string> node ids queued to join again
     */
    public function retryStranded(stdClass $site): array
    {
        $pool = $this->of($site);
        $joined = DB::table('site_nodes')->where('site_id', $site->id)->pluck('node_id')->all();
        $retried = [];
        $members = DB::table('resources')->where('organization_id', $site->organization_id)->where('type', 'server')->where('state', 'active')->get(['slug', 'attributes']);
        foreach ($members as $m) {
            if ((int) (json_decode((string) $m->attributes, true)['pool'] ?? 0) !== $pool->id) {
                continue;
            }
            $node = $this->droplets->nodeId($site->organization_id, $m->slug);
            if (in_array($node, $joined, true) || ! DB::table('nodes')->where('node_id', $node)->exists()) {
                continue;
            }
            $last = DB::table('operations')->where('site_id', $site->id)->whereIn('kind', ['pool_member', 'pool_join'])
                ->where(fn ($q) => $q->where('node_id', $node)->orWhere('meta', 'like', '%"'.$node.'"%'))->orderByDesc('id')->first();
            if ($last !== null && $last->status === 'failed' && $last->finished_at !== null && now()->subMinutes(10)->greaterThan($last->finished_at)) {
                app(OperationRunner::class)->queuePoolJoin($site->id, $node);
                $retried[] = $node;
            }
        }

        return $retried;
    }

    /**
     * Deletes the pool and every Droplet it made. Returns their node ids, so
     * the caller can take them off the site.
     *
     * @return list<string>
     */
    public function destroy(stdClass $site): array
    {
        $pool = $this->of($site);
        $client = $this->client($site, "Removing the autoscale pool for {$site->name}");
        $actor = Actor::system('control-plane');
        $members = array_map(fn ($m) => (string) $m['droplet_id'], $this->members($client, $pool));
        $pool = $this->resources->transition($pool, ResourceState::Deleting, reason: 'Removing the pool', actor: $actor);
        $client->delete("droplets/autoscale/{$pool->externalId}");
        // Whether DigitalOcean takes the members with the pool is not
        // promised, so they are deleted by id: already gone is fine.
        foreach ($members as $droplet) {
            $client->delete("droplets/{$droplet}");
        }
        DB::table('enrollment_grants')->where('autoscale_pool_id', $pool->id)->delete();
        $this->resources->transition($pool, ResourceState::Deleted, reason: 'Pool and its Droplets deleted', actor: $actor);
        DB::table('sites')->where('id', $site->id)->update(['autoscale_pool_id' => null, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, $actor, 'site.autoscale_pool_removed', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['pool' => $pool->externalId, 'droplets' => $members]);

        return array_map(fn ($d) => $this->droplets->nodeId($pool->organizationId, $this->memberSlug($pool, $d)), $members);
    }

    public function of(stdClass $site): ResourceRecord
    {
        return $this->resources->findById((int) ($site->autoscale_pool_id ?? 0))
            ?? throw new InvalidArgumentException("{$site->name} has no autoscale pool.");
    }

    /** @return list<array<string, mixed>> */
    private function members(Client $client, ResourceRecord $pool): array
    {
        return $client->paginate("droplets/autoscale/{$pool->externalId}/members", 'droplets');
    }

    private function memberSlug(ResourceRecord $pool, string $droplet): string
    {
        return preg_replace('/-pool$/', '', $pool->slug)."-{$droplet}";
    }

    /**
     * The id of the account's keyless key, uploading it the first time. The
     * private half is generated, never written anywhere, and dropped here.
     */
    private function platformKey(Client $client): int
    {
        foreach ($client->paginate('account/keys', 'ssh_keys') as $key) {
            if (($key['name'] ?? null) === self::KEY_NAME) {
                return (int) $key['id'];
            }
        }
        $pair = sodium_crypto_sign_keypair();
        $public = sodium_crypto_sign_publickey($pair);
        sodium_memzero($pair);
        $blob = pack('N', 11).'ssh-ed25519'.pack('N', strlen($public)).$public;

        return (int) ($client->post('account/keys', ['name' => self::KEY_NAME, 'public_key' => 'ssh-ed25519 '.base64_encode($blob).' cloud-manager-pool'])['ssh_key']['id']
            ?? throw new RuntimeException('DigitalOcean stored the key but returned no id.'));
    }

    private function region(stdClass $site): string
    {
        $attributes = DB::table('resources')->where('organization_id', $site->organization_id)->where('type', 'server')
            ->where('slug', strstr($site->node_id, '.', true) ?: $site->node_id)->value('attributes');

        return (string) (json_decode((string) $attributes, true)['region'] ?? throw new InvalidArgumentException("{$site->node_id} is not a Droplet the platform created."));
    }

    private function client(stdClass $site, string $reason): Client
    {
        return $this->credentials->clientFor($site->organization_id, $reason, Actor::system('control-plane'));
    }
}
