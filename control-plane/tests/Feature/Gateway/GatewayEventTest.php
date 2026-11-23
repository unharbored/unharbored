<?php

declare(strict_types=1);

namespace Tests\Feature\Gateway;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The gateway holds the connections, so it is the only thing that knows when
 * a node arrives or leaves. Without this endpoint the control plane has a
 * list of nodes it provisioned and no idea which are alive.
 */
final class GatewayEventTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'shared-secret';

    protected function setUp(): void
    {
        parent::setUp();

        config(['control_plane.gateway_token' => self::TOKEN]);

        DB::table('organizations')->insert([
            'id' => 'org-1', 'name' => 'Test', 'created_at' => now(), 'updated_at' => now(),
        ]);

        DB::table('nodes')->insert([
            'node_id' => 'web-1',
            'organization_id' => 'org-1',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function sendEvent(string $kind, array $body, ?string $token = self::TOKEN): \Illuminate\Testing\TestResponse
    {
        $headers = $token === null ? [] : [GatewayClient::AUTH_HEADER => $token];

        return $this->postJson('/api/gateway/events', ['kind' => $kind, 'body' => $body], $headers);
    }

    /**
     * Anything that can post here can tell the control plane a node is
     * connected when it is not, or disconnected when it is.
     */
    public function test_events_require_the_shared_secret(): void
    {
        foreach ([null, 'wrong', '', 'shared-secre'] as $token) {
            $this->sendEvent('node.connected', ['node_id' => 'web-1', 'organization_id' => 'org-1'], $token)
                ->assertStatus(401);
        }
    }

    public function test_a_connect_event_records_the_node_as_seen(): void
    {
        $this->sendEvent('node.connected', [
            'node_id' => 'web-1',
            'organization_id' => 'org-1',
            'agent_version' => '0.2.0',
            'hostname' => 'web-1.example',
            'os' => 'linux',
            'arch' => 'amd64',
        ])->assertOk();

        $node = DB::table('nodes')->where('node_id', 'web-1')->first();

        $this->assertNotNull($node->last_seen_at);
        $this->assertSame('0.2.0', $node->agent_version);
        $this->assertSame('web-1.example', $node->hostname);
    }

    public function test_a_connect_event_is_audited(): void
    {
        $this->sendEvent('node.connected', ['node_id' => 'web-1', 'organization_id' => 'org-1'])->assertOk();

        $record = DB::table('audit_records')->where('action', 'node.connected')->first();

        $this->assertNotNull($record);
        $this->assertSame('agent', $record->actor_type);
        $this->assertSame('web-1', $record->actor_id);
        $this->assertTrue((new AuditLog())->verify('org-1')->intact);
    }

    /**
     * A node dropping off is either being replaced or is in trouble. Both are
     * worth finding when searching the audit log for things that went wrong.
     */
    public function test_a_disconnect_is_audited_as_a_failure(): void
    {
        $this->sendEvent('node.disconnected', [
            'node_id' => 'web-1',
            'organization_id' => 'org-1',
            'reason' => 'transport: heartbeat timed out',
        ])->assertOk();

        $record = DB::table('audit_records')->where('action', 'node.disconnected')->first();

        $this->assertNotNull($record);
        $this->assertSame('failed', $record->outcome);
        $this->assertStringContainsString('heartbeat', (string) $record->reason);
    }

    /**
     * last_seen_at records when the node was last known alive, and a
     * disconnect is exactly the moment that stops being now.
     */
    public function test_a_disconnect_does_not_refresh_last_seen(): void
    {
        $this->sendEvent('node.connected', ['node_id' => 'web-1', 'organization_id' => 'org-1'])->assertOk();
        $seen = DB::table('nodes')->where('node_id', 'web-1')->first()->last_seen_at;

        $this->travel(5)->minutes();

        $this->sendEvent('node.disconnected', ['node_id' => 'web-1', 'organization_id' => 'org-1'])->assertOk();

        $this->assertSame(
            $seen,
            DB::table('nodes')->where('node_id', 'web-1')->first()->last_seen_at
        );
    }

    /**
     * Its certificate chains to a real organisation CA, so it was enrolled by
     * us at some point. Most likely the row was deleted while the node kept
     * running, which is worth knowing rather than discarding.
     */
    public function test_a_connect_from_an_unknown_node_is_still_audited(): void
    {
        $this->sendEvent('node.connected', [
            'node_id' => 'ghost-1',
            'organization_id' => 'org-1',
        ])->assertOk();

        $record = DB::table('audit_records')->where('resource_id', 'ghost-1')->first();

        $this->assertNotNull($record);
        $this->assertStringContainsString('"tracked":false', (string) $record->metadata);
    }

    public function test_progress_events_are_accepted_without_auditing(): void
    {
        // Deploy log lines are output, not actions. Writing each into a
        // hash-chained log would make the chain enormous and no more
        // trustworthy.
        $this->sendEvent('command.progress', [
            'node_id' => 'web-1',
            'organization_id' => 'org-1',
            'command_id' => 'abc',
            'seq' => 0,
            'text' => 'create: directory /srv/app',
        ])->assertOk();

        $this->assertDatabaseCount('audit_records', 0);
    }

    public function test_a_metrics_sample_is_stored_for_the_node_the_gateway_names(): void
    {
        $this->sendEvent('node.metrics', ['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'sample' => [
            'collected_at' => 1, 'cpu_percent' => 42.456, 'load_1' => 0.5,
            'mem_total' => 1000, 'mem_available' => 250, 'uptime_seconds' => 3600,
            'disks' => ['/' => ['total' => 2000, 'free' => 500]],
        ]])->assertOk();

        $row = DB::table('node_metrics')->sole();
        $this->assertSame(['web-1.org-1', 42.46, 1000, 250, 2000, 500], [$row->node_id, (float) $row->cpu, (int) $row->mem_total, (int) $row->mem_available, (int) $row->disk_total, (int) $row->disk_free]);
    }

    public function test_a_sample_without_its_identifiers_is_dropped(): void
    {
        $this->sendEvent('node.metrics', ['node_id' => 'web-1'])->assertOk();
        $this->assertSame(0, DB::table('node_metrics')->count());
    }

    public function test_log_lines_are_stored_and_a_gap_is_recorded_as_a_line(): void
    {
        $this->sendEvent('node.logs', ['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'batch' => [
            'lines' => [
                ['source' => 'laravel:shop', 'ts' => now()->subSecond()->getTimestampMs(), 'text' => 'production.ERROR: SQLSTATE[HY000]'],
                ['source' => 'nginx.error', 'ts' => now()->addYear()->getTimestampMs(), 'text' => 'from a clock running ahead'],
            ],
            'dropped' => 12,
        ]])->assertOk();

        $rows = DB::table('log_lines')->orderBy('id')->get();
        $this->assertSame(['laravel:shop', 'nginx.error', 'agent'], $rows->pluck('source')->all());
        $this->assertFalse(\Illuminate\Support\Carbon::parse($rows[1]->logged_at)->isFuture(), 'A skewed clock does not file lines in the future.');
        $this->assertStringContainsString('12 lines dropped', $rows[2]->text);
    }

    public function test_samples_older_than_two_weeks_are_pruned(): void
    {
        foreach ([now()->subDays(15), now()->subDays(13)] as $at) {
            DB::table('node_metrics')->insert(['organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'collected_at' => $at, 'cpu' => 1, 'load_1' => 0,
                'mem_total' => 1, 'mem_available' => 1, 'disk_total' => 1, 'disk_free' => 1, 'uptime' => 1]);
        }
        $this->artisan('metrics:prune')->assertSuccessful();
        $this->assertSame(1, DB::table('node_metrics')->count());
    }

    public function test_an_unknown_event_kind_is_ignored_rather_than_failing(): void
    {
        // A newer gateway sending something this control plane does not know
        // about must not cause it to reject the whole delivery.
        $this->sendEvent('something.new', ['node_id' => 'web-1'])->assertOk();
    }

    public function test_malformed_events_are_rejected(): void
    {
        $headers = [GatewayClient::AUTH_HEADER => self::TOKEN];

        $this->postJson('/api/gateway/events', [], $headers)->assertStatus(422);
        $this->postJson('/api/gateway/events', ['kind' => 'node.connected'], $headers)->assertStatus(422);
        $this->postJson('/api/gateway/events', ['body' => []], $headers)->assertStatus(422);
    }

    public function test_events_missing_identifiers_are_ignored_quietly(): void
    {
        // Not a reason to fail the delivery: the gateway would retry and the
        // event would still be unusable.
        $this->sendEvent('node.connected', ['organization_id' => 'org-1'])->assertOk();
        $this->sendEvent('node.connected', ['node_id' => 'web-1'])->assertOk();

        $this->assertDatabaseCount('audit_records', 0);
    }

    /**
     * An unconfigured token must fail closed rather than accepting anything.
     */
    public function test_an_unconfigured_token_rejects_everything(): void
    {
        config(['control_plane.gateway_token' => '']);

        $this->sendEvent('node.connected', ['node_id' => 'web-1', 'organization_id' => 'org-1'], '')
            ->assertStatus(401);
        $this->sendEvent('node.connected', ['node_id' => 'web-1', 'organization_id' => 'org-1'], 'anything')
            ->assertStatus(401);
    }
}
