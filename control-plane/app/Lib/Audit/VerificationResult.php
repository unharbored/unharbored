<?php

declare(strict_types=1);

namespace App\Lib\Audit;

/**
 * The outcome of verifying an audit chain.
 *
 * A broken chain names the sequence where verification failed. That number is
 * the useful part: every record before it is still trustworthy, so it bounds
 * how much history is in question rather than discarding all of it.
 */
final readonly class VerificationResult
{
    private function __construct(
        public bool $intact,
        public int $recordsChecked,
        public ?int $brokenAtSequence = null,
        public ?string $reason = null,
    ) {}

    public static function intact(int $recordsChecked): self
    {
        return new self(true, $recordsChecked);
    }

    public static function broken(int $sequence, string $reason): self
    {
        return new self(false, $sequence - 1, $sequence, $reason);
    }

    public function describe(): string
    {
        if ($this->intact) {
            return sprintf('Chain intact across %d record(s).', $this->recordsChecked);
        }

        return sprintf(
            'Chain broken at sequence %d: %s Records 1 to %d remain verifiable.',
            $this->brokenAtSequence,
            $this->reason,
            $this->recordsChecked
        );
    }
}
