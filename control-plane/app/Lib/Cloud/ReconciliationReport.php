<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Resources\ResourceRecord;

/**
 * What a reconciliation pass found.
 *
 * `checkedProvider` matters as much as the lists. A pass that could not reach
 * DigitalOcean finds no untracked resources, and reporting that as a clean
 * sweep would be a lie that reads like good news.
 */
final class ReconciliationReport
{
    /**
     * Resources running at DigitalOcean under our tag with no tracked record.
     * The customer is being billed for these and we cannot manage them.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $untracked = [];

    /**
     * Rows stuck in a transient state for longer than they should be.
     * Something died holding them.
     *
     * @var array<int, ResourceRecord>
     */
    public array $stuck = [];

    /**
     * Resources we believe exist but DigitalOcean does not have. Usually
     * deleted outside the platform.
     *
     * @var array<int, ResourceRecord>
     */
    public array $missing = [];

    /**
     * False when DigitalOcean could not be reached, which makes the untracked
     * and missing lists meaningless rather than empty.
     */
    public bool $checkedProvider = false;

    public ?string $error = null;

    public function __construct(public readonly string $organizationId) {}

    /**
     * True when the pass was conclusive and found nothing wrong.
     */
    public function isClean(): bool
    {
        return $this->checkedProvider
            && $this->untracked === []
            && $this->stuck === []
            && $this->missing === [];
    }

    /**
     * Whether anything here costs money right now.
     */
    public function hasCostlyFindings(): bool
    {
        return $this->untracked !== [] || $this->stuck !== [];
    }

    public function summary(): string
    {
        if (! $this->checkedProvider) {
            return sprintf(
                'Could not reach DigitalOcean for %s%s. Local checks found %d stuck resource(s); '
                .'untracked resources could not be checked.',
                $this->organizationId,
                $this->error !== null ? ' ('.$this->error.')' : '',
                count($this->stuck)
            );
        }

        if ($this->isClean()) {
            return sprintf('%s is consistent with DigitalOcean.', $this->organizationId);
        }

        return sprintf(
            '%s: %d untracked, %d stuck, %d missing.',
            $this->organizationId,
            count($this->untracked),
            count($this->stuck),
            count($this->missing)
        );
    }
}
