<?php

declare(strict_types=1);

namespace App\Lib\Enrollment\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The single failure an enrolling agent is ever told about.
 *
 * Unknown, expired and already-used tokens must be indistinguishable to an
 * unauthenticated caller, or the endpoint becomes an oracle for probing which
 * tokens exist. The real reason goes to the audit log via cause().
 */
final class EnrollmentRejected extends RuntimeException
{
    public const REASON_UNKNOWN = 'token_unknown';
    public const REASON_EXPIRED = 'token_expired';
    public const REASON_CONSUMED = 'token_consumed';
    public const REASON_MALFORMED = 'request_malformed';
    public const REASON_BAD_CSR = 'csr_invalid';

    /** A pool's token, from an address that is no unenrolled member of that pool. */
    public const REASON_NOT_A_MEMBER = 'not_a_pool_member';

    private function __construct(
        private readonly string $reason,
        private readonly ?string $organizationId = null,
        ?Throwable $previous = null,
    ) {
        // Deliberately uniform, and deliberately uninformative.
        parent::__construct('Enrollment rejected.', 0, $previous);
    }

    public static function because(
        string $reason,
        ?string $organizationId = null,
        ?Throwable $previous = null,
    ): self {
        return new self($reason, $organizationId, $previous);
    }

    /**
     * Which organisation the rejected token belonged to, when that is known.
     *
     * Null for an unknown token, which has no organisation by definition.
     * Those still get audited, under a sentinel: repeated unknown-token
     * attempts are what probing looks like.
     */
    public function organizationId(): ?string
    {
        return $this->organizationId;
    }

    /**
     * Log this. Never send it.
     */
    public function cause(): string
    {
        return $this->reason;
    }
}
