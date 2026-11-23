<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use App\Lib\DigitalOcean\Exceptions\DigitalOceanException;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use Carbon\CarbonImmutable;

/**
 * Compares what DigitalOcean actually has against what we think it has.
 *
 * Everything the platform knows about a customer's infrastructure is a belief
 * held in our database, and beliefs drift. A worker dies mid-provision, a
 * create times out after succeeding, someone deletes a Droplet from the
 * DigitalOcean console. This is the only component whose job is to notice.
 *
 * It answers three questions, in order of how much they cost to get wrong:
 *
 *  1. **Is anything running that we are not tracking?** This is the
 *     expensive one: the customer is billed for something we cannot see,
 *     manage, or clean up. Found by searching DigitalOcean for our platform
 *     tag and subtracting what we know about.
 *  2. **Is anything stuck?** A row that has been Provisioning for an hour is
 *     not provisioning. Something died holding it.
 *  3. **Is anything gone that we think exists?** Cheaper, but it makes every
 *     later operation on that resource fail confusingly.
 *
 * Nothing here deletes anything. Reconciliation reports; a human or an
 * explicit cleanup decides. Automatically destroying a resource because it
 * looked untracked is how you delete a customer's production database
 * because a migration half-ran.
 */
final class Reconciler
{
    /**
     * How long a transient state may persist before it counts as stuck.
     *
     * Comfortably longer than DigitalOcean takes to create a Droplet, since
     * calling something stuck while it is still working would produce a
     * second resource alongside a perfectly healthy first one.
     */
    public const DEFAULT_STALE_AFTER_MINUTES = 30;

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ResourceManager $resources,
        private readonly AuditLog $audit,
        private readonly ?\App\Lib\Providers\Providers $providers = null,
    ) {}

    /**
     * Reconcile one organisation.
     *
     * @return ReconciliationReport
     */
    public function reconcile(
        string $organizationId,
        ?Actor $actor = null,
        int $staleAfterMinutes = self::DEFAULT_STALE_AFTER_MINUTES,
    ): ReconciliationReport {
        $actor ??= Actor::system('reconciler');

        $report = new ReconciliationReport($organizationId);

        // Stuck rows first. They need no API call, so they are still found
        // when a credential has been revoked.
        foreach ($this->resources->stale(CarbonImmutable::now()->subMinutes($staleAfterMinutes)) as $stuck) {
            if ($stuck->organizationId !== $organizationId) {
                continue;
            }

            $report->stuck[] = $stuck;

            $this->audit->record(
                organizationId: $organizationId,
                actor: $actor,
                action: 'reconcile.stuck_resource',
                outcome: Outcome::Failed,
                resourceType: $stuck->type,
                resourceId: $stuck->slug,
                reason: sprintf(
                    'Has been %s since %s',
                    $stuck->state->value,
                    $stuck->stateEnteredAt?->toIso8601String() ?? 'unknown'
                ),
                metadata: ['external_id' => $stuck->externalId],
            );
        }

        $this->reconcileDigitalOcean($organizationId, $actor, $report);
        $this->reconcileOthers($organizationId, $actor, $report);

        return $report;
    }

    /** DigitalOcean's droplets, read directly. */
    private function reconcileDigitalOcean(string $organizationId, Actor $actor, ReconciliationReport $report): void
    {
        if (! $this->credentials->has($organizationId)) {
            // Without a credential we cannot ask DigitalOcean anything. Say
            // so rather than reporting a clean sweep, which would be a lie
            // that reads like good news.
            $report->checkedProvider = false;

            return;
        }

        try {
            $client = $this->credentials->clientFor(
                $organizationId,
                'Reconciling tracked resources against DigitalOcean',
                $actor
            );

            $remote = $client->paginate('droplets', 'droplets', [
                'tag_name' => ServerProvisioner::PLATFORM_TAG,
            ]);
        } catch (DigitalOceanException $e) {
            $report->checkedProvider = false;
            $report->error = $e->getMessage();

            return;
        }

        $report->checkedProvider = true;

        $tracked = [];
        foreach ($this->resources->billable($organizationId) as $resource) {
            if ($resource->externalId !== null && $resource->provider === 'digitalocean') {
                $tracked[$resource->externalId] = $resource;
            }
        }

        $seen = [];

        // Question 1: running but untracked.
        foreach ($remote as $droplet) {
            $id = (string) ($droplet['id'] ?? '');

            if ($id === '') {
                continue;
            }

            // Only consider droplets tagged for this organisation. Two
            // organisations could connect the same DigitalOcean account, and
            // reporting one tenant's resources to another would be worse than
            // missing them.
            if (! $this->belongsToOrganization($droplet, $organizationId)) {
                continue;
            }

            $seen[$id] = true;

            if (! isset($tracked[$id])) {
                $report->untracked[] = [
                    'external_id' => $id,
                    'name' => $droplet['name'] ?? null,
                    'created_at' => $droplet['created_at'] ?? null,
                    'region' => $droplet['region']['slug'] ?? null,
                    'size' => $droplet['size_slug'] ?? null,
                ];

                $this->audit->record(
                    organizationId: $organizationId,
                    actor: $actor,
                    action: 'reconcile.untracked_resource',
                    outcome: Outcome::Failed,
                    resourceType: 'server',
                    resourceId: $id,
                    reason: 'Running at DigitalOcean under our tag with no tracked record. The customer is being billed for it.',
                    metadata: ['name' => $droplet['name'] ?? null],
                );
            }
        }

        // Question 3: tracked but gone.
        foreach ($tracked as $id => $resource) {
            if (isset($seen[$id])) {
                continue;
            }

            // A resource mid-deletion is expected to be absent.
            if ($resource->state === ResourceState::Deleting) {
                continue;
            }

            $report->missing[] = $resource;

            $this->audit->record(
                organizationId: $organizationId,
                actor: $actor,
                action: 'reconcile.missing_resource',
                outcome: Outcome::Failed,
                resourceType: $resource->type,
                resourceId: $resource->slug,
                reason: 'Tracked as present but not found at DigitalOcean. It may have been deleted outside the platform.',
                metadata: ['external_id' => $id],
            );
        }

    }

    /**
     * Every other connected provider, through its driver: servers under our
     * platform tag for this organisation, set against the servers we track on
     * that provider. Each region we have used there is asked, since an
     * account's servers are not all in one.
     */
    private function reconcileOthers(string $organizationId, Actor $actor, ReconciliationReport $report): void
    {
        $providers = $this->providers ?? app(\App\Lib\Providers\Providers::class);
        foreach ($providers->connected($organizationId) as $provider) {
            if ($provider->id() === 'digitalocean' || $provider->method() !== 'api') {
                continue;
            }

            $tracked = [];
            $regions = [''];
            foreach ($this->resources->billable($organizationId) as $resource) {
                if ($resource->provider === $provider->id() && $resource->type === 'server') {
                    $regions[] = (string) ($resource->attributes['region'] ?? '');
                    if ($resource->externalId !== null) {
                        $tracked[$resource->externalId] = $resource;
                    }
                }
            }

            $seen = [];
            try {
                foreach (array_unique($regions) as $region) {
                    $driver = $provider->servers($organizationId, $actor, 'Reconciling tracked servers against '.$provider->label(), $region);
                    foreach ($driver->listTagged('org:'.preg_replace('/[^A-Za-z0-9:._-]/', '', $organizationId)) as $server) {
                        // A provider with one API for every region answers the same list each time.
                        if (isset($seen[$server->id])) {
                            continue;
                        }
                        $seen[$server->id] = true;
                        if (! isset($tracked[$server->id])) {
                            $report->untracked[] = [
                                'external_id' => $server->id, 'name' => $server->name, 'created_at' => null, 'region' => $region ?: null, 'size' => null, 'provider' => $provider->id(),
                            ];
                            $this->audit->record($organizationId, $actor, 'reconcile.untracked_resource', outcome: Outcome::Failed, resourceType: 'server', resourceId: $server->id,
                                reason: "Running at {$provider->label()} under our tag with no tracked record. The customer is being billed for it.", metadata: ['name' => $server->name]);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $report->error = trim(($report->error ?? '').' '.$provider->label().': '.$e->getMessage());

                continue;
            }
            $report->checkedProvider = true;

            foreach ($tracked as $id => $resource) {
                if (! isset($seen[$id]) && $resource->state !== ResourceState::Deleting) {
                    $report->missing[] = $resource;
                    $this->audit->record($organizationId, $actor, 'reconcile.missing_resource', outcome: Outcome::Failed, resourceType: $resource->type, resourceId: $resource->slug,
                        reason: "Tracked as present but not found at {$provider->label()}. It may have been deleted outside the platform.", metadata: ['external_id' => $id]);
                }
            }
        }
    }

    /**
     * Try to adopt an untracked Droplet back into a tracked resource.
     *
     * Only works when the Droplet carries a resource tag we recognise, which
     * ours do. Anything else needs a human: adopting a resource we cannot
     * confidently name risks attaching a record to the wrong machine.
     *
     * @param  array<string, mixed>  $droplet
     */
    public function adopt(string $organizationId, array $droplet, ?Actor $actor = null): bool
    {
        $actor ??= Actor::system('reconciler');

        $slug = $this->resourceSlugFromTags($droplet);
        $externalId = (string) ($droplet['id'] ?? '');

        if ($slug === null || $externalId === '') {
            return false;
        }

        $resource = $this->resources->find($organizationId, 'server', $slug);

        if ($resource === null) {
            return false;
        }

        if ($resource->externalId !== null && $resource->externalId !== $externalId) {
            // Two droplets claiming the same slug. Refuse: overwriting the id
            // would abandon whichever one we already knew about.
            $this->audit->record(
                organizationId: $organizationId,
                actor: $actor,
                action: 'reconcile.adoption_conflict',
                outcome: Outcome::Denied,
                resourceType: 'server',
                resourceId: $slug,
                reason: sprintf(
                    'Droplet %s carries our tag but the resource already points at %s.',
                    $externalId,
                    $resource->externalId
                ),
            );

            return false;
        }

        if ($resource->externalId === null) {
            $resource = $this->resources->recordExternalId($resource, $externalId);
        }

        if ($resource->state === ResourceState::Orphaned || $resource->state === ResourceState::Provisioning) {
            $this->resources->transition(
                $resource,
                ResourceState::Active,
                reason: 'Recovered by tag search during reconciliation',
                actor: $actor,
            );
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $droplet
     */
    private function belongsToOrganization(array $droplet, string $organizationId): bool
    {
        $wanted = 'org:'.preg_replace('/[^A-Za-z0-9:._-]/', '', $organizationId);

        return in_array($wanted, $droplet['tags'] ?? [], true);
    }

    /**
     * @param  array<string, mixed>  $droplet
     */
    private function resourceSlugFromTags(array $droplet): ?string
    {
        foreach ($droplet['tags'] ?? [] as $tag) {
            if (str_starts_with((string) $tag, 'resource:')) {
                return substr((string) $tag, strlen('resource:'));
            }
        }

        return null;
    }
}
