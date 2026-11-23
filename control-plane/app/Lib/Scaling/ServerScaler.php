<?php

declare(strict_types=1);

namespace App\Lib\Scaling;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\AutoscalePools;
use App\Lib\Cloud\BaseImages;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Providers;
use App\Lib\Providers\Shapes;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * One autoscaler for every provider. It reads the agents' CPU samples and an
 * application's settings, and adds or removes one server at a time through
 * ServerProvisioner, so it works wherever a provider can create servers.
 *
 * The settings live in the same `autoscale_pool` resource a DigitalOcean
 * native pool uses (engine = scaler), so the limits and schedule the console
 * already edits are shared. Existing native pools keep working on
 * AutoscalePools; new autoscaling uses this.
 *
 * Rules, all enforced here:
 *  - never fewer than the minimum (the schedule can raise it) or more than the maximum;
 *  - one action at a time per application, and a cooldown after each;
 *  - the primary is never removed, and only servers the scaler created are destroyed;
 *  - scaling up needs CPU over the target for the whole window; scaling down
 *    needs it well under (half) for a longer one, so load cannot flap it;
 *  - a server that was created but never joined is destroyed, not left billing.
 *
 * Tradeoff, accepted in the spec: it reacts at the metrics interval and stops
 * if the control plane does. A native pool does neither.
 */
final class ServerScaler
{
    public const WINDOW_UP_MINUTES = 5;

    public const WINDOW_DOWN_MINUTES = 10;

    public const MIN_SAMPLES = 3;

    public const COOLDOWN_MINUTES = 5;

    /** How long a server stays off the balancer before it is destroyed, so requests in flight finish. */
    public const DRAIN_SECONDS = 60;

    /** A server created but still not serving after this is given up on. */
    public const STRANDED_MINUTES = 20;

    /** In a row, servers that failed to join before the scaler stops adding any and says so. */
    public const FAILURES_BEFORE_PAUSE = 3;

    private const BUSY = ['deploy', 'rollback', 'scaler_up', 'scaler_down', 'pool_member', 'pool_join', 'pool_leave', 'pool_create', 'pool_resize', 'pool_remove', 'server_add', 'server_remove', 'app_delete'];

    public function __construct(
        private readonly ResourceManager $resources,
        private readonly AuditLog $audit,
        private readonly Providers $providers,
        private readonly ServerProvisioner $provisioner,
    ) {}

    /** Whether this application's autoscaling is ours rather than a provider's native pool. */
    public static function manages(stdClass $site): bool
    {
        if (($site->autoscale_pool_id ?? null) === null) {
            return false;
        }
        $attributes = json_decode((string) DB::table('resources')->where('id', $site->autoscale_pool_id)->value('attributes'), true) ?: [];

        return ($attributes['engine'] ?? null) === 'scaler';
    }

