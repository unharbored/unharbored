<?php

declare(strict_types=1);

namespace Tests\Feature\Gateway;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\Exceptions\CommandOutcomeUnknown;
use App\Lib\Gateway\Exceptions\GatewayUnavailable;
use App\Lib\Gateway\Exceptions\NodeNotConnected;
use App\Lib\Gateway\GatewayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The distinction these tests exist to protect is between a command that
 * failed and one whose outcome is unknown.
 *
 * They look alike and are not. A failure can be retried. An unknown outcome
 * means the work may already have happened, and retrying a release activation
 * or a migration that actually ran is how a deploy corrupts data while
 * appearing to recover.
 */
final class GatewayClientTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->http = new HttpFactory();
    }

    private function client(): GatewayClient
    {
        return new GatewayClient(
            baseUrl: 'http://gateway.test:9444',
            token: 'shared-secret',
            http: $this->http,
            audit: new AuditLog(),
        );
    }

    public function test_the_gateway_waits_as_long_as_the_command_may_run(): void
    {
        $waits = [];
        $this->http->fake(function (Request $r) use (&$waits) {
            $waits[] = $r['wait_seconds'];

            return $this->http->response(['status' => 'succeeded', 'outcome_known' => true], 200);
        });

        $this->client()->run('org-1', 'web-1', 'exec', ['name' => 'php']);
        $this->client()->run('org-1', 'web-1', 'exec', ['name' => 'php'], timeoutSeconds: 60);
        $this->client()->run('org-1', 'web-1', 'exec', ['name' => 'php'], timeoutSeconds: 1800);
        $this->client()->run('org-1', 'web-1', 'exec', ['name' => 'php'], timeoutSeconds: 1800, waitSeconds: 45);

        $this->assertSame([120, 120, 1830, 45], $waits, 'The default, the default for a short command, the command\'s own time, and what a caller asked for.');
    }

    public function test_a_successful_command_returns_its_result(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'status' => 'succeeded',
            'exit_code' => 0,
            'duration_ms' => 1234,
            'outcome_known' => true,
        ], 200)]);

        $result = $this->client()->run('org-1', 'web-1', 'exec', ['name' => 'php']);

        $this->assertSame('succeeded', $result['status']);
        $this->assertSame(1234, $result['duration_ms']);
    }

    public function test_requests_carry_the_shared_secret(): void
    {
        $this->http->fake(['*' => $this->http->response(['status' => 'succeeded', 'outcome_known' => true], 200)]);

        $this->client()->run('org-1', 'web-1', 'exec');

        $this->http->assertSent(fn (Request $r) => $r->hasHeader(GatewayClient::AUTH_HEADER, 'shared-secret'));
    }

    public function test_a_client_cannot_be_built_without_a_token(): void
    {
        // An unauthenticated link to the gateway would let anything that can
        // reach it run commands as root on every node.
        $this->expectException(GatewayUnavailable::class);

        new GatewayClient('http://gateway.test', '', $this->http, new AuditLog());
    }

    public function test_the_idempotency_key_is_passed_through(): void
    {
        $this->http->fake(['*' => $this->http->response(['status' => 'succeeded', 'outcome_known' => true], 200)]);

        $this->client()->run('org-1', 'web-1', 'activate_release', idempotencyKey: 'deploy-42');

        $this->http->assertSent(fn (Request $r) => ($r->data()['idempotency_key'] ?? null) === 'deploy-42');
    }

    // ---- the three failure shapes ----

    /**
     * Nothing was sent, so nothing ran. Safe to retry once the node is back.
     */
    public function test_an_unconnected_node_is_distinct_from_a_failure(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'status' => 'unavailable',
            'error' => 'gateway: node is not connected: web-1',
            'outcome_known' => false,
        ], 409)]);

        $this->expectException(NodeNotConnected::class);

        $this->client()->run('org-1', 'web-1', 'exec');
    }

    /**
     * The gateway stopped waiting. The command may still be running on the
     * node, so this must not be reported as a clean failure.
     */
    public function test_a_gateway_timeout_is_an_unknown_outcome(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'status' => 'timed_out_waiting',
            'error' => 'the gateway stopped waiting; the command may still be running',
            'outcome_known' => false,
        ], 504)]);

        $this->expectException(CommandOutcomeUnknown::class);

        $this->client()->run('org-1', 'web-1', 'activate_release');
    }

    /**
     * The gateway was unreachable. It may have received the command and
     * dispatched it before we lost contact.
     */
    public function test_an_unreachable_gateway_is_an_unknown_outcome(): void
    {
        $this->http->fake(fn () => throw new ConnectionException('connection refused'));

        $this->expectException(CommandOutcomeUnknown::class);

        $this->client()->run('org-1', 'web-1', 'activate_release');
    }

    /**
     * Any response the gateway marks as uncertain is uncertain, whatever its
     * status code. The flag is the contract, not the status.
     */
    public function test_outcome_known_false_is_honoured_regardless_of_status(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'status' => 'failed',
            'error' => 'session ended mid-command',
            'outcome_known' => false,
        ], 200)]);

        $this->expectException(CommandOutcomeUnknown::class);

        $this->client()->run('org-1', 'web-1', 'deploy');
    }

    public function test_a_refused_command_raises_gateway_unavailable(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'error' => 'unauthorized',
            'outcome_known' => true,
        ], 401)]);

        $this->expectException(GatewayUnavailable::class);

        $this->client()->run('org-1', 'web-1', 'exec');
    }

    // ---- attribution ----

    public function test_commands_and_results_are_audited(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'status' => 'succeeded', 'outcome_known' => true,
        ], 200)]);

        $this->client()->run('org-1', 'web-1', 'exec', reason: 'Operator ran artisan migrate');

        $actions = DB::table('audit_records')->orderBy('sequence')->pluck('action')->all();

        $this->assertSame(['node.command_sent', 'node.command_result'], $actions);

        $sent = DB::table('audit_records')->where('action', 'node.command_sent')->first();
        $this->assertSame('Operator ran artisan migrate', $sent->reason);
        $this->assertSame('web-1', $sent->resource_id);

        $this->assertTrue((new AuditLog())->verify('org-1')->intact);
    }

    /**
     * An unknown outcome is recorded as a failure rather than something
     * neutral. It is not a success, and these are the cases most worth
     * finding when searching the audit log after an incident.
     */
    public function test_an_unknown_outcome_is_audited_as_a_failure(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'status' => 'timed_out_waiting', 'outcome_known' => false,
        ], 504)]);

        try {
            $this->client()->run('org-1', 'web-1', 'activate_release');
        } catch (CommandOutcomeUnknown) {
            // expected
        }

        $result = DB::table('audit_records')->where('action', 'node.command_result')->first();

        $this->assertNotNull($result);
        $this->assertSame('failed', $result->outcome);
        $this->assertStringContainsString('unknown', (string) $result->metadata);
    }

    public function test_a_command_is_audited_even_when_the_gateway_is_unreachable(): void
    {
        $this->http->fake(fn () => throw new ConnectionException('refused'));

        try {
            $this->client()->run('org-1', 'web-1', 'exec');
        } catch (CommandOutcomeUnknown) {
            // expected
        }

        // Both records: we tried, and we do not know what happened. An audit
        // log that only records outcomes we learned would be missing exactly
        // the entries an incident review needs.
        $this->assertSame(
            ['node.command_sent', 'node.command_result'],
            DB::table('audit_records')->orderBy('sequence')->pluck('action')->all()
        );
    }

    // ---- node listing and organisation sync ----

    public function test_connected_nodes_are_listed(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'nodes' => [
                ['node_id' => 'web-1', 'organization_id' => 'org-1'],
                ['node_id' => 'web-2', 'organization_id' => 'org-1'],
            ],
        ], 200)]);

        $nodes = $this->client()->connectedNodes('org-1');

        $this->assertCount(2, $nodes);
        $this->assertSame('web-1', $nodes[0]['node_id']);
    }

    public function test_is_connected_reads_the_gateway(): void
    {
        $this->http->fake(['*' => $this->http->response(['connected' => true], 200)]);
        \Illuminate\Support\Facades\DB::table('nodes')->insert(['node_id' => 'web-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue($this->client()->isConnected('web-1'));

        // A node the control plane has no record of is never asked about.
        $this->assertFalse($this->client()->isConnected('stranger'));
    }

    /**
     * Only the certificate is sent. The CA private key stays in the vault,
     * because giving the gateway signing power would widen the blast radius
     * of a gateway compromise from "can command nodes" to "can mint node
     * identities".
     */
    public function test_organisation_sync_sends_certificates_and_never_keys(): void
    {
        $this->http->fake(['*' => $this->http->response(['organisations' => 1], 200)]);

        $this->client()->syncOrganisations([
            ['id' => 'org-1', 'ca_certificate_pem' => "-----BEGIN CERTIFICATE-----\nAAA\n-----END CERTIFICATE-----\n"],
        ]);

        $this->http->assertSent(function (Request $r): bool {
            $body = (string) $r->body();

            return str_contains($body, 'BEGIN CERTIFICATE')
                && ! str_contains($body, 'PRIVATE KEY');
        });
    }

    public function test_a_refused_organisation_sync_raises(): void
    {
        $this->http->fake(['*' => $this->http->response([
            'error' => 'organisations org-a and org-b share a CA certificate',
        ], 400)]);

        $this->expectException(GatewayUnavailable::class);

        $this->client()->syncOrganisations([['id' => 'org-a', 'ca_certificate_pem' => 'x']]);
    }

    public function test_every_node_call_names_its_organisation(): void
    {
        $this->http->fake(['*' => $this->http->response(['connected' => true, 'status' => 'succeeded', 'outcome_known' => true], 200)]);
        \Illuminate\Support\Facades\DB::table('nodes')->insert(['node_id' => 'web-1', 'organization_id' => 'org-7', 'created_at' => now(), 'updated_at' => now()]);

        $this->client()->isConnected('web-1');
        $this->client()->run('org-7', 'web-1', 'exec');

        $this->http->assertSent(fn ($r) => str_ends_with($r->url(), '/v1/nodes/web-1') && $r->header('X-Organization-ID') === ['org-7']);
        $this->http->assertSent(fn ($r) => str_ends_with($r->url(), '/v1/nodes/web-1/commands') && $r->header('X-Organization-ID') === ['org-7']);
    }
}
