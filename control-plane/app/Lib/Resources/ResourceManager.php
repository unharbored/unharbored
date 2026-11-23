<?php

declare(strict_types=1);

namespace App\Lib\Resources;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use App\Lib\Resources\Exceptions\ConcurrentModification;
use App\Lib\Resources\Exceptions\InvalidTransition;
use App\Lib\Resources\Exceptions\ResourceNotFound;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Tracks provisioned infrastructure and the state it is in.
 *
 * The shape of this class is driven by one failure mode: the process dies
 * between asking DigitalOcean to create something and finding out whether it
 * did. The resource exists, the customer is paying for it, and we have no
 * record. PLAN.md calls that the worst class of bug this product can have.
 *
 * So the order of operations is fixed and not negotiable:
 *
 *   1. Write the row first, with an idempotency key, before any API call.
 *   2. Make the call.
 *   3. Record the provider identifier the moment it is known.
 *
 * A crash between 1 and 2 leaves a row in Provisioning with no external id,
 * which reconciliation can look for at the provider by tag. A crash between
 * 2 and 3 leaves the same, and the idempotency key means the retry does not
 * create a second one. Neither leaves silence.
 */
final class ResourceManager
{
    public function __construct(
        private readonly AuditLog $audit,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Declare a resource before anything is created at the provider.
     *
     * Returns the existing row if one is already declared, so a retried
     * request does not produce a second resource.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function declare(
        string $organizationId,
        string $type,
        string $slug,
        array $attributes = [],
        ResourceState $desiredState = ResourceState::Active,
        string $provider = 'digitalocean',
    ): ResourceRecord {
        $existing = $this->find($organizationId, $type, $slug);

        if ($existing !== null) {
            return $existing;
        }

        $now = CarbonImmutable::now();

        $id = $this->db()->table('resources')->insertGetId([
            'organization_id' => $organizationId,
            'type' => $type,
            'provider' => $provider,
            'slug' => $slug,
            'state' => ResourceState::Pending->value,
            'desired_state' => $desiredState->value,
            'idempotency_key' => (string) Str::uuid(),
            'attributes' => $attributes === [] ? null : json_encode($attributes, JSON_THROW_ON_ERROR),
            'state_entered_at' => $now,
            'version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $this->recordTransition($id, null, ResourceState::Pending, 'declared');

        return $this->requireById($id);
    }

    /**
     * Move a resource to a new state.
     *
     * @param  array<string, mixed>  $context
     *
     * @throws InvalidTransition      If the graph does not allow it.
     * @throws ConcurrentModification If another worker moved it first.
     */
    public function transition(
        ResourceRecord $resource,
        ResourceState $to,
        ?string $reason = null,
        ?string $error = null,
        array $context = [],
        ?Actor $actor = null,
    ): ResourceRecord {
        if (! $resource->state->canTransitionTo($to)) {
            throw new InvalidTransition(sprintf(
                'Cannot move %s %s from %s to %s. Allowed: %s.',
                $resource->type,
                $resource->slug,
                $resource->state->value,
                $to->value,
                implode(', ', array_map(fn (ResourceState $s) => $s->value, $resource->state->allowedNext())) ?: 'none'
            ));
        }

        $now = CarbonImmutable::now();

        $update = [
            'state' => $to->value,
            'state_entered_at' => $now,
            'last_error' => $error,
            'version' => $resource->version + 1,
            'updated_at' => $now,
        ];

        if ($to === ResourceState::Provisioning || $to === ResourceState::Updating) {
            $update['attempts'] = $resource->attempts + 1;
        }

        // Optimistic locking. Two reconciler workers looking at the same
        // stale row must not both act on it.
        $affected = $this->db()->table('resources')
            ->where('id', $resource->id)
            ->where('version', $resource->version)
            ->update($update);

        if ($affected === 0) {
            throw new ConcurrentModification(sprintf(
                'Resource %s %s was modified by another process; reload and retry.',
                $resource->type,
                $resource->slug
            ));
        }

        $this->recordTransition($resource->id, $resource->state, $to, $reason, $error, $context);

        $this->audit->record(
            organizationId: $resource->organizationId,
            actor: $actor ?? Actor::system('resource-manager'),
            action: sprintf('resource.%s', $to->value),
            outcome: $to === ResourceState::Failed ? Outcome::Failed : Outcome::Succeeded,
            resourceType: $resource->type,
            resourceId: $resource->slug,
            reason: $reason,
            metadata: array_merge($context, [
                'from' => $resource->state->value,
                'to' => $to->value,
                'external_id' => $resource->externalId,
            ]),
        );

        return $this->requireById($resource->id);
    }

    /**
     * Record the provider identifier.
     *
     * Called the instant an identifier is known, before anything else is done
     * with it. Everything about losing track of paid resources comes down to
     * how quickly this happens.
     */
    public function recordExternalId(ResourceRecord $resource, string $externalId): ResourceRecord
    {
        $this->db()->table('resources')
            ->where('id', $resource->id)
            ->update([
                'external_id' => $externalId,
                'updated_at' => CarbonImmutable::now(),
            ]);

        $this->recordTransition(
            $resource->id,
            $resource->state,
            $resource->state,
            'external id recorded',
            null,
            ['external_id' => $externalId],
        );

        return $this->requireById($resource->id);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function mergeAttributes(ResourceRecord $resource, array $attributes): ResourceRecord
    {
        $merged = array_merge($resource->attributes, $attributes);

        $this->db()->table('resources')
            ->where('id', $resource->id)
            ->update([
                'attributes' => json_encode($merged, JSON_THROW_ON_ERROR),
                'updated_at' => CarbonImmutable::now(),
            ]);

        return $this->requireById($resource->id);
    }

    public function find(string $organizationId, string $type, string $slug): ?ResourceRecord
    {
        $row = $this->db()->table('resources')
            ->where('organization_id', $organizationId)
            ->where('type', $type)
            ->where('slug', $slug)
            ->first();

        return $row === null ? null : ResourceRecord::fromRow($row);
    }

    public function findById(int $id): ?ResourceRecord
    {
        $row = $this->db()->table('resources')->where('id', $id)->first();

        return $row === null ? null : ResourceRecord::fromRow($row);
    }

    private function requireById(int $id): ResourceRecord
    {
        $record = $this->findById($id);

        if ($record === null) {
            throw new ResourceNotFound(sprintf('Resource %d disappeared mid-operation.', $id));
        }

        return $record;
    }

    /**
     * Resources stuck in a transient state for longer than they should be.
     *
     * This is the query that catches a worker that died mid-provision. Nothing
     * else in the system notices: the row looks busy, and busy looks fine
     * until you ask how long it has been busy.
     *
     * @return array<int, ResourceRecord>
     */
    public function stale(DateTimeInterface $olderThan): array
    {
        $transient = array_map(
            fn (ResourceState $s) => $s->value,
            array_filter(ResourceState::cases(), fn (ResourceState $s) => $s->isTransient())
        );

        return $this->db()->table('resources')
            ->whereIn('state', $transient)
            ->where('state_entered_at', '<', $olderThan)
            ->orderBy('state_entered_at')
            ->get()
            ->map(fn (object $row) => ResourceRecord::fromRow($row))
            ->all();
    }

    /**
     * Rows that may be costing the customer money.
     *
     * Deliberately includes Orphaned and Provisioning, not just Active. The
     * question this answers is "what might they be billed for", and the
     * honest answer includes everything we are unsure about.
     *
     * @return array<int, ResourceRecord>
     */
    public function billable(string $organizationId): array
    {
        $states = array_map(
            fn (ResourceState $s) => $s->value,
            array_filter(ResourceState::cases(), fn (ResourceState $s) => $s->mayIncurCost())
        );

        return $this->db()->table('resources')
            ->where('organization_id', $organizationId)
            ->whereIn('state', $states)
            ->orderBy('type')
            ->get()
            ->map(fn (object $row) => ResourceRecord::fromRow($row))
            ->all();
    }

    /**
     * Resources we believe exist but cannot manage.
     *
     * @return array<int, ResourceRecord>
     */
    public function orphaned(?string $organizationId = null): array
    {
        $query = $this->db()->table('resources')
            ->where('state', ResourceState::Orphaned->value);

        if ($organizationId !== null) {
            $query->where('organization_id', $organizationId);
        }

        return $query->get()->map(fn (object $row) => ResourceRecord::fromRow($row))->all();
    }

    /**
     * @return array<int, object> Every state change for a resource, oldest first.
     */
    public function history(int $resourceId): array
    {
        return $this->db()->table('resource_transitions')
            ->where('resource_id', $resourceId)
            ->orderBy('id')
            ->get()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function recordTransition(
        int $resourceId,
        ?ResourceState $from,
        ResourceState $to,
        ?string $reason = null,
        ?string $error = null,
        array $context = [],
    ): void {
        $this->db()->table('resource_transitions')->insert([
            'resource_id' => $resourceId,
            'from_state' => $from?->value,
            'to_state' => $to->value,
            'reason' => $reason,
            'error' => $error,
            'context' => $context === [] ? null : json_encode($context, JSON_THROW_ON_ERROR),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
