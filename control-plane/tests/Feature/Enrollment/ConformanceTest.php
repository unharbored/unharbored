<?php

declare(strict_types=1);

namespace Tests\Feature\Enrollment;

use App\Lib\Enrollment\EnrollmentService;
use PHPUnit\Framework\TestCase;

/**
 * Asserts that this control plane agrees with the Go agent about the wire.
 *
 * ADR 0002 names cross-language drift as the main cost of writing the agent
 * and the control plane in different languages. These fixtures are generated
 * by the Go implementation (go run ./cmd/conformance) and committed, so a
 * change on either side that breaks the agreement fails here rather than in
 * production, where the symptom would be agents that silently cannot enroll.
 *
 * Regenerate after any protocol change:
 *
 *   go run ./cmd/conformance > control-plane/tests/fixtures/conformance.json
 *
 * If regenerating makes these tests fail, that is the point: look at what
 * changed before accepting it.
 */
final class ConformanceTest extends TestCase
{
    /** @var array<string, mixed> */
    private static array $fixtures;

    public static function setUpBeforeClass(): void
    {
        $path = __DIR__.'/../../fixtures/conformance.json';

        if (! is_file($path)) {
            self::fail(
                'Conformance fixtures are missing. Generate them with: '
                .'go run ./cmd/conformance > control-plane/tests/fixtures/conformance.json'
            );
        }

        self::$fixtures = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * If the two sides hash differently, no node can ever enroll. That fails
     * loudly, but only once a real agent tries.
     */
    public function test_token_hashing_matches_the_agent(): void
    {
        $cases = self::$fixtures['token_hashes'];
        $this->assertNotEmpty($cases);

        foreach ($cases as $case) {
            $this->assertSame(
                $case['sha256'],
                EnrollmentService::hashToken($case['token']),
                sprintf('Token hash disagrees with the agent for %s.', var_export($case['token'], true))
            );
        }
    }

    public function test_token_length_matches_the_agent(): void
    {
        $this->assertSame(
            self::$fixtures['constants']['token_bytes'],
            EnrollmentService::TOKEN_BYTES
        );
    }

    public function test_default_token_ttl_matches_the_agent(): void
    {
        $this->assertSame(
            self::$fixtures['constants']['default_token_ttl_s'],
            EnrollmentService::DEFAULT_TTL_SECONDS
        );
    }

    /**
     * A token this control plane generates must be a shape the agent can
     * carry: base64url, no padding, and the agreed entropy.
     */
    public function test_generated_tokens_have_the_agreed_shape(): void
    {
        $method = new \ReflectionMethod(EnrollmentService::class, 'generateToken');
        $method->setAccessible(true);

        for ($i = 0; $i < 50; $i++) {
            $token = $method->invoke(null);

            $this->assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $token, 'Token is not base64url.');
            $this->assertStringNotContainsString('=', $token, 'Token carries base64 padding.');

            $decoded = base64_decode(strtr($token, '-_', '+/'), true);
            $this->assertIsString($decoded);
            $this->assertSame(EnrollmentService::TOKEN_BYTES, strlen($decoded));
        }
    }

    /**
     * Field names in the envelope are the contract. A rename on either side
     * would mean the other silently ignoring messages it does not recognise.
     */
    public function test_envelope_field_names_are_understood(): void
    {
        $required = ['v', 'id', 'type', 'ts'];

        foreach (self::$fixtures['envelopes'] as $case) {
            $envelope = json_decode((string) json_encode($case['json']), true);
            if (! is_array($envelope)) {
                $envelope = $case['json'];
            }

            foreach ($required as $field) {
                $this->assertArrayHasKey(
                    $field,
                    $envelope,
                    sprintf('The %s envelope is missing %s.', $case['description'], $field)
                );
            }

            $this->assertSame(self::$fixtures['protocol_version'], $envelope['v']);
            $this->assertSame(32, strlen((string) $envelope['id']), 'Message ids are 128 bits of hex.');
        }
    }

    public function test_the_enrollment_response_shape_matches_what_the_agent_parses(): void
    {
        // enroll.Response in the agent. Drift here means an agent that
        // enrolls successfully and then cannot find its own certificate.
        $expected = ['node_id', 'org_id', 'cert_pem', 'ca_pem', 'control_plane_addr', 'not_after'];

        $result = new \App\Lib\Enrollment\EnrollmentResult(
            nodeId: 'node-1',
            organizationId: 'org-1',
            certificatePem: 'cert',
            caCertificatePem: 'ca',
            notAfter: new \DateTimeImmutable('2026-12-04T00:00:00+00:00'),
        );

        $this->assertSame($expected, array_keys($result->toArray('cp.example:9443')));
    }

