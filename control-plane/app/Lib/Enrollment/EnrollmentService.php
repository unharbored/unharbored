<?php

declare(strict_types=1);

namespace App\Lib\Enrollment;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use App\Lib\Enrollment\Exceptions\EnrollmentRejected;
use App\Lib\Pki\CertificateAuthority;
use App\Lib\Pki\Exceptions\PkiException;
use App\Lib\Vault\Vault;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Turns a freshly booted Droplet into a node the control plane trusts.
 *
 * The server half of the handshake implemented by internal/enroll in the Go
 * agent. The two must agree exactly; tests/Feature/Enrollment/ConformanceTest
 * asserts that against fixtures the Go side generates.
 *
 * Six rules, each of which exists because breaking it has a specific
 * consequence:
 *
 *  1. The token is single use, and consumption is atomic. Cloud-init retries.
 *  2. Only the token hash is stored, so a database leak is not replayable.
 *  3. The node identity comes from the grant, not the request. A node must
 *     not be able to choose what it is called.
 *  4. CSR signatures are verified, or proof of possession means nothing.
 *  5. A malformed CSR still burns the token, or one token grants unlimited
 *     attempts and stops being single use.
 *  6. Every failure returns one opaque error. The reason goes to the audit
 *     log and nowhere else.
 *
 * An autoscale pool's grant bends rule 1 and keeps rule 3. DigitalOcean
 * boots its members with nobody there to mint a token each, so the pool has
 * one, in every member's user_data. What it names is not a node but a pool:
 * the node is whichever member Droplet DigitalOcean says has the address the
 * request came from, and each member enrolls once. A copy of the token is
 * worth nothing from anywhere else.
 */
final class EnrollmentService
{
    /**
     * Cloud-init normally enrolls within a minute of boot. An hour is
     * generous slack for a slow image or a retried provision, and short
     * enough that a token found later is worthless.
     */
    public const DEFAULT_TTL_SECONDS = 3600;

    public const TOKEN_BYTES = 32;

    /** Where an organisation's CA private key lives in the vault. */
    public const CA_KEY_SECRET = 'pki.ca.key';

