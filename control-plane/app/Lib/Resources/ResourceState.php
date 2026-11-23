<?php

declare(strict_types=1);

namespace App\Lib\Resources;

/**
 * Where a provisioned resource is in its life.
 *
 * The state graph is explicit rather than implied, because the interesting
 * question is never the happy path. It is what the row says after the process
 * died between calling DigitalOcean and hearing back.
 */
enum ResourceState: string
{
    /** Recorded by us, nothing sent to the provider yet. */
    case Pending = 'pending';

    /**
     * A create call is in flight, or was in flight when we last knew.
     *
     * This is the dangerous state: the resource may or may not exist, and the
     * customer may or may not be paying for it. A row sitting here longer
     * than the provider could plausibly take needs reconciling against the
     * provider, never silently abandoning.
     */
    case Provisioning = 'provisioning';

    /** Exists and is usable. */
    case Active = 'active';

    /** A change is in flight. */
    case Updating = 'updating';

    /** A delete call is in flight. */
    case Deleting = 'deleting';

    /** Confirmed gone at the provider. Terminal. */
    case Deleted = 'deleted';

    /**
     * An operation failed and we know the resource did not change.
     * Retryable.
     */
    case Failed = 'failed';

    /**
     * We believe something exists at the provider that we can no longer
     * manage: a create that timed out with no identifier returned, or a
     * resource found under our tag with no matching row.
     *
     * This state exists so the condition has a name and shows up in a query.
     * The alternative is that it has no name and shows up on an invoice.
     */
    case Orphaned = 'orphaned';

    /**
     * States from which no further transition is possible.
     */
    public function isTerminal(): bool
    {
        return $this === self::Deleted;
    }

    /**
     * States where an operation is believed to be in flight.
     *
     * These are the ones a reconciler watches for staleness.
     */
    public function isTransient(): bool
    {
        return in_array($this, [self::Provisioning, self::Updating, self::Deleting], true);
    }

    /**
     * States where the provider may be charging for something.
     */
    public function mayIncurCost(): bool
    {
        return in_array(
            $this,
            [self::Provisioning, self::Active, self::Updating, self::Deleting, self::Orphaned],
            true
        );
    }

    /**
     * @return array<int, self> States reachable from this one.
     */
    public function allowedNext(): array
    {
        return match ($this) {
            self::Pending => [self::Provisioning, self::Failed],

            // Orphaned is reachable from Provisioning because a create that
            // times out leaves exactly that uncertainty.
            self::Provisioning => [self::Active, self::Failed, self::Orphaned],

            self::Active => [self::Updating, self::Deleting, self::Orphaned],
            self::Updating => [self::Active, self::Failed, self::Orphaned],

            // Deleting can fail, and a delete that times out can orphan.
            self::Deleting => [self::Deleted, self::Failed, self::Orphaned],

            // A failed create retries; a failed anything can be torn down.
            self::Failed => [self::Provisioning, self::Deleting, self::Deleted],

            // An orphan is either recovered once we find its identifier, or
            // deleted once we do. It is never simply forgotten.
            self::Orphaned => [self::Active, self::Deleting, self::Deleted],

            self::Deleted => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedNext(), true);
    }
}
