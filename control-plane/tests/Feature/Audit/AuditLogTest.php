<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    private AuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dropAuditTriggers(); // these tests tamper on purpose; AuditProtectionTest covers the triggers
        $this->audit = new AuditLog();
    }

    private function recordSome(string $org = 'org-1', int $count = 3): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $this->audit->record(
                organizationId: $org,
                actor: Actor::user('user-1', 'matt@example.com'),
                action: 'droplet.created',
                resourceType: 'droplet',
                resourceId: 'drop-'.$i,
                reason: 'Provisioning node '.$i,
            );
        }
    }

    public function test_records_are_appended_in_sequence(): void
    {
        $this->recordSome();

        $sequences = DB::table('audit_records')->orderBy('sequence')->pluck('sequence')->all();
        $this->assertSame([1, 2, 3], array_map('intval', $sequences));
    }

    public function test_record_returns_its_sequence_and_hash(): void
    {
        $result = $this->audit->record('org-1', Actor::system('scheduler'), 'reconcile.ran');

        $this->assertSame(1, $result['sequence']);
        $this->assertSame(64, strlen($result['hash']));
    }

    public function test_captures_who_what_and_why(): void
    {
        $this->audit->record(
            organizationId: 'org-1',
            actor: Actor::user('user-7', 'ops@example.com'),
            action: 'droplet.destroyed',
            outcome: Outcome::Succeeded,
            resourceType: 'droplet',
            resourceId: 'drop-9',
            reason: 'Decommissioning staging',
            ipAddress: '203.0.113.5',
        );

        $row = DB::table('audit_records')->first();

        $this->assertSame('user', $row->actor_type);
        $this->assertSame('user-7', $row->actor_id);
        $this->assertSame('ops@example.com', $row->actor_label);
        $this->assertSame('droplet.destroyed', $row->action);
        $this->assertSame('drop-9', $row->resource_id);
        $this->assertSame('Decommissioning staging', $row->reason);
        $this->assertSame('succeeded', $row->outcome);
        $this->assertSame('203.0.113.5', $row->ip_address);
    }

    /**
     * An audit log containing only successes cannot answer "what did they
     * try", which is usually the more interesting question after an incident.
     */
    public function test_failures_and_denials_are_recorded(): void
    {
        $this->audit->record('org-1', Actor::user('u1'), 'droplet.create', Outcome::Failed, reason: 'Rate limited');
        $this->audit->record('org-1', Actor::user('u2'), 'droplet.destroy', Outcome::Denied, reason: 'Not an owner');

        $outcomes = DB::table('audit_records')->orderBy('sequence')->pluck('outcome')->all();
        $this->assertSame(['failed', 'denied'], $outcomes);

        $this->assertTrue($this->audit->verify('org-1')->intact);
    }

    // ---- chain integrity ----

    public function test_an_untouched_chain_verifies(): void
    {
        $this->recordSome(count: 5);

        $result = $this->audit->verify('org-1');

        $this->assertTrue($result->intact);
        $this->assertSame(5, $result->recordsChecked);
    }

    public function test_an_empty_chain_verifies(): void
    {
        $this->assertTrue($this->audit->verify('org-nothing')->intact);
    }

    public function test_records_link_to_their_predecessor(): void
    {
        $this->recordSome();

        $rows = DB::table('audit_records')->orderBy('sequence')->get();

        $this->assertNull($rows[0]->previous_hash);
        $this->assertSame($rows[0]->hash, $rows[1]->previous_hash);
        $this->assertSame($rows[1]->hash, $rows[2]->previous_hash);
    }

    /**
     * The point of the whole design: an attacker with database write access
     * can destroy the log but cannot quietly edit it.
     */
    public function test_editing_a_record_is_detected(): void
    {
        $this->recordSome(count: 5);

        DB::table('audit_records')->where('sequence', 3)->update([
            'action' => 'droplet.read',
            'reason' => 'Nothing to see here',
        ]);

        $result = $this->audit->verify('org-1');

        $this->assertFalse($result->intact);
        $this->assertSame(3, $result->brokenAtSequence);
        $this->assertStringContainsString('altered', (string) $result->reason);
    }

    public function test_editing_metadata_is_detected(): void
    {
        $this->audit->record('org-1', Actor::user('u1'), 'a', metadata: ['region' => 'nyc3']);

        DB::table('audit_records')->where('sequence', 1)->update([
            'metadata' => json_encode(['region' => 'lon1']),
        ]);

        $this->assertFalse($this->audit->verify('org-1')->intact);
    }

    public function test_editing_the_timestamp_is_detected(): void
    {
        $this->recordSome(count: 2);

        DB::table('audit_records')->where('sequence', 1)->update([
            'recorded_at' => '2020-01-01 00:00:00.000000',
        ]);

        $this->assertFalse($this->audit->verify('org-1')->intact);
    }

    public function test_deleting_a_record_from_the_middle_is_detected(): void
    {
        $this->recordSome(count: 5);

        DB::table('audit_records')->where('sequence', 3)->delete();

        $result = $this->audit->verify('org-1');

        $this->assertFalse($result->intact);
        $this->assertSame(4, $result->brokenAtSequence);
        $this->assertStringContainsString('missing', (string) $result->reason);
    }

    /**
     * Truncating the end is the subtle case: every surviving record still
     * hashes correctly and links correctly, so only the chain head reveals
     * that anything is gone.
     */
    public function test_deleting_records_from_the_end_is_detected(): void
    {
        $this->recordSome(count: 5);

        DB::table('audit_records')->where('sequence', '>', 3)->delete();

        $result = $this->audit->verify('org-1');

        $this->assertFalse($result->intact);
        $this->assertStringContainsString('removed from the end', (string) $result->reason);
    }

    public function test_reordering_records_is_detected(): void
    {
        $this->recordSome(count: 4);

        // Swap the sequence numbers of two records.
        DB::table('audit_records')->where('sequence', 2)->update(['sequence' => 99]);
        DB::table('audit_records')->where('sequence', 3)->update(['sequence' => 2]);
        DB::table('audit_records')->where('sequence', 99)->update(['sequence' => 3]);

        $this->assertFalse($this->audit->verify('org-1')->intact);
    }

    /**
     * A forged record cannot be inserted, because the attacker would have to
     * recompute every hash after it, and the chain head too.
     */
    public function test_a_forged_record_appended_without_the_chain_is_detected(): void
    {
        $this->recordSome(count: 3);

        DB::table('audit_records')->insert([
            'organization_id' => 'org-1',
            'sequence' => 4,
            'actor_type' => 'user',
            'actor_id' => 'attacker',
            'action' => 'droplet.destroyed',
            'outcome' => 'succeeded',
            'recorded_at' => now()->format('Y-m-d H:i:s.u'),
            'previous_hash' => str_repeat('0', 64),
            'hash' => str_repeat('f', 64),
        ]);

        $result = $this->audit->verify('org-1');

        $this->assertFalse($result->intact);
        $this->assertSame(4, $result->brokenAtSequence);
    }

    public function test_a_broken_chain_reports_how_much_history_is_still_good(): void
    {
        $this->recordSome(count: 10);
        DB::table('audit_records')->where('sequence', 7)->update(['action' => 'tampered']);

        $result = $this->audit->verify('org-1');

        $this->assertSame(6, $result->recordsChecked);
        $this->assertStringContainsString('1 to 6 remain verifiable', $result->describe());
    }

    // ---- tenant isolation ----

    public function test_chains_are_independent_per_organisation(): void
    {
        $this->recordSome('org-1', 3);
        $this->recordSome('org-2', 2);

        $this->assertTrue($this->audit->verify('org-1')->intact);
        $this->assertTrue($this->audit->verify('org-2')->intact);

        // Each starts at 1.
        $this->assertSame(
            [1, 2],
            array_map('intval', DB::table('audit_records')->where('organization_id', 'org-2')->orderBy('sequence')->pluck('sequence')->all())
        );
    }

    public function test_tampering_with_one_organisation_does_not_break_another(): void
    {
        $this->recordSome('org-1', 3);
        $this->recordSome('org-2', 3);

        DB::table('audit_records')
            ->where('organization_id', 'org-1')
            ->where('sequence', 2)
            ->update(['action' => 'tampered']);

        $this->assertFalse($this->audit->verify('org-1')->intact);
        $this->assertTrue($this->audit->verify('org-2')->intact);
    }

    // ---- redaction ----

    /**
     * The audit log is read by more people than the vault and gets exported.
     * A credential that lands here has effectively been published internally.
     */
    public function test_sensitive_metadata_keys_are_redacted(): void
    {
        $this->audit->record('org-1', Actor::user('u1'), 'credential.connected', metadata: [
            'do_token' => 'dop_v1_very_secret',
            'api_key' => 'sk_live_secret',
            'password' => 'hunter2',
            'Authorization' => 'Bearer abc',
            'private_key' => '-----BEGIN PRIVATE KEY-----',
            'region' => 'nyc3',
        ]);

        $row = DB::table('audit_records')->first();
        $metadata = json_decode((string) $row->metadata, true);

        $this->assertSame('[redacted]', $metadata['do_token']);
        $this->assertSame('[redacted]', $metadata['api_key']);
        $this->assertSame('[redacted]', $metadata['password']);
        $this->assertSame('[redacted]', $metadata['Authorization']);
        $this->assertSame('[redacted]', $metadata['private_key']);

        // Non-sensitive context survives, or the log stops being useful.
        $this->assertSame('nyc3', $metadata['region']);

        $this->assertStringNotContainsString('dop_v1_very_secret', (string) $row->metadata);
        $this->assertStringNotContainsString('hunter2', (string) $row->metadata);
    }

    public function test_redaction_reaches_nested_metadata(): void
    {
        $this->audit->record('org-1', Actor::user('u1'), 'x', metadata: [
            'request' => ['headers' => ['authorization' => 'Bearer secret'], 'path' => '/v2/droplets'],
        ]);

        $row = DB::table('audit_records')->first();
        $metadata = json_decode((string) $row->metadata, true);

        $this->assertStringNotContainsString('Bearer secret', (string) $row->metadata);
        $this->assertSame('[redacted]', $metadata['request']['headers']['authorization']);

        // Assert on the decoded structure rather than the raw column: JSON
        // escapes forward slashes, so a substring check for a path would
        // fail against a perfectly correct record.
        $this->assertSame('/v2/droplets', $metadata['request']['path']);
    }

    public function test_redaction_does_not_break_verification(): void
    {
        $this->audit->record('org-1', Actor::user('u1'), 'x', metadata: ['token' => 'secret']);
        $this->audit->record('org-1', Actor::user('u1'), 'y', metadata: ['region' => 'nyc3']);

        $this->assertTrue($this->audit->verify('org-1')->intact);
    }

    // ---- reading back ----

    public function test_reads_back_newest_first(): void
    {
        $this->recordSome(count: 3);

        $records = $this->audit->forOrganization('org-1');

        $this->assertCount(3, $records);
        $this->assertSame(3, (int) $records[0]->sequence);
    }

    public function test_reads_back_by_resource(): void
    {
        $this->recordSome(count: 3);
        $this->audit->record('org-1', Actor::user('u1'), 'droplet.resized', resourceType: 'droplet', resourceId: 'drop-2');

        $records = $this->audit->forResource('droplet', 'drop-2');

        $this->assertCount(2, $records);
    }

    // ---- append only ----

    /**
     * Append-only is enforced by there being no other code path, not by a
     * policy document. If someone adds an update method, this fails.
     */
    public function test_the_class_exposes_no_mutation_methods(): void
    {
        $methods = get_class_methods(AuditLog::class);

        foreach (['update', 'delete', 'forget', 'destroy', 'truncate', 'purge'] as $forbidden) {
            foreach ($methods as $method) {
                $this->assertStringNotContainsStringIgnoringCase(
                    $forbidden,
                    $method,
                    sprintf('AuditLog::%s looks like a mutation; the log is append only.', $method)
                );
            }
        }
    }

    public function test_concurrent_appends_do_not_collide(): void
    {
        // SQLite in tests cannot exercise real concurrency, but the unique
        // constraint on (organization_id, sequence) is what makes a lost
        // update impossible rather than merely unlikely. Assert it exists.
        $this->recordSome(count: 2);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('audit_records')->insert([
            'organization_id' => 'org-1',
            'sequence' => 2,
            'actor_type' => 'user',
            'action' => 'duplicate',
            'outcome' => 'succeeded',
            'recorded_at' => now()->format('Y-m-d H:i:s.u'),
            'hash' => str_repeat('a', 64),
        ]);
    }
}
