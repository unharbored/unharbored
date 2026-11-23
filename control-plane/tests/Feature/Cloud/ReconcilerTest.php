<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\Reconciler;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class ReconcilerTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    private ResourceManager $resources;

    private CredentialStore $credentials;

    protected function setUp(): void
    {
        parent::setUp();

        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        Cache::store('array')->clear();
        $this->http = new HttpFactory();
        $this->resources = new ResourceManager(new AuditLog());

        $this->credentials = new CredentialStore(
            vault: $this->app->make(Vault::class),
            http: $this->http,
            cache: Cache::store('array'),
            audit: new AuditLog(),
        );

        foreach (['org-1', 'org-2'] as $id) {
            DB::table('organizations')->insert([
                'id' => $id, 'name' => $id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->credentials->connect('org-1', 'dop_v1_test_token');
    }

    private function reconciler(): Reconciler
    {
        return new Reconciler($this->credentials, $this->resources, new AuditLog());
    }

    /**
     * @param  array<int, array<string, mixed>>  $droplets
     */
    private function fakeDroplets(array $droplets): void
    {
        $this->http->fake([
            '*' => $this->http->response(['droplets' => $droplets, 'links' => ['pages' => []]], 200),
        ]);
    }

    private function trackedActive(string $slug, string $externalId): void
    {
        $r = $this->resources->declare('org-1', 'server', $slug);
        $r = $this->resources->transition($r, ResourceState::Provisioning);
        $r = $this->resources->recordExternalId($r, $externalId);
        $this->resources->transition($r, ResourceState::Active);
    }

    /**
     * @return array<string, mixed>
     */
    private function remoteDroplet(string $id, string $slug, string $org = 'org-1'): array
    {
        return [
            'id' => (int) $id,
            'name' => $slug,
            'status' => 'active',
            'tags' => ['cloud-manager', 'org:'.$org, 'resource:'.$slug],
        ];
    }

    // ---- the expensive finding ----

    /**
     * A Droplet running under our tag that we have no record of is the
     * customer paying for something we cannot see, manage, or clean up.
     */
    public function test_untracked_droplets_are_reported(): void
    {
        $this->trackedActive('web-1', '111');
        $this->fakeDroplets([
            $this->remoteDroplet('111', 'web-1'),
            $this->remoteDroplet('222', 'web-ghost'),
        ]);

        $report = $this->reconciler()->reconcile('org-1');

        $this->assertTrue($report->checkedProvider);
        $this->assertCount(1, $report->untracked);
        $this->assertSame('222', $report->untracked[0]['external_id']);
        $this->assertSame('web-ghost', $report->untracked[0]['name']);
        $this->assertTrue($report->hasCostlyFindings());
        $this->assertFalse($report->isClean());
    }

    public function test_an_untracked_droplet_is_audited(): void
    {
        $this->fakeDroplets([$this->remoteDroplet('222', 'web-ghost')]);

        $this->reconciler()->reconcile('org-1');

        $record = DB::table('audit_records')
            ->where('action', 'reconcile.untracked_resource')
            ->first();

        $this->assertNotNull($record);
        // Recorded as a failure rather than a success: finding a resource we
        // are not tracking is a problem, not a routine observation.
        $this->assertSame('failed', $record->outcome);
        $this->assertSame('server', $record->resource_type);
        $this->assertSame('222', $record->resource_id);
        $this->assertStringContainsString('billed', (string) $record->reason);
    }

    /**
     * Two organisations could connect the same DigitalOcean account.
     * Reporting one tenant's resources to another would be worse than
     * missing them.
     */
    public function test_another_organisations_droplets_are_not_reported(): void
    {
        $this->fakeDroplets([
            $this->remoteDroplet('333', 'their-web', 'org-2'),
        ]);

        $report = $this->reconciler()->reconcile('org-1');

        $this->assertSame([], $report->untracked);
        $this->assertTrue($report->isClean());
    }

    // ---- stuck rows ----

    public function test_stuck_resources_are_reported(): void
    {
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');

        $r = $this->resources->declare('org-1', 'server', 'web-stuck');
        $this->resources->transition($r, ResourceState::Provisioning);

        CarbonImmutable::setTestNow('2026-11-04 14:00:00');

        $this->fakeDroplets([]);
        $report = $this->reconciler()->reconcile('org-1');

        CarbonImmutable::setTestNow();

        $this->assertCount(1, $report->stuck);
        $this->assertSame('web-stuck', $report->stuck[0]->slug);
        $this->assertTrue($report->hasCostlyFindings());
    }

    public function test_recently_started_work_is_not_called_stuck(): void
    {
        $r = $this->resources->declare('org-1', 'server', 'web-new');
        $this->resources->transition($r, ResourceState::Provisioning);

        $this->fakeDroplets([]);
        $report = $this->reconciler()->reconcile('org-1');

        // Calling something stuck while it is still working would produce a
        // second resource beside a healthy first one.
        $this->assertSame([], $report->stuck);
    }

    // ---- missing rows ----

    public function test_tracked_resources_absent_at_the_provider_are_reported(): void
    {
        $this->trackedActive('web-1', '111');
        $this->fakeDroplets([]);

        $report = $this->reconciler()->reconcile('org-1');

        $this->assertCount(1, $report->missing);
        $this->assertSame('web-1', $report->missing[0]->slug);
    }

    public function test_a_resource_mid_deletion_is_not_reported_missing(): void
    {
        $r = $this->resources->declare('org-1', 'server', 'web-1');
        $r = $this->resources->transition($r, ResourceState::Provisioning);
        $r = $this->resources->recordExternalId($r, '111');
        $r = $this->resources->transition($r, ResourceState::Active);
        $this->resources->transition($r, ResourceState::Deleting);

        $this->fakeDroplets([]);
        $report = $this->reconciler()->reconcile('org-1');

        $this->assertSame([], $report->missing);
    }

    // ---- honesty about what was checked ----

    /**
     * A pass that could not reach DigitalOcean finds no untracked resources.
     * Reporting that as clean would be a lie that reads like good news.
     */
    public function test_an_unreachable_provider_is_not_reported_as_clean(): void
    {
        $this->http->fake(['*' => $this->http->response(['message' => 'boom'], 500)]);

        $report = $this->reconciler()->reconcile('org-1');

        $this->assertFalse($report->checkedProvider);
        $this->assertFalse($report->isClean());
        $this->assertNotNull($report->error);
        $this->assertStringContainsString('Could not reach DigitalOcean', $report->summary());
    }

    public function test_without_a_credential_local_checks_still_run(): void
    {
        CarbonImmutable::setTestNow('2026-11-04 12:00:00');
        $r = $this->resources->declare('org-2', 'server', 'web-stuck');
        $this->resources->transition($r, ResourceState::Provisioning);
        CarbonImmutable::setTestNow('2026-11-04 14:00:00');

        $this->http->fake();
        $report = $this->reconciler()->reconcile('org-2');
        CarbonImmutable::setTestNow();

        // No credential, so nothing could be asked of DigitalOcean. The stuck
        // row is still found, because that check needs no API call.
        $this->assertFalse($report->checkedProvider);
        $this->assertCount(1, $report->stuck);
        $this->http->assertNothingSent();
    }

    public function test_a_consistent_organisation_reports_clean(): void
    {
        $this->trackedActive('web-1', '111');
        $this->fakeDroplets([$this->remoteDroplet('111', 'web-1')]);

        $report = $this->reconciler()->reconcile('org-1');

        $this->assertTrue($report->isClean());
        $this->assertFalse($report->hasCostlyFindings());
        $this->assertStringContainsString('consistent', $report->summary());
    }

    // ---- adoption ----

    /**
     * An orphan whose Droplet is found by tag can be recovered rather than
     * abandoned, which is the whole reason resources are tagged.
     */
    public function test_an_orphan_is_adopted_when_its_droplet_is_found(): void
    {
        $r = $this->resources->declare('org-1', 'server', 'web-1');
        $r = $this->resources->transition($r, ResourceState::Provisioning);
        $this->resources->transition($r, ResourceState::Orphaned, 'create timed out');

        $adopted = $this->reconciler()->adopt('org-1', $this->remoteDroplet('444', 'web-1'));

        $this->assertTrue($adopted);

        $recovered = $this->resources->find('org-1', 'server', 'web-1');
        $this->assertSame(ResourceState::Active, $recovered->state);
        $this->assertSame('444', $recovered->externalId);
        $this->assertFalse($recovered->isUntrackable());
    }

    /**
     * Two droplets claiming one slug. Overwriting the id would abandon
     * whichever one we already knew about, so refuse and let a human look.
     */
    public function test_adoption_refuses_when_the_resource_already_points_elsewhere(): void
    {
        $this->trackedActive('web-1', '111');

        $adopted = $this->reconciler()->adopt('org-1', $this->remoteDroplet('999', 'web-1'));

        $this->assertFalse($adopted);
        $this->assertSame('111', $this->resources->find('org-1', 'server', 'web-1')->externalId);

        $this->assertNotNull(
            DB::table('audit_records')->where('action', 'reconcile.adoption_conflict')->first()
        );
    }

    public function test_adoption_declines_a_droplet_with_no_resource_tag(): void
    {
        $this->resources->declare('org-1', 'server', 'web-1');

        $untagged = ['id' => 777, 'name' => 'web-1', 'tags' => ['cloud-manager']];

        // Without a resource tag we cannot confidently say which record this
        // belongs to, and attaching a record to the wrong machine is worse
        // than leaving it for a human.
        $this->assertFalse($this->reconciler()->adopt('org-1', $untagged));
    }

    public function test_adoption_declines_an_unknown_resource(): void
    {
        $this->assertFalse(
            $this->reconciler()->adopt('org-1', $this->remoteDroplet('888', 'never-declared'))
        );
    }

    // ---- reconciliation never destroys ----

    /**
     * Nothing here deletes anything. Automatically destroying something that
     * looked untracked is how a customer's production database disappears
     * because a migration half-ran.
     */
    public function test_reconciliation_issues_no_destructive_calls(): void
    {
        $this->trackedActive('web-1', '111');
        $this->fakeDroplets([
            $this->remoteDroplet('222', 'web-ghost'),
        ]);

        $this->reconciler()->reconcile('org-1');

        $this->http->assertSent(function ($request): bool {
            return ! in_array($request->method(), ['DELETE', 'PUT', 'POST'], true);
        });
    }
}