    public function __construct(
        private readonly Vault $vault,
        private readonly AuditLog $audit,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Hash a token for storage.
     *
     * Plain SHA-256 of the token bytes, matching enroll.HashToken in the
     * agent. If the two ever disagree no node can enroll, which at least
     * fails loudly rather than quietly.
     */
    public static function hashToken(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * Mint a token for a node the control plane is about to create.
     *
     * Returns the plaintext, which is the only time it exists on this side.
     * It goes into cloud-init user_data and is never stored.
     */
    public function issueToken(
        string $organizationId,
        string $nodeId,
        ?int $ttlSeconds = null,
        ?Actor $actor = null,
    ): string {
        if (trim($nodeId) === '') {
            throw new \InvalidArgumentException('A node id is required to issue an enrollment token.');
        }

        $token = self::generateToken();
        $ttl = $ttlSeconds ?? self::DEFAULT_TTL_SECONDS;

        $this->db()->table('enrollment_grants')->insert([
            'token_hash' => self::hashToken($token),
            'node_id' => $nodeId,
            'organization_id' => $organizationId,
            'expires_at' => CarbonImmutable::now()->addSeconds($ttl),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->audit->record(
            organizationId: $organizationId,
            actor: $actor ?? Actor::system('enrollment'),
            action: 'enrollment.token_issued',
            resourceType: 'node',
            resourceId: $nodeId,
            reason: sprintf('Token valid for %d seconds', $ttl),
        );

        return $token;
    }

    /**
     * Redeem a token and issue a node certificate.
     *
     * @throws EnrollmentRejected Always, on any failure.
     */
    public function enroll(
        #[SensitiveParameter] string $token,
        string $csrPem,
        array $reported = [],
        ?string $ipAddress = null,
    ): EnrollmentResult {
        if ($token === '' || trim($csrPem) === '') {
            $this->auditRejection(null, EnrollmentRejected::REASON_MALFORMED, $reported, $ipAddress);

            throw EnrollmentRejected::because(EnrollmentRejected::REASON_MALFORMED);
        }

        $pool = $this->db()->table('enrollment_grants')->where('token_hash', self::hashToken($token))->value('autoscale_pool_id');
        if ($pool !== null) {
            return $this->enrollPoolMember(self::hashToken($token), (int) $pool, $csrPem, $reported, $ipAddress);
        }

        // Audit the rejection out here, not inside consume(). Writing it
        // inside the transaction meant the rollback that accompanies the
        // exception erased the record, so every probing attempt against this
        // endpoint left no trace at all.
        try {
            $grant = $this->consume(self::hashToken($token), $reported, $ipAddress);
        } catch (EnrollmentRejected $e) {
            $this->auditRejection($e->organizationId(), $e->cause(), $reported, $ipAddress);

            throw $e;
        }

        // The token is already spent at this point, deliberately. A caller
        // able to submit malformed requests must not get unlimited attempts
        // against one token.
        try {
            $ca = $this->certificateAuthorityFor($grant->organization_id);
            $certificatePem = $ca->issueNodeCertificate($csrPem, $grant->node_id);
        } catch (PkiException $e) {
            $this->auditRejection($grant->organization_id, EnrollmentRejected::REASON_BAD_CSR, $reported, $ipAddress);

            throw EnrollmentRejected::because(EnrollmentRejected::REASON_BAD_CSR, $grant->organization_id, $e);
        }

        $notAfter = CertificateAuthority::notAfter($certificatePem);

        $this->recordNode($grant, $certificatePem, $notAfter, $reported);

        $this->audit->record(
            organizationId: $grant->organization_id,
            actor: Actor::agent($grant->node_id),
            action: 'enrollment.completed',
            resourceType: 'node',
            resourceId: $grant->node_id,
            metadata: array_merge($this->reportedMetadata($reported), ['ip' => $ipAddress]),
        );

        return new EnrollmentResult(
            nodeId: $grant->node_id,
            organizationId: $grant->organization_id,
            certificatePem: $certificatePem,
            caCertificatePem: $ca->certificatePem(),
            notAfter: $notAfter,
        );
    }

    /**
     * Mint the one token a pool's members all boot with. It lasts as long as
     * the pool; removing the pool deletes it.
     */
    public function issuePoolToken(string $organizationId, int $poolId, string $poolSlug, ?Actor $actor = null): string
    {
        $token = self::generateToken();
        $this->db()->table('enrollment_grants')->insert([
            'token_hash' => self::hashToken($token),
            'node_id' => "pool:{$poolSlug}",
            'organization_id' => $organizationId,
            'autoscale_pool_id' => $poolId,
            'expires_at' => CarbonImmutable::now()->addYears(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->audit->record(organizationId: $organizationId, actor: $actor ?? Actor::system('enrollment'), action: 'enrollment.pool_token_issued',
            resourceType: 'autoscale_pool', resourceId: $poolSlug);

        return $token;
    }

    private function enrollPoolMember(string $tokenHash, int $poolId, string $csrPem, array $reported, ?string $ipAddress): EnrollmentResult
    {
        $grant = $this->db()->table('enrollment_grants')->where('token_hash', $tokenHash)->first();
        $pools = app(\App\Lib\Cloud\AutoscalePools::class);
        $reject = function (string $reason, ?Throwable $previous = null) use ($grant, $reported, $ipAddress): never {
            $this->auditRejection($grant->organization_id, $reason, $reported, $ipAddress);

            throw EnrollmentRejected::because($reason, $grant->organization_id, $previous);
        };

        if (CarbonImmutable::parse($grant->expires_at)->isPast()) {
            $reject(EnrollmentRejected::REASON_EXPIRED);
        }
        // Who the caller is comes from DigitalOcean, never from the request.
        $droplet = $ipAddress === null ? null : $pools->memberAt($poolId, $ipAddress);
        if ($droplet === null) {
            $reject(EnrollmentRejected::REASON_NOT_A_MEMBER);
        }
        $nodeId = $pools->memberNodeId($poolId, $droplet);
        // Each member once. The insert is the claim, so two racing requests
        // from one Droplet cannot both win.
        try {
            $this->db()->table('nodes')->insert(['node_id' => $nodeId, 'organization_id' => $grant->organization_id, 'created_at' => now(), 'updated_at' => now()]);
        } catch (\Illuminate\Database\UniqueConstraintViolationException) {
            $reject(EnrollmentRejected::REASON_CONSUMED);
        }

        try {
            $ca = $this->certificateAuthorityFor($grant->organization_id);
            $certificatePem = $ca->issueNodeCertificate($csrPem, $nodeId);
        } catch (PkiException $e) {
            $reject(EnrollmentRejected::REASON_BAD_CSR, $e);
        }
        $notAfter = CertificateAuthority::notAfter($certificatePem);
        $this->recordNode((object) ['node_id' => $nodeId, 'organization_id' => $grant->organization_id], $certificatePem, $notAfter, $reported);
        $this->db()->table('enrollment_grants')->where('id', $grant->id)->update(['consumed_by_hostname' => $reported['hostname'] ?? null, 'consumed_by_ip' => $ipAddress, 'updated_at' => now()]);
        $this->audit->record(organizationId: $grant->organization_id, actor: Actor::agent($nodeId), action: 'enrollment.completed',
            resourceType: 'node', resourceId: $nodeId, metadata: array_merge($this->reportedMetadata($reported), ['ip' => $ipAddress, 'pool' => $poolId, 'droplet' => $droplet]));
        $pools->enrolled($poolId, $droplet, $nodeId);

        return new EnrollmentResult(nodeId: $nodeId, organizationId: $grant->organization_id, certificatePem: $certificatePem,
            caCertificatePem: $ca->certificatePem(), notAfter: $notAfter);
    }

    /**
     * Atomically validate and burn a token.
     *
     * The update is conditional on consumed_at still being null, so two
     * concurrent requests with the same token produce exactly one winner
     * without relying on transaction isolation level.
     */
    private function consume(string $tokenHash, array $reported, ?string $ipAddress): object
    {
        return $this->db()->transaction(function () use ($tokenHash, $reported, $ipAddress): object {
            $grant = $this->db()->table('enrollment_grants')
                ->where('token_hash', $tokenHash)
                ->lockForUpdate()
                ->first();

            if ($grant === null) {
                throw EnrollmentRejected::because(EnrollmentRejected::REASON_UNKNOWN);
            }

            if ($grant->consumed_at !== null) {
                throw EnrollmentRejected::because(
                    EnrollmentRejected::REASON_CONSUMED,
                    $grant->organization_id
                );
            }

            if (CarbonImmutable::parse($grant->expires_at)->isPast()) {
                throw EnrollmentRejected::because(
                    EnrollmentRejected::REASON_EXPIRED,
                    $grant->organization_id
                );
            }

            $claimed = $this->db()->table('enrollment_grants')
                ->where('id', $grant->id)
                ->whereNull('consumed_at')
                ->update([
                    'consumed_at' => now(),
                    'consumed_by_hostname' => $reported['hostname'] ?? null,
                    'consumed_by_ip' => $ipAddress,
                    'updated_at' => now(),
                ]);

            if ($claimed === 0) {
                // Another request took it between the read and the write.
                throw EnrollmentRejected::because(
                    EnrollmentRejected::REASON_CONSUMED,
                    $grant->organization_id
                );
            }

            return $grant;
        });
    }

    /**
     * Load or create an organisation's CA.
     *
     * The private key lives in the vault, which means it inherits per-tenant
     * data keys and the wrapped-at-rest guarantee rather than sitting in a
     * column next to the certificate.
     */
    public function certificateAuthorityFor(string $organizationId): CertificateAuthority
    {
        $row = $this->db()->table('organization_certificate_authorities')
            ->where('organization_id', $organizationId)
            ->first();

        if ($row !== null) {
            $keyPem = $this->vault->get($organizationId, self::CA_KEY_SECRET);

            if ($keyPem === null) {
                throw new PkiException(sprintf(
                    'Organisation %s has a CA certificate but no key in the vault.',
                    $organizationId
                ));
            }

            return CertificateAuthority::load($row->certificate_pem, $keyPem);
        }

        $ca = CertificateAuthority::create(sprintf('%s organisation CA', $organizationId));

        // Key into the vault first. A certificate recorded without its key
        // would be unusable and unrecoverable.
        $this->vault->put($organizationId, self::CA_KEY_SECRET, $this->extractKey($ca));

        $this->db()->table('organization_certificate_authorities')->insert([
            'organization_id' => $organizationId,
            'certificate_pem' => $ca->certificatePem(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $ca;
    }

    private function recordNode(
        object $grant,
        string $certificatePem,
        DateTimeInterface $notAfter,
        array $reported,
    ): void {
        $attributes = [
            'organization_id' => $grant->organization_id,
            'certificate_pem' => $certificatePem,
            'certificate_expires_at' => $notAfter,
            'agent_version' => $reported['agent_version'] ?? null,
            'hostname' => $reported['hostname'] ?? null,
            'os' => $reported['os'] ?? null,
            'arch' => $reported['arch'] ?? null,
            'enrolled_at' => now(),
            'updated_at' => now(),
        ];

        $existing = $this->db()->table('nodes')->where('node_id', $grant->node_id)->first();

        if ($existing !== null) {
            $this->db()->table('nodes')->where('id', $existing->id)->update($attributes);

            return;
        }

        // The provider is set once, when the node first appears: the one that
        // made it, or custom for a machine the user provided.
        $slug = strstr((string) $grant->node_id, '.', true) ?: (string) $grant->node_id;
        $provider = $this->db()->table('resources')->where('organization_id', $grant->organization_id)
            ->where('type', 'server')->where('slug', $slug)->value('provider') ?: 'custom';

        $this->db()->table('nodes')->insert(array_merge($attributes, [
            'node_id' => $grant->node_id,
            'provider' => $provider,
            'created_at' => now(),
        ]));
    }

    private function auditRejection(?string $organizationId, string $reason, array $reported, ?string $ipAddress): void
    {
        // An unknown token has no organisation to attribute to. Record it
        // under a sentinel rather than dropping it: repeated unknown-token
        // attempts are exactly what probing looks like.
        $this->audit->record(
            organizationId: $organizationId ?? '_unattributed',
            actor: Actor::system('enrollment'),
            action: 'enrollment.rejected',
            outcome: Outcome::Denied,
            reason: $reason,
            metadata: array_merge($this->reportedMetadata($reported), ['ip' => $ipAddress]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function reportedMetadata(array $reported): array
    {
        return [
            'hostname' => $reported['hostname'] ?? null,
            'agent_version' => $reported['agent_version'] ?? null,
            'os' => $reported['os'] ?? null,
            'arch' => $reported['arch'] ?? null,
        ];
    }

    private function extractKey(CertificateAuthority $ca): string
    {
        // CertificateAuthority keeps its key private; creation hands it back
        // exactly once through this accessor so it can be vaulted.
        return $ca->exportPrivateKey();
    }

    /**
     * 32 random bytes, base64url without padding, matching enroll.NewToken.
     */
    private static function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
    }

    /**
     * Tokens that were never redeemed and can no longer be.
     *
     * Expired grants are kept for a window so a late attempt still reads as
     * "expired" rather than "unknown", then swept.
     */
    public function pruneExpired(?DateTimeInterface $olderThan = null): int
    {
        $cutoff = $olderThan ?? CarbonImmutable::now()->subDays(30);

        return $this->db()->table('enrollment_grants')
            ->whereNull('consumed_at')
            ->where('expires_at', '<', $cutoff)
            ->delete();
    }
}
