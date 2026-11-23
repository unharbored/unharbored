<?php

declare(strict_types=1);

namespace Tests\Feature\Resources;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Resources\Exceptions\ConcurrentModification;
use App\Lib\Resources\Exceptions\InvalidTransition;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ResourceManagerTest extends TestCase
{
    use RefreshDatabase;

    private ResourceManager $resources;

    protected function setUp(): void
    {
        parent::setUp();
        $this->resources = new ResourceManager(new AuditLog);
    }

    private function declareDroplet(string $slug = 'web-1'): ResourceRecord
    {
        return $this->resources->declare('org-1', 'server', $slug, ['region' => 'nyc3', 'size' => 's-1vcpu-1gb']);
    }

    // ---- declaration ----

    public function test_a_declared_resource_starts_pending_with_an_idempotency_key(): void
    {
        $resource = $this->declareDroplet();

        $this->assertSame(ResourceState::Pending, $resource->state);
        $this->assertSame(ResourceState::Active, $resource->desiredState);
        $this->assertNotNull($resource->idempotencyKey);
        $this->assertNull($resource->externalId);
        $this->assertEquals(['region' => 'nyc3', 'size' => 's-1vcpu-1gb'], $resource->attributes);
    }

    /**
     * Declaring is the first thing that happens, before any API call. A
     * retried request must find the existing row rather than creating a
     * second resource the customer pays for.
     */
    public function test_declaring_twice_returns_the_same_resource(): void
    {
        $first = $this->declareDroplet();
        $second = $this->declareDroplet();

        $this->assertSame($first->id, $second->id);
        $this->assertSame($first->idempotencyKey, $second->idempotencyKey);
        $this->assertDatabaseCount('resources', 1);
    }

    public function test_slugs_are_scoped_per_organisation_and_type(): void
    {
        $this->resources->declare('org-1', 'server', 'web-1');
        $this->resources->declare('org-2', 'server', 'web-1');
        $this->resources->declare('org-1', 'load_balancer', 'web-1');

        $this->assertDatabaseCount('resources', 3);
    }

    // ---- transitions ----

    public function test_the_happy_path(): void
    {
        $resource = $this->declareDroplet();

        $resource = $this->resources->transition($resource, ResourceState::Provisioning, 'creating droplet');
        $this->assertSame(1, $resource->attempts);

        $resource = $this->resources->recordExternalId($resource, '123456');
        $this->assertSame('123456', $resource->externalId);

        $resource = $this->resources->transition($resource, ResourceState::Active, 'droplet is up');
        $this->assertSame(ResourceState::Active, $resource->state);
        $this->assertFalse($resource->needsReconciliation());
    }

    public function test_invalid_transitions_are_rejected(): void
    {
        $resource = $this->declareDroplet();

        // Pending cannot jump straight to Active.
        $this->expectException(InvalidTransition::class);
        $this->resources->transition($resource, ResourceState::Active);
    }

    public function test_the_rejection_names_what_is_allowed(): void
    {
        $resource = $this->declareDroplet();

        try {
            $this->resources->transition($resource, ResourceState::Deleted);
            $this->fail('Expected InvalidTransition.');
        } catch (InvalidTransition $e) {
            $this->assertStringContainsString('provisioning', $e->getMessage());
            $this->assertStringContainsString('failed', $e->getMessage());
        }
    }

    public function test_deleted_is_terminal(): void
    {
        $this->assertTrue(ResourceState::Deleted->isTerminal());
        $this->assertSame([], ResourceState::Deleted->allowedNext());

        foreach (ResourceState::cases() as $state) {
            $this->assertFalse(
                ResourceState::Deleted->canTransitionTo($state),
                sprintf('Deleted should not reach %s.', $state->value)
            );
        }
    }

    public function test_a_failed_create_can_be_retried(): void
    {
        $resource = $this->declareDroplet();
        $resource = $this->resources->transition($resource, ResourceState::Provisioning);
        $resource = $this->resources->transition($resource, ResourceState::Failed, error: 'rate limited');

        $this->assertSame('rate limited', $resource->lastError);

        $resource = $this->resources->transition($resource, ResourceState::Provisioning, 'retrying');
        $this->assertSame(2, $resource->attempts);
    }

    // ---- the expensive failure mode ----

    /**
     * A create that times out leaves a resource that may exist, that we
     * cannot address, and that the customer may be billed for. That
     * condition needs a name and a query, or it only shows up on an invoice.
     */
    public function test_a_timed_out_create_becomes_orphaned_not_forgotten(): void
    {
        $resource = $this->declareDroplet();
        $resource = $this->resources->transition($resource, ResourceState::Provisioning);

        // The API call times out. We never learn an identifier.
        $resource = $this->resources->transition(
            $resource,
            ResourceState::Orphaned,
            reason: 'create timed out with no identifier returned',
        );

        $this->assertTrue($resource->isUntrackable());
        $this->assertTrue($resource->state->mayIncurCost());

        $orphans = $this->resources->orphaned('org-1');
        $this->assertCount(1, $orphans);
        $this->assertSame($resource->id, $orphans[0]->id);
    }

    public function test_an_orphan_can_be_recovered_once_its_identifier_is_found(): void
    {
        $resource = $this->declareDroplet();
        $resource = $this->resources->transition($resource, ResourceState::Provisioning);
        $resource = $this->resources->transition($resource, ResourceState::Orphaned, 'create timed out');

        // Reconciliation finds it at the provider by tag.
        $resource = $this->resources->recordExternalId($resource, '999888');
        $resource = $this->resources->transition($resource, ResourceState::Active, 'recovered by tag search');

        $this->assertSame('999888', $resource->externalId);
        $this->assertFalse($resource->isUntrackable());
        $this->assertSame([], $this->resources->orphaned('org-1'));
    }

    public function test_an_orphan_can_be_torn_down(): void
    {
        $resource = $this->declareDroplet();
        $resource = $this->resources->transition($resource, ResourceState::Provisioning);
        $resource = $this->resources->transition($resource, ResourceState::Orphaned, 'create timed out');
        $resource = $this->resources->recordExternalId($resource, '999888');
        $resource = $this->resources->transition($resource, ResourceState::Deleting, 'cleaning up orphan');
        $resource = $this->resources->transition($resource, ResourceState::Deleted);

        $this->assertSame(ResourceState::Deleted, $resource->state);
        $this->assertFalse($resource->state->mayIncurCost());
    }

    /**
     * A resource with no external id that might be costing money is the
     * single most important thing this system can surface.
     */
    public function test_untrackable_resources_are_identifiable(): void
    {
        $pending = $this->declareDroplet('web-1');
        $this->assertFalse($pending->isUntrackable(), 'Pending has not been created yet.');

        $provisioning = $this->resources->transition($pending, ResourceState::Provisioning);
        $this->assertTrue($provisioning->isUntrackable());

        $tracked = $this->resources->recordExternalId($provisioning, '123');
        $this->assertFalse($tracked->isUntrackable());
    }

    // ---- staleness ----

    /**
     * A row that looks busy looks fine, until you ask how long it has been
     * busy. This is the query that catches a worker that died mid-provision.
     */
    public function test_stale_transient_resources_are_found(): void
    {
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');

        $stuck = $this->declareDroplet('web-stuck');
        $this->resources->transition($stuck, ResourceState::Provisioning);

        CarbonImmutable::setTestNow('2026-11-04 12:30:00');

        $fresh = $this->declareDroplet('web-fresh');
        $this->resources->transition($fresh, ResourceState::Provisioning);

        $stale = $this->resources->stale(CarbonImmutable::parse('2026-11-04 12:15:00'));

        $this->assertCount(1, $stale);
        $this->assertSame('web-stuck', $stale[0]->slug);

        CarbonImmutable::setTestNow();
    }

    public function test_settled_resources_are_never_reported_stale(): void
    {
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');

        $resource = $this->declareDroplet();
        $resource = $this->resources->transition($resource, ResourceState::Provisioning);
        $resource = $this->resources->recordExternalId($resource, '1');
        $this->resources->transition($resource, ResourceState::Active);

        CarbonImmutable::setTestNow('2026-11-05 12:00:00');

        // Active for a day is not stale. Provisioning for a day is.
        $this->assertSame([], $this->resources->stale(CarbonImmutable::parse('2026-11-05 11:00:00')));

        CarbonImmutable::setTestNow();
    }

    // ---- billing visibility ----

    public function test_billable_includes_everything_we_are_unsure_about(): void
    {
        $active = $this->declareDroplet('web-active');
        $active = $this->resources->transition($active, ResourceState::Provisioning);
        $active = $this->resources->recordExternalId($active, '1');
        $this->resources->transition($active, ResourceState::Active);

        $orphan = $this->declareDroplet('web-orphan');
        $orphan = $this->resources->transition($orphan, ResourceState::Provisioning);
        $this->resources->transition($orphan, ResourceState::Orphaned, 'timed out');

        $gone = $this->declareDroplet('web-gone');
        $gone = $this->resources->transition($gone, ResourceState::Provisioning);
        $gone = $this->resources->transition($gone, ResourceState::Failed, error: 'quota');
        $this->resources->transition($gone, ResourceState::Deleted);

        $billable = array_map(fn ($r) => $r->slug, $this->resources->billable('org-1'));
        sort($billable);

        // The orphan counts. Answering "what might they be billed for"
        // honestly means including what we are unsure about.
        $this->assertSame(['web-active', 'web-orphan'], $billable);
    }

    // ---- concurrency ----

    /**
     * Two reconciler workers reading the same row must not both act on it.
     */
    public function test_a_stale_read_cannot_transition(): void
    {
        $resource = $this->declareDroplet();

        $workerA = $resource;
        $workerB = $resource;

        $this->resources->transition($workerA, ResourceState::Provisioning);

        $this->expectException(ConcurrentModification::class);
        $this->resources->transition($workerB, ResourceState::Failed);
    }

    public function test_version_increments_on_each_transition(): void
    {
        $resource = $this->declareDroplet();
        $this->assertSame(1, $resource->version);

        $resource = $this->resources->transition($resource, ResourceState::Provisioning);
        $this->assertSame(2, $resource->version);
    }

    // ---- history ----

    /**
     * A failed provision must be diagnosable after the fact rather than a
     * mystery, which means keeping every step including the failures.
     */
    public function test_every_transition_is_recorded(): void
    {
        $resource = $this->declareDroplet();
        $resource = $this->resources->transition($resource, ResourceState::Provisioning, 'creating');
        $resource = $this->resources->transition($resource, ResourceState::Failed, error: 'DO returned 422');
        $resource = $this->resources->transition($resource, ResourceState::Provisioning, 'retrying');
        $resource = $this->resources->recordExternalId($resource, '555');
        $this->resources->transition($resource, ResourceState::Active);

        $history = $this->resources->history($resource->id);

        $states = array_map(fn ($t) => $t->to_state, $history);
        $this->assertSame(
            ['pending', 'provisioning', 'failed', 'provisioning', 'provisioning', 'active'],
            $states
        );

        $failure = array_values(array_filter($history, fn ($t) => $t->to_state === 'failed'))[0];
        $this->assertSame('DO returned 422', $failure->error);
    }

    public function test_recording_an_external_id_appears_in_history(): void
    {
        $resource = $this->declareDroplet();
        $resource = $this->resources->transition($resource, ResourceState::Provisioning);
        $this->resources->recordExternalId($resource, '777');

        $history = $this->resources->history($resource->id);
        $last = end($history);

        $this->assertSame('external id recorded', $last->reason);
        $this->assertStringContainsString('777', (string) $last->context);
    }

    // ---- audit integration ----

    public function test_transitions_are_audited(): void
    {
        $resource = $this->declareDroplet();
        $this->resources->transition(
            $resource,
            ResourceState::Provisioning,
            reason: 'customer requested a new web node',
            actor: Actor::user('user-1', 'matt@example.com'),
        );

        $record = DB::table('audit_records')->first();

        $this->assertSame('resource.provisioning', $record->action);
        $this->assertSame('server', $record->resource_type);
        $this->assertSame('web-1', $record->resource_id);
        $this->assertSame('user-1', $record->actor_id);
        $this->assertSame('customer requested a new web node', $record->reason);

        $this->assertTrue((new AuditLog)->verify('org-1')->intact);
    }

    public function test_a_failed_transition_is_audited_as_a_failure(): void
    {
        $resource = $this->declareDroplet();
        $resource = $this->resources->transition($resource, ResourceState::Provisioning);
        $this->resources->transition($resource, ResourceState::Failed, error: 'quota exceeded');

        $outcomes = DB::table('audit_records')->orderBy('sequence')->pluck('outcome')->all();
        $this->assertSame(['succeeded', 'failed'], $outcomes);
    }

    // ---- attributes ----

    public function test_attributes_merge_without_losing_existing_keys(): void
    {
        $resource = $this->declareDroplet();
        $resource = $this->resources->mergeAttributes($resource, ['ipv4' => '203.0.113.9']);

        $this->assertEquals(
            ['region' => 'nyc3', 'size' => 's-1vcpu-1gb', 'ipv4' => '203.0.113.9'],
            $resource->attributes
        );
    }

    // ---- the state graph itself ----

    public function test_every_non_terminal_state_can_reach_deletion(): void
    {
        // Otherwise a resource could become permanently unremovable, which on
        // a billed provider means permanently expensive.
        foreach (ResourceState::cases() as $state) {
            if ($state->isTerminal()) {
                continue;
            }

            $reachable = $this->reachableFrom($state);

            $this->assertContains(
                ResourceState::Deleted,
                $reachable,
                sprintf('%s cannot reach Deleted; a resource there could never be torn down.', $state->value)
            );
        }
    }

    public function test_transient_states_always_have_an_escape(): void
    {
        foreach (ResourceState::cases() as $state) {
            if (! $state->isTransient()) {
                continue;
            }

            $next = $state->allowedNext();
            $settled = array_filter($next, fn (ResourceState $s) => ! $s->isTransient());

            $this->assertNotEmpty(
                $settled,
                sprintf('%s has no settled successor; a stuck resource could never leave it.', $state->value)
            );
        }
    }

    /**
     * @return array<int, ResourceState>
     */
    private function reachableFrom(ResourceState $start): array
    {
        $seen = [];
        $queue = [$start];

        while ($queue !== []) {
            $current = array_shift($queue);

            foreach ($current->allowedNext() as $next) {
                if (in_array($next, $seen, true)) {
                    continue;
                }
                $seen[] = $next;
                $queue[] = $next;
            }
        }

        return $seen;
    }
}