    /**
     * @param  array{min: int, max: int, size: string, cpu: float}  $spec
     */
    public function create(stdClass $site, array $spec): ResourceRecord
    {
        $provider = $this->providers->get((string) ($site->provider ?? 'digitalocean'));
        if (($why = Shapes::lockedBecause($provider, Shapes::AUTOSCALE)) !== null) {
            throw new InvalidArgumentException($why);
        }
        foreach (['load_balancer_id' => 'a load balancer', 'bucket_id' => 'a bucket (new servers fetch each build from it)', 'database_cluster_id' => 'a managed database'] as $column => $what) {
            if (($site->{$column} ?? null) === null) {
                throw new InvalidArgumentException("{$site->name} needs {$what} before it can autoscale: a server added later must find everything it needs off the server.");
            }
        }
        if (($problem = $this->problem($site)) !== null) {
            throw new InvalidArgumentException($problem);
        }
        if (! $provider->catalog()->hasSize((string) $spec['size'])) {
            throw new InvalidArgumentException("{$provider->label()} has no size {$spec['size']}.");
        }

        $slug = "{$site->name}-pool";
        $resource = $this->resources->declare($site->organization_id, 'autoscale_pool', $slug, [
            'engine' => 'scaler', 'provider' => $provider->id(), 'region' => $this->region($site), 'site_id' => $site->id,
            'cooldown_minutes' => self::COOLDOWN_MINUTES, 'next_index' => 1,
        ] + $spec, provider: $provider->id());
        if ($resource->state === ResourceState::Active) {
            return $resource;
        }

        $actor = Actor::system('control-plane');
        $resource = $this->resources->transition($resource, ResourceState::Provisioning, reason: "Autoscaling {$site->name}", actor: $actor);
        $resource = $this->resources->recordExternalId($resource, "scaler-{$site->id}");
        $resource = $this->resources->transition($resource, ResourceState::Active, reason: 'Autoscaler on', actor: $actor);
        DB::table('sites')->where('id', $site->id)->update(['autoscale_pool_id' => $resource->id, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, $actor, 'site.autoscaler_enabled', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: $spec);

        return $resource;
    }

    /** New limits. A scheduled change moves only the minimum in force, so the set minimum survives outside the windows. */
    public function resize(stdClass $site, int $min, int $max, bool $scheduled = false): void
    {
        $pool = $this->of($site);
        $this->resources->mergeAttributes($pool, $scheduled ? ['live_min' => $min] : ['min' => $min, 'max' => $max, 'live_min' => $min]);
    }

    /**
     * Turns autoscaling off and destroys every server the scaler made.
     * Returns their node ids so the caller can take them off the site.
     *
     * @return list<string>
     */
    public function destroy(stdClass $site): array
    {
        $pool = $this->of($site);
        $actor = Actor::system('control-plane');
        $nodes = [];
        foreach ($this->members($pool) as $member) {
            $this->provisioner->destroy($member, $actor);
            $nodes[] = $this->provisioner->nodeId($pool->organizationId, $member->slug);
        }
        $pool = $this->resources->transition($pool, ResourceState::Deleting, reason: 'Autoscaling stopped', actor: $actor);
        $this->resources->transition($pool, ResourceState::Deleted, reason: 'Autoscaler off; its servers destroyed', actor: $actor);
        DB::table('sites')->where('id', $site->id)->update(['autoscale_pool_id' => null, 'updated_at' => now()]);
        $this->audit->record($site->organization_id, $actor, 'site.autoscaler_disabled', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['servers' => $nodes]);

        return $nodes;
    }

    public function of(stdClass $site): ResourceRecord
    {
        return $this->resources->findById((int) ($site->autoscale_pool_id ?? 0)) ?? throw new InvalidArgumentException("{$site->name} does not autoscale.");
    }

    /**
     * Servers the scaler made for this pool that exist or are being made.
     *
     * @return list<ResourceRecord>
     */
    public function members(ResourceRecord $pool): array
    {
        $out = [];
        foreach (DB::table('resources')->where('organization_id', $pool->organizationId)->where('type', 'server')
            ->whereIn('state', ['pending', 'provisioning', 'active'])->orderBy('id')->get() as $row) {
            if ((int) (json_decode((string) $row->attributes, true)['pool'] ?? 0) === $pool->id) {
                $out[] = ResourceRecord::fromRow($row);
            }
        }

        return $out;
    }

    /** A name for the next server, taken once so two actions never share one. */
    public function reserveSlug(stdClass $site): string
    {
        $pool = $this->of($site);
        $n = (int) ($pool->attributes['next_index'] ?? 1);
        do {
            $slug = "{$site->name}-s{$n}";
            $n++;
        } while (DB::table('resources')->where('organization_id', $site->organization_id)->where('slug', $slug)->exists()
            || DB::table('nodes')->where('node_id', "{$slug}.{$site->organization_id}")->exists());
        $this->resources->mergeAttributes($pool, ['next_index' => $n]);

        return $slug;
    }

    /** What a new server is made from. */
    public function spec(stdClass $site, ResourceRecord $pool, string $slug): array
    {
        $attributes = $pool->attributes;
        $spec = ['provider' => (string) $attributes['provider'], 'region' => (string) $attributes['region'], 'size' => (string) $attributes['size'],
            'attributes' => ['pool' => $pool->id, 'scaler' => true, 'site_id' => $site->id]];
        // DigitalOcean has a base image with the stack on it, which makes a server ready sooner.
        if ($spec['provider'] === 'digitalocean') {
            $spec['image'] = app(BaseImages::class)->imageFor($site->organization_id, $spec['region']);
        }

        return $spec;
    }

    /**
     * One look at one application. Queues at most one action; returns what it
     * decided, or null for nothing worth saying.
     */
    public function tick(stdClass $site, ?DateTimeInterface $now = null): ?string
    {
        $now = CarbonImmutable::instance($now ?? now());
        $pool = $this->of($site);
        if ($pool->state !== ResourceState::Active || $this->busy($site)) {
            return null;
        }
        $runner = app(OperationRunner::class);

        // A server that can never work is not tried again: stop, and say why.
        if (! $this->paused($pool) && ($problem = $this->problem($site)) !== null) {
            $this->pause($site, $pool, $problem);
            $pool = $this->of($site);
        }

        // A server made but never joined, for too long, is destroyed rather than left billing.
        $joined = DB::table('site_nodes')->where('site_id', $site->id)->pluck('node_id')->all();
        foreach ($this->members($pool) as $member) {
            $node = $this->provisioner->nodeId($site->organization_id, $member->slug);
            if ($member->state === ResourceState::Active && ! in_array($node, $joined, true)
                && DB::table('nodes')->where('node_id', $node)->exists()
                && $member->stateEnteredAt !== null && $member->stateEnteredAt->lt($now->subMinutes(self::STRANDED_MINUTES))) {
                $runner->queueScalerDown($site->id, $node, 'autoscale', 'autoscaler', stranded: true);
                $this->noteAction($pool, $now, "removed {$member->slug}: it never joined");
                $this->audit->record($site->organization_id, Actor::system('autoscaler'), 'site.autoscaled', resourceType: 'site',
                    resourceId: "{$site->node_id}/{$site->name}", metadata: ['action' => 'down', 'reason' => "{$member->slug} never joined, so it was destroyed", 'servers_before' => count($this->members($pool))]);

                return "{$member->slug} never joined; destroying it";
            }
        }

        if ($this->paused($pool)) {
            return null;
        }

        $count = count($this->members($pool));
        [$action, $reason] = self::decide($pool->attributes, $count, $this->load($site, self::WINDOW_UP_MINUTES), $this->load($site, self::WINDOW_DOWN_MINUTES), $now);
        if ($action === null) {
            return null;
        }

        if ($action === 'up') {
            $slug = $this->reserveSlug($site);
            $id = $runner->queueScalerUp($site->id, $slug, 'autoscale', 'autoscaler');
            $what = "adding {$slug} ({$reason}), operation {$id}";
        } else {
            $victim = $this->newestJoined($pool, $joined);
            if ($victim === null) {
                return null;
            }
            $id = $runner->queueScalerDown($site->id, $this->provisioner->nodeId($site->organization_id, $victim->slug), 'autoscale', 'autoscaler');
            $what = "removing {$victim->slug} ({$reason}), operation {$id}";
        }
        $this->noteAction($pool, $now, $what);
        $this->audit->record($site->organization_id, Actor::system('autoscaler'), 'site.autoscaled', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['action' => $action, 'reason' => $reason, 'servers_before' => $count]);

        return $what;
    }

    /**
     * What to do, from the settings and what was measured. Pure, so every
     * rule can be tested with a clock and numbers.
     *
     * @param  array<string, mixed>  $attributes  the pool's settings
     * @param  array{avg: float, latest: float, peak: float, samples: int}|null  $up  CPU over the short window, as fractions
     * @param  array{avg: float, latest: float, peak: float, samples: int}|null  $down  CPU over the long window
     * @return array{0: ?string, 1: string} 'up', 'down' or null, and why
     */
    public static function decide(array $attributes, int $count, ?array $up, ?array $down, DateTimeInterface $now): array
    {
        $min = AutoscalePools::minAt($attributes, $now);
        $max = (int) $attributes['max'];
        $target = (float) ($attributes['cpu'] ?? 0.7);

        $last = isset($attributes['last_action_at']) ? CarbonImmutable::parse((string) $attributes['last_action_at']) : null;
        $cooldown = (int) ($attributes['cooldown_minutes'] ?? self::COOLDOWN_MINUTES);
        if ($last !== null && CarbonImmutable::instance($now)->lt($last->addMinutes($cooldown))) {
            return [null, 'cooling down'];
        }

        if ($count < $min) {
            return ['up', "below the minimum of {$min}"];
        }
        if ($count > $max) {
            return ['down', "above the maximum of {$max}"];
        }
        if ($count < $max && $up !== null && $up['samples'] >= self::MIN_SAMPLES && $up['avg'] > $target && $up['latest'] > $target) {
            return ['up', 'CPU '.round($up['avg'] * 100).'% over '.round($target * 100).'% for '.self::WINDOW_UP_MINUTES.' minutes'];
        }
        if ($count > $min && $down !== null && $down['samples'] >= self::MIN_SAMPLES && $down['avg'] < $target / 2 && $down['peak'] < $target) {
            return ['down', 'CPU '.round($down['avg'] * 100).'% for '.self::WINDOW_DOWN_MINUTES.' minutes, well under '.round($target * 100).'%'];
        }

        return [null, 'steady'];
    }

    /**
     * CPU across the application's serving servers over a window, as fractions
     * of 1. Null when there are too few samples to say.
     *
     * @return array{avg: float, latest: float, peak: float, samples: int}|null
     */
    public function load(stdClass $site, int $minutes): ?array
    {
        $rows = DB::table('node_metrics')->whereIn('node_id', \App\Lib\Sites\Sites::nodes($site))
            ->where('collected_at', '>=', now()->subMinutes($minutes))->orderBy('collected_at')->get(['node_id', 'cpu']);
        if ($rows->isEmpty()) {
            return null;
        }
        $by = $rows->groupBy('node_id');
        $avgs = $by->map(fn ($r) => $r->avg('cpu') / 100);
        $latest = $by->map(fn ($r) => $r->last()->cpu / 100);

        return ['avg' => (float) $avgs->avg(), 'latest' => (float) $latest->avg(), 'peak' => (float) $avgs->max(), 'samples' => (int) $by->map->count()->min()];
    }

    public function paused(ResourceRecord $pool): bool
    {
        return isset($pool->attributes['paused_at']);
    }

    /**
     * Why servers added to this application can never work, or null. The
     * servers share nothing but the network, so an environment that points at
     * a file or a cache on one of them cannot be joined by another.
     */
    public function problem(stdClass $site): ?string
    {
        $sites = app(\App\Lib\Sites\Sites::class);
        $env = $sites->env($site);
        $over = $sites->overriddenKeys($site);
        $why = $over === [] ? '' : ' Your own variables override '.implode(', ', $over).', so the values Unharbored injected do not apply; remove them from Variables.';
        if (preg_match('/^DB_CONNECTION=["\']?sqlite/m', $env)) {
            return "{$site->name} uses SQLite, a file on one server, so a second server cannot run it. Autoscaling needs a database every server shares.".$why;
        }
        if (preg_match('/^(CACHE_STORE|SESSION_DRIVER|QUEUE_CONNECTION)=["\']?(file|array)\b/m', $env)) {
            return "{$site->name} keeps its cache, sessions or queue on a server's own disk, which another server cannot see. Use the database or Valkey.".$why;
        }

        return null;
    }

    /** A failed attempt to add a server: counted, and at the limit the scaler stops. */
    public function recordFailure(stdClass $site, string $why): void
    {
        $pool = $this->of($site);
        $failures = (int) ($pool->attributes['failures'] ?? 0) + 1;
        $pool = $this->resources->mergeAttributes($pool, ['failures' => $failures, 'last_failure' => mb_substr($why, 0, 400)]);
        if ($failures >= self::FAILURES_BEFORE_PAUSE && ! $this->paused($pool)) {
            $this->pause($site, $pool, "{$failures} servers in a row could not join. The last said: ".mb_substr($why, 0, 300));
        }
    }

    /** A server joined: whatever went wrong before is over. */
    public function recordJoined(stdClass $site): void
    {
        $pool = $this->of($site);
        if (isset($pool->attributes['failures'])) {
            $this->resources->mergeAttributes($pool, ['failures' => 0]);
        }
    }

    private function pause(stdClass $site, ResourceRecord $pool, string $why): void
    {
        $this->resources->mergeAttributes($pool, ['paused_at' => now()->toIso8601String(), 'paused_because' => mb_substr($why, 0, 600)]);
        $this->audit->record($site->organization_id, Actor::system('autoscaler'), 'site.autoscaler_paused', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['because' => mb_substr($why, 0, 600)]);
    }

    /** Lets the scaler carry on, after whatever stopped it has been put right. */
    public function resume(stdClass $site): void
    {
        $pool = $this->of($site);
        if (($why = $this->problem($site)) !== null) {
            throw new InvalidArgumentException("It cannot carry on yet: {$why}");
        }
        $attributes = $pool->attributes;
        unset($attributes['paused_at'], $attributes['paused_because']);
        $attributes['failures'] = 0;
        DB::table('resources')->where('id', $pool->id)->update(['attributes' => json_encode($attributes), 'updated_at' => now()]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.autoscaler_resumed', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}");
    }

    private function busy(stdClass $site): bool
    {
        return DB::table('operations')->where('site_id', $site->id)->whereIn('kind', self::BUSY)->whereIn('status', ['queued', 'running', 'waiting'])->exists();
    }

    /** @param list<string> $joined */
    private function newestJoined(ResourceRecord $pool, array $joined): ?ResourceRecord
    {
        $joinedMembers = array_filter($this->members($pool), fn ($m) => in_array($this->provisioner->nodeId($pool->organizationId, $m->slug), $joined, true));

        return $joinedMembers === [] ? null : end($joinedMembers);
    }

    private function noteAction(ResourceRecord $pool, CarbonImmutable $now, string $what): void
    {
        $this->resources->mergeAttributes($pool, ['last_action_at' => $now->toIso8601String(), 'last_action' => $what]);
    }

    private function region(stdClass $site): string
    {
        $attributes = DB::table('resources')->where('organization_id', $site->organization_id)->where('type', 'server')
            ->where('slug', strstr($site->node_id, '.', true) ?: $site->node_id)->value('attributes');

        return (string) (json_decode((string) $attributes, true)['region'] ?? throw new InvalidArgumentException("{$site->node_id} was not created by the platform, so there is no region to add servers in."));
    }
}
