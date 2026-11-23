<?php

declare(strict_types=1);

namespace App\Lib\Audit;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

/**
 * Append-only, tamper-evident record of everything that happened.
 *
 * Two properties make this worth more than a log file:
 *
 *  1. **Append only.** There is no update and no delete. Not as a policy, as
 *     an absence of code.
 *  2. **Hash chained.** Each record commits to its predecessor, so altering
 *     one record or removing one breaks verification for every record after
 *     it. An attacker with database write access can still destroy the log,
 *     but cannot quietly edit it.
 *
 * Chains are per organisation. One tenant can be verified without reading
 * another's records, and appends serialise per tenant rather than globally.
 *
 * PLAN.md puts this in Phase 0 specifically because it cannot be retrofitted:
 * a chain that starts late cannot prove anything about what came before.
 */
final class AuditLog
{
    /**
     * Metadata keys whose values are replaced before storage.
     *
     * The audit log is read by more people than the vault is, and gets
     * exported. A credential that lands here has effectively been published
     * internally, so redaction happens on the way in rather than on the way
     * out.
     */
    private const REDACT_PATTERNS = [
        'token', 'secret', 'password', 'passwd', 'key', 'authorization',
        'auth', 'credential', 'cookie', 'session', 'signature', 'private',
    ];

    private const REDACTED = '[redacted]';

