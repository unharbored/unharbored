<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Lib\Audit\AuditLog;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Enrollment\Exceptions\EnrollmentRejected;
use App\Lib\Pki\CertificateAuthority;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;
use Tests\TestCase;

final class EnrollmentServiceTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentService $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        $this->enrollment = new EnrollmentService(
            $this->app->make(Vault::class),
            new AuditLog(),
        );
    }

    /**
     * Build a key and CSR as the agent does. The common name is advisory and
     * the control plane is expected to ignore it.
     *
     * @return array{0: string, 1: string} key PEM, CSR PEM
     */
    private function makeCsr(string $requestedName = 'node'): array
    {
        $key = EC::createKey('secp256r1');

        $csr = new X509();
        $csr->setPrivateKey($key);
        $csr->setDN(['CN' => $requestedName]);

        $signed = $csr->signCSR();
        $this->assertNotFalse($signed, 'Could not build a test CSR.');

        return [$key->toString('PKCS8'), $csr->saveCSR($signed)];
    }

    // ---- happy path ----

    public function test_a_node_enrolls_and_receives_a_certificate(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [, $csr] = $this->makeCsr();

        $result = $this->enrollment->enroll($token, $csr, [
            'hostname' => 'web-1',
            'agent_version' => '0.1.0',
            'os' => 'linux',
            'arch' => 'amd64',
        ], '203.0.113.5');

        $this->assertSame('node-123', $result->nodeId);
        $this->assertSame('org-1', $result->organizationId);
        $this->assertSame('node-123', CertificateAuthority::commonName($result->certificatePem));
        $this->assertNotSame('', $result->caCertificatePem);
        $this->assertTrue($result->notAfter > new \DateTimeImmutable());
    }

    public function test_enrollment_records_the_node(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [, $csr] = $this->makeCsr();

        $this->enrollment->enroll($token, $csr, ['hostname' => 'web-1', 'os' => 'linux', 'arch' => 'amd64']);

        $node = DB::table('nodes')->where('node_id', 'node-123')->first();

        $this->assertNotNull($node);
        $this->assertSame('org-1', $node->organization_id);
        $this->assertSame('web-1', $node->hostname);
        $this->assertNotNull($node->enrolled_at);
    }

    /**
     * The CA private key mints identities for an entire organisation. It
     * belongs in the vault, inheriting per-tenant data keys, not in a column
     * beside the certificate.
     */
    public function test_the_ca_private_key_is_stored_in_the_vault(): void
    {
        $this->enrollment->certificateAuthorityFor('org-1');

        $this->assertTrue(
            $this->app->make(Vault::class)->has('org-1', EnrollmentService::CA_KEY_SECRET)
        );

        // And nowhere in the CA table.
        $row = DB::table('organization_certificate_authorities')->first();
        $this->assertStringNotContainsString('PRIVATE KEY', $row->certificate_pem);
    }

    public function test_each_organisation_gets_its_own_ca(): void
    {
        $a = $this->enrollment->certificateAuthorityFor('org-1');
        $b = $this->enrollment->certificateAuthorityFor('org-2');

        $this->assertNotSame($a->certificatePem(), $b->certificatePem());
    }

    public function test_the_ca_is_reused_across_enrollments(): void
    {
        $first = $this->enrollment->certificateAuthorityFor('org-1')->certificatePem();
        $second = $this->enrollment->certificateAuthorityFor('org-1')->certificatePem();

        $this->assertSame($first, $second);
        $this->assertDatabaseCount('organization_certificate_authorities', 1);
    }

    // ---- the identity property ----

    /**
     * The single most important property in this service. A node holding a
     * valid token for node-a must not be able to become node-b.
     */
    public function test_a_node_cannot_choose_its_own_identity(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-a');
        [, $csr] = $this->makeCsr('i-am-the-control-plane');

        $result = $this->enrollment->enroll($token, $csr, ['hostname' => 'node-b']);

        $this->assertSame('node-a', $result->nodeId);
        $this->assertSame('node-a', CertificateAuthority::commonName($result->certificatePem));
    }

    // ---- token lifecycle ----

    public function test_a_token_survives_exactly_one_use(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [, $csr1] = $this->makeCsr();
        [, $csr2] = $this->makeCsr();

        $this->enrollment->enroll($token, $csr1);

        try {
            $this->enrollment->enroll($token, $csr2);
            $this->fail('A spent token enrolled a second time.');
        } catch (EnrollmentRejected $e) {
            $this->assertSame(EnrollmentRejected::REASON_CONSUMED, $e->cause());
        }
    }

    public function test_an_expired_token_is_rejected(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123', ttlSeconds: 60);
        [, $csr] = $this->makeCsr();

        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));

        try {
            $this->enrollment->enroll($token, $csr);
            $this->fail('An expired token was accepted.');
        } catch (EnrollmentRejected $e) {
            $this->assertSame(EnrollmentRejected::REASON_EXPIRED, $e->cause());
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_an_unknown_token_is_rejected(): void
    {
        [, $csr] = $this->makeCsr();

        try {
            $this->enrollment->enroll('not-a-real-token', $csr);
            $this->fail('An unknown token was accepted.');
        } catch (EnrollmentRejected $e) {
            $this->assertSame(EnrollmentRejected::REASON_UNKNOWN, $e->cause());
        }
    }

    /**
     * Otherwise one token grants unlimited attempts and stops being single
     * use.
     */
    public function test_a_malformed_csr_still_burns_the_token(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');

        try {
            $this->enrollment->enroll($token, 'this is not a csr');
            $this->fail('A garbage CSR was accepted.');
        } catch (EnrollmentRejected $e) {
            $this->assertSame(EnrollmentRejected::REASON_BAD_CSR, $e->cause());
        }

        [, $csr] = $this->makeCsr();

        try {
            $this->enrollment->enroll($token, $csr);
            $this->fail('The token survived a malformed attempt.');
        } catch (EnrollmentRejected $e) {
            $this->assertSame(EnrollmentRejected::REASON_CONSUMED, $e->cause());
        }
    }

    /**
     * An unauthenticated caller must not be able to tell an unknown token
     * from an expired or spent one, or the endpoint is an oracle.
     */
    public function test_every_rejection_looks_identical_on_the_wire(): void
    {
        $spent = $this->enrollment->issueToken('org-1', 'node-a');
        [, $csr] = $this->makeCsr();
        $this->enrollment->enroll($spent, $csr);

        $expired = $this->enrollment->issueToken('org-1', 'node-b', ttlSeconds: 1);
        CarbonImmutable::setTestNow(CarbonImmutable::now()->addMinutes(5));

        $messages = [];
        foreach ([$spent, $expired, 'completely-unknown'] as $token) {
            try {
                [, $fresh] = $this->makeCsr();
                $this->enrollment->enroll($token, $fresh);
                $this->fail('Expected rejection.');
            } catch (EnrollmentRejected $e) {
                $messages[] = $e->getMessage();
            }
        }
        CarbonImmutable::setTestNow();

        $this->assertCount(3, array_filter($messages, fn ($m) => $m === $messages[0]));

        foreach (['expired', 'consumed', 'used', 'unknown'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $messages[0]);
        }
    }

    /**
     * Only the hash is stored, so a leak of this table is not replayable.
     */
    public function test_the_plaintext_token_is_never_stored(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');

        $row = DB::table('enrollment_grants')->first();

        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(EnrollmentService::hashToken($token), $row->token_hash);
        $this->assertStringNotContainsString($token, (string) json_encode($row));
    }

    public function test_a_spent_grant_is_kept_rather_than_deleted(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [, $csr] = $this->makeCsr();
        $this->enrollment->enroll($token, $csr, ['hostname' => 'web-1'], '203.0.113.9');

        $row = DB::table('enrollment_grants')->first();

        // Kept so a replay reads as "already used" rather than "unknown",
        // which is the difference between a retry and someone guessing.
        $this->assertNotNull($row);
        $this->assertNotNull($row->consumed_at);
        $this->assertSame('web-1', $row->consumed_by_hostname);
        $this->assertSame('203.0.113.9', $row->consumed_by_ip);
    }

    public function test_expired_grants_can_be_pruned(): void
    {
        $this->enrollment->issueToken('org-1', 'node-old', ttlSeconds: 60);
        $this->enrollment->issueToken('org-1', 'node-new', ttlSeconds: 86_400);

        $pruned = $this->enrollment->pruneExpired(CarbonImmutable::now()->addHour());

        $this->assertSame(1, $pruned);
        $this->assertDatabaseCount('enrollment_grants', 1);
    }

    // ---- audit ----

    public function test_enrollment_is_audited(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [, $csr] = $this->makeCsr();
        $this->enrollment->enroll($token, $csr, ['hostname' => 'web-1']);

        $actions = DB::table('audit_records')
            ->where('organization_id', 'org-1')
            ->orderBy('sequence')
            ->pluck('action')
            ->all();

        $this->assertContains('enrollment.token_issued', $actions);
        $this->assertContains('enrollment.completed', $actions);
        $this->assertTrue((new AuditLog())->verify('org-1')->intact);
    }

    /**
     * Repeated unknown-token attempts are what probing looks like, so they
     * must be recorded even though there is no organisation to attribute
     * them to.
     */
    public function test_rejections_are_audited_even_when_unattributable(): void
    {
        [, $csr] = $this->makeCsr();

        try {
            $this->enrollment->enroll('unknown-token', $csr, ['hostname' => 'attacker']);
        } catch (EnrollmentRejected) {
            // expected
        }

        $record = DB::table('audit_records')->where('organization_id', '_unattributed')->first();

        $this->assertNotNull($record);
        $this->assertSame('enrollment.rejected', $record->action);
        $this->assertSame('denied', $record->outcome);
    }

    // ---- certificate properties ----

    public function test_issued_certificates_chain_to_the_organisation_ca(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [, $csr] = $this->makeCsr();
        $result = $this->enrollment->enroll($token, $csr);

        $cert = new X509();
        $cert->loadX509($result->certificatePem);
        $cert->loadCA($result->caCertificatePem);

        $this->assertTrue($cert->validateSignature(), 'The node certificate does not chain to the CA.');
    }

    public function test_a_certificate_from_another_organisation_does_not_chain(): void
    {
        $tokenA = $this->enrollment->issueToken('org-1', 'node-a');
        [, $csrA] = $this->makeCsr();
        $resultA = $this->enrollment->enroll($tokenA, $csrA);

        $otherCa = $this->enrollment->certificateAuthorityFor('org-2');

        $cert = new X509();
        $cert->loadX509($resultA->certificatePem);
        $cert->loadCA($otherCa->certificatePem());

        $this->assertNotTrue(
            $cert->validateSignature(),
            'A certificate validated against a different organisation CA.'
        );
    }

    public function test_issued_certificates_are_client_auth_only(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [, $csr] = $this->makeCsr();
        $result = $this->enrollment->enroll($token, $csr);

        $cert = new X509();
        $cert->loadX509($result->certificatePem);

        $extKeyUsage = $cert->getExtension('id-ce-extKeyUsage');
        $this->assertSame(['id-kp-clientAuth'], $extKeyUsage);

        $basicConstraints = $cert->getExtension('id-ce-basicConstraints');
        $this->assertNotTrue($basicConstraints['cA'] ?? false, 'A node certificate must not be a CA.');
    }

    /**
     * The CA must actually be marked as one, or nothing it signs chains.
     *
     * Worth asserting separately from node certificates: phpseclib's sign()
     * discards extensions set with setExtension, but makeCA() sets a flag
     * that sign() honours internally. The two paths behave differently and
     * only one of them was broken.
     */
    public function test_the_ca_certificate_is_marked_as_a_ca(): void
    {
        $ca = $this->enrollment->certificateAuthorityFor('org-1');

        $cert = new X509();
        $cert->loadX509($ca->certificatePem());

        $this->assertSame(['cA' => true], $cert->getExtension('id-ce-basicConstraints'));
    }

    /**
     * Regression. phpseclib's sign() rebuilds the certificate body and
     * silently drops extensions set beforehand: setExtension returns true,
     * the certificate comes out unrestricted, and nothing complains. What it
     * drops is the restriction that stops a compromised node impersonating
     * the control plane to its peers.
     */
    public function test_extensions_survive_signing(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [, $csr] = $this->makeCsr();
        $result = $this->enrollment->enroll($token, $csr);

        $cert = new X509();
        $cert->loadX509($result->certificatePem);

        $extensions = $cert->getExtensions();

        $this->assertContains('id-ce-extKeyUsage', $extensions);
        $this->assertContains('id-ce-keyUsage', $extensions);
        $this->assertContains('id-ce-basicConstraints', $extensions);
    }

    /**
     * The public key in the issued certificate must be the one from the CSR,
     * not one the two-pass signing quietly substituted.
     */
    public function test_the_certificate_carries_the_key_from_the_request(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-123');
        [$keyPem, $csr] = $this->makeCsr();
        $result = $this->enrollment->enroll($token, $csr);

        $expected = EC::loadPrivateKey($keyPem)->getPublicKey()->toString('PKCS8');

        $cert = new X509();
        $cert->loadX509($result->certificatePem);

        $this->assertSame($expected, $cert->getPublicKey()->toString('PKCS8'));
    }

    /**
     * Garbage must produce a typed rejection, not an exception from deep in a
     * crypto library that nothing upstream is catching. This endpoint is
     * unauthenticated, so it is the one most likely to receive garbage.
     */
    public function test_hostile_csr_input_produces_a_typed_rejection(): void
    {
        foreach (['not base64 at all !!!', '-----BEGIN CERTIFICATE REQUEST-----\n!!!\n-----END CERTIFICATE REQUEST-----', str_repeat('A', 10_000), "\x00\x01\x02"] as $garbage) {
            $token = $this->enrollment->issueToken('org-1', 'node-'.md5($garbage));

            try {
                $this->enrollment->enroll($token, $garbage);
                $this->fail('Garbage CSR was accepted.');
            } catch (EnrollmentRejected $e) {
                $this->assertSame(EnrollmentRejected::REASON_BAD_CSR, $e->cause());
            }
        }
    }

    public function test_issuing_requires_a_node_id(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->enrollment->issueToken('org-1', '  ');
    }
}
