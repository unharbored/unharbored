<?php

declare(strict_types=1);

namespace App\Lib\Resources;

use Carbon\CarbonImmutable;

/**
 * A snapshot of a resource row.
 *
 * Immutable and carries its version, so a caller holding one knows exactly
 * which revision it reasoned about. Transitions check that version, which is
 * what stops two reconciler workers acting on the same stale read.
 */
final readonly class ResourceRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public int $id,
        public string $organizationId,
        public string $type,
        public string $slug,
        public ?string $externalId,
        public ResourceState $state,
        public ResourceState $desiredState,
        public ?string $idempotencyKey,
        public array $attributes,
        public ?string $lastError,
        public int $attempts,
        public ?CarbonImmutable $stateEnteredAt,
        public int $version,
        public string $provider = 'digitalocean',
    ) {}

    public static function fromRow(object $row): self
    {
        return new self(
            id: (int) $row->id,
            organizationId: (string) $row->organization_id,
            type: (string) $row->type,
            slug: (string) $row->slug,
            externalId: $row->external_id !== null ? (string) $row->external_id : null,
            state: ResourceState::from((string) $row->state),
            desiredState: ResourceState::from((string) $row->desired_state),
            idempotencyKey: $row->idempotency_key !== null ? (string) $row->idempotency_key : null,
            attributes: $row->attributes !== null
                ? (array) json_decode((string) $row->attributes, true, 512, JSON_THROW_ON_ERROR)
                : [],
            lastError: $row->last_error !== null ? (string) $row->last_error : null,
            attempts: (int) $row->attempts,
            stateEnteredAt: $row->state_entered_at !== null
                ? CarbonImmutable::parse((string) $row->state_entered_at)
                : null,
            version: (int) $row->version,
            provider: (string) ($row->provider ?? 'digitalocean'),
        );
    }

    /**
     * True when the row has drifted from what it should be.
     */
    public function needsReconciliation(): bool
    {
        return $this->state !== $this->desiredState && ! $this->state->isTransient();
    }

    /**
     * True when we believe something exists at the provider but hold no
     * identifier for it. The most expensive condition in the system.
     */
    public function isUntrackable(): bool
    {
        return $this->externalId === null && $this->state->mayIncurCost();
    }
}