    public function __construct(
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Append a record.
     *
     * @param  array<string, mixed>  $metadata  Redacted before storage.
     * @return array{sequence: int, hash: string}
     */
    public function record(
        string $organizationId,
        Actor $actor,
        string $action,
        Outcome $outcome = Outcome::Succeeded,
        ?string $resourceType = null,
        ?string $resourceId = null,
        ?string $reason = null,
        array $metadata = [],
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?DateTimeInterface $recordedAt = null,
    ): array {
        $recordedAt = $recordedAt !== null
            ? DateTimeImmutable::createFromInterface($recordedAt)
            : new DateTimeImmutable();

        return $this->db()->transaction(function () use (
            $organizationId, $actor, $action, $outcome, $resourceType,
            $resourceId, $reason, $metadata, $ipAddress, $userAgent, $recordedAt
        ): array {
            $head = $this->lockHead($organizationId);

            $sequence = $head->sequence + 1;
            $previousHash = $head->head_hash;

            $row = [
                'organization_id' => $organizationId,
                'sequence' => $sequence,
                'actor_type' => $actor->type,
                'actor_id' => $actor->id,
                'actor_label' => $actor->label,
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'reason' => $reason,
                'outcome' => $outcome->value,
                'metadata' => $metadata === []
                    ? null
                    : json_encode($this->redact($metadata), JSON_THROW_ON_ERROR),
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'recorded_at' => $recordedAt->format('Y-m-d H:i:s.u'),
                'previous_hash' => $previousHash,
            ];

            $row['hash'] = $this->hash($row, $previousHash);

            $this->db()->table('audit_records')->insert($row);

            $this->db()->table('audit_chain_heads')
                ->where('organization_id', $organizationId)
                ->update([
                    'sequence' => $sequence,
                    'head_hash' => $row['hash'],
                    'updated_at' => now(),
                ]);

            return ['sequence' => $sequence, 'hash' => $row['hash']];
        });
    }

    /**
     * Verify an organisation's chain.
     *
     * Recomputes every hash and checks the links. Detects edits, deletions
     * and reordering.
     */
    public function verify(string $organizationId): VerificationResult
    {
        $records = $this->db()->table('audit_records')
            ->where('organization_id', $organizationId)
            ->orderBy('sequence')
            ->get();

        if ($records->isEmpty()) {
            return VerificationResult::intact(0);
        }

        $expectedSequence = 1;
        $previousHash = null;

        foreach ($records as $record) {
            $sequence = (int) $record->sequence;

            if ($sequence !== $expectedSequence) {
                return VerificationResult::broken(
                    $sequence,
                    sprintf(
                        'Expected sequence %d but found %d. %d record(s) are missing.',
                        $expectedSequence,
                        $sequence,
                        $sequence - $expectedSequence
                    )
                );
            }

            if ($record->previous_hash !== $previousHash) {
                return VerificationResult::broken(
                    $sequence,
                    'Record does not link to its predecessor.'
                );
            }

            $recomputed = $this->hash($this->columnsOf($record), $previousHash);

            if (! hash_equals($record->hash, $recomputed)) {
                return VerificationResult::broken(
                    $sequence,
                    'Record contents do not match its hash; it has been altered.'
                );
            }

            $previousHash = $record->hash;
            $expectedSequence++;
        }

        // The head must agree with the last record, or records were removed
        // from the end, where a per-record check alone would see nothing.
        $head = $this->db()->table('audit_chain_heads')
            ->where('organization_id', $organizationId)
            ->first();

        if ($head !== null) {
            if ((int) $head->sequence !== $expectedSequence - 1 || $head->head_hash !== $previousHash) {
                return VerificationResult::broken(
                    $expectedSequence - 1,
                    'The chain head does not match the last record; records were removed from the end.'
                );
            }
        }

        return VerificationResult::intact($records->count());
    }

    /**
     * Read records back, newest first.
     *
     * @return array<int, object>
     */
    public function forOrganization(string $organizationId, int $limit = 100): array
    {
        return $this->db()->table('audit_records')
            ->where('organization_id', $organizationId)
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * @return array<int, object>
     */
    public function forResource(string $resourceType, string $resourceId, int $limit = 100): array
    {
        return $this->db()->table('audit_records')
            ->where('resource_type', $resourceType)
            ->where('resource_id', $resourceId)
            ->orderByDesc('sequence')
            ->limit($limit)
            ->get()
            ->all();
    }

    /**
     * Lock and return the chain head, creating it on first use.
     */
    private function lockHead(string $organizationId): object
    {
        $head = $this->db()->table('audit_chain_heads')
            ->where('organization_id', $organizationId)
            ->lockForUpdate()
            ->first();

        if ($head !== null) {
            return $head;
        }

        $this->db()->table('audit_chain_heads')->insert([
            'organization_id' => $organizationId,
            'sequence' => 0,
            'head_hash' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (object) ['organization_id' => $organizationId, 'sequence' => 0, 'head_hash' => null];
    }

    /**
     * Compute a record hash.
     *
     * Fields are concatenated in a fixed order with a separator that cannot
     * appear in the values, rather than hashing a JSON encoding. JSON key
     * order and escaping are not guaranteed stable across versions, and a
     * hash that changes when the encoder changes is a hash that fails
     * verification for reasons nobody can diagnose.
     *
     * @param  array<string, mixed>  $row
     */
    private function hash(array $row, ?string $previousHash): string
    {
        $fields = [
            $previousHash ?? '',
            (string) $row['organization_id'],
            (string) $row['sequence'],
            (string) $row['actor_type'],
            (string) ($row['actor_id'] ?? ''),
            (string) ($row['actor_label'] ?? ''),
            (string) $row['action'],
            (string) ($row['resource_type'] ?? ''),
            (string) ($row['resource_id'] ?? ''),
            (string) ($row['reason'] ?? ''),
            (string) $row['outcome'],
            (string) ($row['metadata'] ?? ''),
            (string) ($row['ip_address'] ?? ''),
            (string) ($row['user_agent'] ?? ''),
            (string) $row['recorded_at'],
        ];

        // Length-prefix each field so two different field splits cannot
        // produce the same input. Without this, moving a character from the
        // end of one field to the start of the next would hash identically.
        $canonical = '';
        foreach ($fields as $field) {
            $canonical .= strlen($field).':'.$field.'|';
        }

        return hash('sha256', $canonical);
    }

    /**
     * Normalise a database row into the shape hash() expects.
     *
     * @return array<string, mixed>
     */
    private function columnsOf(object $record): array
    {
        return [
            'organization_id' => $record->organization_id,
            'sequence' => $record->sequence,
            'actor_type' => $record->actor_type,
            'actor_id' => $record->actor_id,
            'actor_label' => $record->actor_label,
            'action' => $record->action,
            'resource_type' => $record->resource_type,
            'resource_id' => $record->resource_id,
            'reason' => $record->reason,
            'outcome' => $record->outcome,
            'metadata' => $record->metadata,
            'ip_address' => $record->ip_address,
            'user_agent' => $record->user_agent,
            'recorded_at' => $record->recorded_at,
        ];
    }

    /**
     * Replace values whose key suggests they are sensitive.
     *
     * Deliberately crude and deliberately aggressive. Over-redacting an
     * audit record costs a little context; under-redacting one publishes a
     * credential to everyone who can read the log.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function redact(array $data): array
    {
        $out = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $out[$key] = $this->redact($value);

                continue;
            }

            $out[$key] = $this->isSensitiveKey((string) $key) ? self::REDACTED : $value;
        }

        return $out;
    }

    private function isSensitiveKey(string $key): bool
    {
        $needle = strtolower($key);

        foreach (self::REDACT_PATTERNS as $pattern) {
            if (str_contains($needle, $pattern)) {
                return true;
            }
        }

        return false;
    }
}
