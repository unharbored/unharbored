<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Cloud\Exceptions\CloudException;
use App\Lib\Cloud\Exceptions\ProvisioningFailed;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Providers\Providers;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\Uncertain;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use Throwable;

/**
 * Creates a server on the application's provider and gets an agent talking on it.
 *
 * The ordering here is the whole point, and it is not negotiable:
 *
 *   1. Record the intent, with an idempotency key, before any API call.
 *   2. Mint the enrollment token and build cloud-init.
 *   3. Call the provider (through its ServerDriver).
 *   4. Record the provider id the instant it comes back.
 *   5. Poll until the server is running.
 *
 * Every failure mode is handled by where it falls in that sequence. A crash
 * between 1 and 3 leaves a tracked row with no provider id, which
 * reconciliation can search for by tag. A failure at 3 that might have
 * succeeded anyway, such as a timeout, moves the resource to Orphaned rather
 * than Failed, because "we do not know whether this exists" is a different
 * and more expensive situation than "this did not happen".
 *
 * PLAN.md calls orphaned paid resources the worst class of bug this product
 * can have. This class is the main place that bug would be introduced.
 */
final class ServerProvisioner
{
    /** Every resource we create carries this, so reconciliation can find it. */
    public const PLATFORM_TAG = 'cloud-manager';

    /**
     * How long a server may stay pending before we stop waiting. Generous: a
     * slow region is not a failure, and giving up early on something that is
     * still being created is how a tracked resource becomes an untracked one.
     */
    public const DEFAULT_READY_TIMEOUT_SECONDS = 300;

    public function __construct(
        private readonly Providers $providers,
        private readonly ResourceManager $resources,
        private readonly EnrollmentService $enrollment,
        private readonly CloudInit $cloudInit,
        private readonly ?\Closure $sleeper = null,
    ) {}

    /**
     * @param  array<string, mixed>  $spec  provider, region, size, image, vpc_uuid, ssh_keys, attributes (kept on the resource, e.g. the pool it belongs to)
     */
    public function provision(
        string $organizationId,
        string $slug,
        array $spec,
        ?Actor $actor = null,
        int $readyTimeoutSeconds = self::DEFAULT_READY_TIMEOUT_SECONDS,
    ): ResourceRecord {
        $actor ??= Actor::system('provisioner');
        $provider = $this->providers->get((string) ($spec['provider'] ?? 'digitalocean'));
        $catalog = $provider->catalog();
        $region = (string) ($spec['region'] ?? $catalog->defaultRegion);
        $size = (string) ($spec['size'] ?? array_key_first($catalog->sizes));
        $image = (string) ($spec['image'] ?? $catalog->defaultImage);

        // Step 1. Before anything else, and before any network call. Declare
        // is idempotent, so a retried request finds the existing row rather
        // than creating a second server.
        $resource = $this->resources->declare(
            organizationId: $organizationId,
            type: 'server',
            slug: $slug,
            attributes: ['region' => $region, 'size' => $size, 'image' => $image] + (array) ($spec['attributes'] ?? []),
            provider: $provider->id(),
        );

        if ($resource->state === ResourceState::Active) {
            return $resource;
        }

        if ($resource->state !== ResourceState::Pending && $resource->state !== ResourceState::Failed) {
            throw new ProvisioningFailed(sprintf(
                'Server %s is %s; refusing to provision over it.',
                $slug,
                $resource->state->value
            ), mayHaveCreatedResource: $resource->state->mayIncurCost());
        }

        $driver = $provider->servers($organizationId, $actor, sprintf('Provisioning server %s', $slug), $region);

        $resource = $this->resources->transition(
            $resource,
            ResourceState::Provisioning,
            reason: sprintf('Creating server %s', $slug),
            actor: $actor,
        );

        // Step 2. The node id is the resource slug scoped by organisation, so
        // the certificate common name and the resource row agree.
        $nodeId = $this->nodeId($organizationId, $slug);

        try {
            $token = $this->enrollment->issueToken($organizationId, $nodeId, actor: $actor);
            $userData = $this->cloudInit->build(
                nodeId: $nodeId,
                enrollmentToken: $token,
                caCertificatePem: $this->enrollment->serverTrustFor($organizationId),
                hostname: $slug,
            );
        } catch (Throwable $e) {
            // Nothing was created, so this is the cheap kind of failure.
            $this->resources->transition(
                $resource,
                ResourceState::Failed,
                reason: 'Could not prepare bootstrap',
                error: $e->getMessage(),
                actor: $actor,
            );

            throw new ProvisioningFailed(
                'Could not prepare the node bootstrap: '.$e->getMessage(),
                mayHaveCreatedResource: false,
                previous: $e,
            );
        }

        // Step 3. The idempotency key was generated at declare time and is
        // stable across retries of this logical operation, so a retried create
        // cannot produce a second server.
        $request = new ServerSpec(
            name: $slug,
            region: $region,
            size: $size,
            image: $image,
            userData: $userData,
            idempotencyKey: (string) $resource->idempotencyKey,
            tags: $this->tags($organizationId, $slug),
            extra: array_intersect_key($spec, array_flip(['vpc_uuid', 'ssh_keys', 'role'])),
        );

        try {
            $externalId = $driver->create($request);
        } catch (Uncertain $e) {
            // A request that never got a usable answer might still have
            // created something. This is the expensive case.
            $this->resources->transition(
                $resource,
                ResourceState::Orphaned,
                reason: 'Create did not return a usable answer; a server may exist',
                error: $e->getMessage(),
                actor: $actor,
            );

            throw new ProvisioningFailed(
                'Server creation failed: '.$e->getMessage(),
                mayHaveCreatedResource: true,
                previous: $e,
            );
        } catch (Rejected $e) {
            $this->resources->transition(
                $resource,
                ResourceState::Failed,
                reason: $provider->label().' refused the create',
                error: $e->getMessage(),
                actor: $actor,
            );

            throw new ProvisioningFailed(
                'Server creation failed: '.$e->getMessage(),
                mayHaveCreatedResource: false,
                previous: $e,
            );
        }

        // Step 4. Immediately, before anything else can fail.
        $resource = $this->resources->recordExternalId($resource, $externalId);

        // Step 5.
        return $this->waitUntilRunning($resource, $driver, $actor, $readyTimeoutSeconds);
    }