    /**
     * The control plane builds release identifiers too. One it generates that
     * the agent then rejects would fail a deploy at the last possible moment.
     */
    public function test_release_id_format_is_understood(): void
    {
        foreach (self::$fixtures['release_ids'] as $case) {
            $this->assertTrue(
                (bool) $case['valid'],
                sprintf('The agent generated an id it considers invalid for ref %s.', var_export($case['ref'], true))
            );

            $this->assertMatchesRegularExpression(
                '/^[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9._-]{1,64}$/',
                $case['id'],
                sprintf('Release id %s does not match the agreed format.', $case['id'])
            );

            // Hostile refs must never survive into a path segment.
            $this->assertStringNotContainsString('..', $case['id']);
            $this->assertStringNotContainsString('/', $case['id']);
            $this->assertStringNotContainsString('\\', $case['id']);
        }
    }

    /**
     * The gap that let a real bug through.
     *
     * Field names alone were not enough. CSRPEM, CertPEM and CAPEM were once
     * []byte on the Go side, which encodes to base64 in JSON, while this
     * control plane sent plain PEM. Both sides used the agreed field names
     * and the first live enrollment still failed with "illegal base64 data at
     * input byte 0", because nothing had ever compared the values.
     */
    public function test_pem_fields_travel_as_plain_text_not_base64(): void
    {
        $enrollment = self::$fixtures['enrollment'];

        $this->assertTrue(
            $enrollment['pem_fields_are_plain_text'],
            'The agent no longer sends PEM as text. One side has changed encoding.'
        );

        foreach ([
            ['request_json', 'csr_pem', 'CERTIFICATE REQUEST'],
            ['response_json', 'cert_pem', 'CERTIFICATE'],
            ['response_json', 'ca_pem', 'CERTIFICATE'],
        ] as [$message, $field, $marker]) {
            $value = $enrollment[$message][$field];

            $this->assertStringContainsString(
                '-----BEGIN '.$marker.'-----',
                $value,
                sprintf('%s.%s is not readable PEM; it may have become base64.', $message, $field)
            );
            // chr(10) rather than an escape sequence, so the assertion cannot
            // be silently broken by the quoting of whatever edits this file.
            $this->assertStringContainsString(chr(10), $value, $field.' has no line breaks.');
        }
    }

    /**
     * What this control plane emits must be shaped like what the agent
     * expects to parse.
     */
    public function test_our_response_matches_the_shape_the_agent_parses(): void
    {
        $expected = self::$fixtures['enrollment']['response_json'];

        $ours = (new \App\Lib\Enrollment\EnrollmentResult(
            nodeId: 'web-1.org-1',
            organizationId: 'org-1',
            certificatePem: $expected['cert_pem'],
            caCertificatePem: $expected['ca_pem'],
            notAfter: new \DateTimeImmutable('2026-12-04T00:00:00+00:00'),
        ))->toArray('cp.example:9443');

        $this->assertSame(array_keys($expected), array_keys($ours));

        // And the PEM must come back out unchanged, not re-encoded.
        $this->assertSame($expected['cert_pem'], $ours['cert_pem']);
        $this->assertSame($expected['ca_pem'], $ours['ca_pem']);
    }

    /**
     * The request the agent sends must be one this control plane accepts.
     */
    public function test_we_accept_the_request_the_agent_sends(): void
    {
        $request = self::$fixtures['enrollment']['request_json'];

        foreach (['token', 'csr_pem', 'agent_version', 'hostname', 'os', 'arch'] as $field) {
            $this->assertArrayHasKey($field, $request);
        }

        // Within the validation limits the endpoint enforces.
        $this->assertLessThanOrEqual(512, strlen($request['token']));
        $this->assertLessThanOrEqual(16384, strlen($request['csr_pem']));
    }

    public function test_known_command_kinds_are_enumerated(): void
    {
        // Pinned so adding a command on one side without the other is
        // visible rather than silent.
        $this->assertSame(
            ['apply_recipe', 'deploy', 'activate_release', 'rollback', 'exec', 'collect_metrics', 'prune_releases'],
            self::$fixtures['command_kinds']
        );
    }

    public function test_known_message_types_are_enumerated(): void
    {
        $this->assertSame(
            [
                'hello', 'hello_ack', 'ping', 'pong', 'command', 'command_ack',
                'progress', 'result', 'metrics', 'log_batch', 'error',
            ],
            self::$fixtures['message_types']
        );
    }

    public function test_known_error_codes_are_enumerated(): void
    {
        $this->assertSame(
            [
                'unsupported_version', 'identity_mismatch', 'malformed',
                'unknown_type', 'frame_too_large', 'internal',
            ],
            self::$fixtures['error_codes']
        );
    }

    public function test_frame_limits_are_agreed(): void
    {
        $this->assertSame(8 * 1024 * 1024, self::$fixtures['constants']['max_frame_size']);
        $this->assertSame(4, self::$fixtures['constants']['frame_header_bytes']);
    }
}
