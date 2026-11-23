<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Lib\Audit\AuditLog;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Pki\CertificateAuthority;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;
use Tests\TestCase;

/**
 * The only unauthenticated endpoint in the system, so these tests are mostly
 * about what it must refuse to tell a caller.
 */
final class EnrollmentEndpointTest extends TestCase
{
    use RefreshDatabase;

    private EnrollmentService $enrollment;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'vault.master_key' => LocalKeyManager::generateMasterKey(),
            'control_plane.session_address' => 'cp.example.test:9443',
        ]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        RateLimiter::clear('enrollment');

        $this->enrollment = $this->app->make(EnrollmentService::class);

        DB::table('organizations')->insert([
            'id' => 'org-1', 'name' => 'Test', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Build a key and CSR the way the agent does.
     *
     * @return array{0: string, 1: string} key PEM, CSR PEM
     */
    private function makeCsr(string $cn = 'node'): array
    {
        $key = EC::createKey('secp256r1');

        $csr = new X509();
        $csr->setPrivateKey($key);
        $csr->setDN(['CN' => $cn]);

        return [$key->toString('PKCS8'), $csr->saveCSR($csr->signCSR())];
    }

    public function test_a_valid_request_returns_a_certificate(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-1');
        [, $csr] = $this->makeCsr();

        $response = $this->postJson('/api/enroll', [
            'token' => $token,
            'csr_pem' => $csr,
            'agent_version' => '0.1.0',
            'hostname' => 'web-1',
            'os' => 'linux',
            'arch' => 'amd64',
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'node_id', 'org_id', 'cert_pem', 'ca_pem', 'control_plane_addr', 'not_after',
            ])
            ->assertJsonPath('node_id', 'node-1')
            ->assertJsonPath('org_id', 'org-1')
            ->assertJsonPath('control_plane_addr', 'cp.example.test:9443');

        $cert = $response->json('cert_pem');
        $this->assertSame('node-1', CertificateAuthority::commonName($cert));
    }

    /**
     * Go marshals a []byte field to base64, so a request from our own agent
     * arrives encoded rather than as raw PEM.
     */
    public function test_a_base64_encoded_csr_is_accepted(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-1');
        [, $csr] = $this->makeCsr();

        $this->postJson('/api/enroll', [
            'token' => $token,
            'csr_pem' => base64_encode($csr),
        ])->assertOk();
    }

    /**
     * The defining property. An unauthenticated caller must not be able to
     * tell an unknown token from an expired or spent one, or the endpoint
     * becomes an oracle for probing which tokens exist.
     */
    public function test_every_rejection_is_byte_identical(): void
    {
        $spent = $this->enrollment->issueToken('org-1', 'node-a');
        [, $csr] = $this->makeCsr();
        $this->postJson('/api/enroll', ['token' => $spent, 'csr_pem' => $csr])->assertOk();

        [, $fresh] = $this->makeCsr();

        $bodies = [];
        $statuses = [];

        foreach ([$spent, 'completely-unknown-token', str_repeat('x', 400)] as $token) {
            $response = $this->postJson('/api/enroll', ['token' => $token, 'csr_pem' => $fresh]);
            $bodies[] = $response->getContent();
            $statuses[] = $response->status();
        }

        $this->assertSame([403, 403, 403], $statuses);
        $this->assertCount(1, array_unique($bodies), 'Rejection bodies differ and leak why.');

        foreach (['expired', 'consumed', 'used', 'unknown', 'not found'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $bodies[0]);
        }
    }

    public function test_a_malformed_csr_is_rejected_the_same_way(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-1');

        $this->postJson('/api/enroll', ['token' => $token, 'csr_pem' => 'not a csr at all'])
            ->assertStatus(403)
            ->assertJsonPath('error', 'enrollment_rejected');
    }

    public function test_missing_fields_are_rejected(): void
    {
        $this->postJson('/api/enroll', [])->assertStatus(422);
        $this->postJson('/api/enroll', ['token' => 'x'])->assertStatus(422);
        $this->postJson('/api/enroll', ['csr_pem' => 'x'])->assertStatus(422);
    }

    /**
     * An unauthenticated caller should not be able to make us parse an
     * arbitrary amount of JSON.
     */
    public function test_oversized_fields_are_rejected(): void
    {
        $this->postJson('/api/enroll', [
            'token' => str_repeat('a', 5_000),
            'csr_pem' => 'x',
        ])->assertStatus(422);
    }

    /**
     * A body larger than any real agent would send is refused before it is
     * parsed. The status differs from an enrollment rejection deliberately:
     * body size says nothing about whether a token is valid, so a distinct
     * code leaks nothing and is more useful to a real client.
     */
    public function test_an_oversized_body_is_refused_before_parsing(): void
    {
        $this->postJson('/api/enroll', [
            'token' => 'x',
            'csr_pem' => str_repeat('a', 100_000),
        ])->assertStatus(413);
    }

    public function test_the_endpoint_is_rate_limited(): void
    {
        $limit = (int) config('control_plane.enrollment_attempts_per_minute');

        for ($i = 0; $i < $limit; $i++) {
            $this->postJson('/api/enroll', ['token' => 'nope', 'csr_pem' => 'nope'])
                ->assertStatus(403);
        }

        $this->postJson('/api/enroll', ['token' => 'nope', 'csr_pem' => 'nope'])
            ->assertStatus(429);
    }

    public function test_a_spent_token_cannot_be_reused_over_http(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-1');
        [, $first] = $this->makeCsr();
        [, $second] = $this->makeCsr();

        $this->postJson('/api/enroll', ['token' => $token, 'csr_pem' => $first])->assertOk();
        $this->postJson('/api/enroll', ['token' => $token, 'csr_pem' => $second])->assertStatus(403);
    }

    /**
     * The node identity comes from the grant. A caller holding a token for
     * node-a must not become node-b by asking.
     */
    public function test_the_caller_cannot_choose_its_identity_over_http(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-a');
        [, $csr] = $this->makeCsr('node-b-please');

        $response = $this->postJson('/api/enroll', [
            'token' => $token,
            'csr_pem' => $csr,
            'hostname' => 'node-b',
        ]);

        $response->assertOk()->assertJsonPath('node_id', 'node-a');
        $this->assertSame('node-a', CertificateAuthority::commonName($response->json('cert_pem')));
    }

    public function test_the_response_carries_the_ca_so_the_agent_can_pin_it(): void
    {
        $token = $this->enrollment->issueToken('org-1', 'node-1');
        [, $csr] = $this->makeCsr();

        $response = $this->postJson('/api/enroll', ['token' => $token, 'csr_pem' => $csr]);

        $caPem = $response->json('ca_pem');
        $certPem = $response->json('cert_pem');

        $this->assertStringContainsString('BEGIN CERTIFICATE', $caPem);

        // The issued certificate must actually chain to the CA we handed back,
        // or the agent pins something that cannot verify it.
        $cert = new X509();
        $cert->loadX509($certPem);
        $cert->loadCA($caPem);
        $this->assertTrue($cert->validateSignature());
    }

    public function test_rejections_are_audited(): void
    {
        $this->postJson('/api/enroll', ['token' => 'unknown', 'csr_pem' => 'x'])->assertStatus(403);

        $this->assertNotNull(
            DB::table('audit_records')->where('action', 'enrollment.rejected')->first()
        );
    }

    // ---- server certificates ----

    /**
     * Agents pin the organisation CA and verify the server name. That only
     * works if whatever terminates TLS presents a certificate from the same
     * CA, so the CA has to be able to mint one.
     */
    public function test_the_ca_can_issue_a_server_certificate(): void
    {
        $ca = $this->enrollment->certificateAuthorityFor('org-1');

        [$certPem, $keyPem] = $ca->issueServerCertificate('cp.example.test');

        $this->assertStringContainsString('BEGIN CERTIFICATE', $certPem);
        $this->assertStringContainsString('PRIVATE KEY', $keyPem);

        $cert = new X509();
        $cert->loadX509($certPem);

        $this->assertSame(['id-kp-serverAuth'], $cert->getExtension('id-ce-extKeyUsage'));

        // Go and every modern TLS client ignore the common name and require a
        // subject alternative name. Without one the agent refuses to connect
        // regardless of what the CN says.
        $san = $cert->getExtension('id-ce-subjectAltName');
        $this->assertNotFalse($san, 'The server certificate has no subject alternative name.');

        $cert->loadCA($ca->certificatePem());
        $this->assertTrue($cert->validateSignature());
    }

    /**
     * A server certificate must not be usable as a node certificate, and a
     * node certificate must not be usable as a server one.
     */
    public function test_server_and_node_certificates_are_not_interchangeable(): void
    {
        $ca = $this->enrollment->certificateAuthorityFor('org-1');

        [$serverPem] = $ca->issueServerCertificate('cp.example.test');
        [, $csr] = $this->makeCsr();
        $nodePem = $ca->issueNodeCertificate($csr, 'node-1');

        $server = new X509();
        $server->loadX509($serverPem);
        $node = new X509();
        $node->loadX509($nodePem);

        $this->assertSame(['id-kp-serverAuth'], $server->getExtension('id-ce-extKeyUsage'));
        $this->assertSame(['id-kp-clientAuth'], $node->getExtension('id-ce-extKeyUsage'));
    }
}