    /**
     * Poll until the server reports running.
     *
     * A timeout here does not mean failure. The server exists, we know its
     * id, and it is probably still booting. It stays in Provisioning so the
     * staleness query picks it up, rather than being marked Failed and
     * forgotten while it keeps billing.
     */
    private function waitUntilRunning(
        ResourceRecord $resource,
        ServerDriver $driver,
        Actor $actor,
        int $timeoutSeconds,
    ): ResourceRecord {
        $deadline = time() + $timeoutSeconds;
        $interval = 5;

        while (time() < $deadline) {
            try {
                $server = $driver->find((string) $resource->externalId);
            } catch (Uncertain) {
                // Transient. We hold the id, so nothing is lost by retrying.
                $this->sleep($interval);

                continue;
            }

            $state = $server?->state ?? 'pending';

            if ($state === 'running') {
                $resource = $this->resources->mergeAttributes($resource, [
                    'ipv4' => $server->ipv4,
                    'ipv6' => $server->ipv6,
                    'private_ipv4' => $server->privateIpv4,
                    'server_status' => 'running',
                ]);

                return $this->resources->transition(
                    $resource,
                    ResourceState::Active,
                    reason: 'Server is running',
                    actor: $actor,
                );
            }

            if ($state === 'failed' || $state === 'gone') {
                return $this->resources->transition(
                    $resource,
                    ResourceState::Failed,
                    reason: 'Server entered a failed state',
                    actor: $actor,
                );
            }

            $this->sleep($interval);
        }

        // Deliberately left in Provisioning, not Failed. We know it exists
        // and we know its id; it is simply taking longer than we waited.
        // Marking it Failed here would invite a retry that creates a second
        // server alongside a first one nobody is watching.
        throw new ProvisioningFailed(
            sprintf(
                'Server %s did not become active within %d seconds. It exists as %s '
                .'and remains tracked; reconciliation will pick it up.',
                $resource->slug,
                $timeoutSeconds,
                $resource->externalId
            ),
            mayHaveCreatedResource: true,
        );
    }

    /**
     * Tear a server down.
     *
     * Deleting something already deleted is the desired end state, so a
     * machine the provider no longer has is success rather than an error.
     */
    public function destroy(ResourceRecord $resource, ?Actor $actor = null): ResourceRecord
    {
        $actor ??= Actor::system('provisioner');

        if ($resource->externalId === null) {
            // Nothing to call. The row may still describe something real, so
            // this is only safe because reconciliation searches by tag.
            return $this->resources->transition(
                $resource,
                ResourceState::Deleted,
                reason: 'No provider id was ever recorded',
                actor: $actor,
            );
        }

        $driver = $this->providers->get($resource->provider)->servers(
            $resource->organizationId,
            $actor,
            sprintf('Destroying server %s', $resource->slug),
            (string) ($resource->attributes['region'] ?? ''),
        );

        $resource = $this->resources->transition(
            $resource,
            ResourceState::Deleting,
            reason: 'Destroying server',
            actor: $actor,
        );

        try {
            $driver->destroy($resource->externalId);
        } catch (CloudException $e) {
            $this->resources->transition(
                $resource,
                ResourceState::Failed,
                reason: 'Destroy failed',
                error: $e->getMessage(),
                actor: $actor,
            );

            throw new ProvisioningFailed(
                'Could not destroy server: '.$e->getMessage(),
                mayHaveCreatedResource: true,
                previous: $e,
            );
        }

        return $this->resources->transition(
            $resource,
            ResourceState::Deleted,
            reason: 'Server destroyed',
            actor: $actor,
        );
    }

    /**
     * Tags every resource carries, so reconciliation can find what we made
     * even when our own records are incomplete.
     *
     * @return array<int, string>
     */
    public function tags(string $organizationId, string $slug): array
    {
        return [
            self::PLATFORM_TAG,
            'org:'.$this->sanitiseTag($organizationId),
            'resource:'.$this->sanitiseTag($slug),
        ];
    }

    /**
     * The node identity burned into the agent's certificate.
     */
    public function nodeId(string $organizationId, string $slug): string
    {
        return sprintf('%s.%s', $this->sanitiseTag($slug), $this->sanitiseTag($organizationId));
    }

    /**
     * Reduce a value to characters DigitalOcean accepts in a tag: letters,
     * numbers, colons, dashes, underscores and periods. Anything else is
     * dropped rather than substituted, so two different inputs cannot
     * collapse onto one tag.
     *
     * Runs of periods are then collapsed and trimmed. Stripping the slashes
     * out of "org/../evil" otherwise leaves "org..evil", which is harmless as
     * a tag but not as an identifier: this same function produces the node id
     * that becomes a certificate common name, and a parent-directory sequence
     * surviving into an identifier is the kind of thing that is fine until
     * the day something joins it onto a path.
     */
    private function sanitiseTag(string $value): string
    {
        $clean = preg_replace('/[^A-Za-z0-9:._-]/', '', $value) ?? '';
        $clean = preg_replace('/\.{2,}/', '.', $clean) ?? '';
        $clean = trim($clean, '.');

        return $clean === '' ? 'unknown' : $clean;
    }

    private function sleep(int $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        sleep($seconds);
    }
}
