<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Heartbeats;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * "Did it run?": a monitored scheduler or job pings a URL after each
 * successful run, and an alert opens when the ping is late.
 */
final class HeartbeatTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'app.url' => 'https://cp.example.com']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $http = new HttpFactory();
        $http->fake(fn () => $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]));
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function site(): object
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['current_release' => '20261110-1']);

        return DB::table('sites')->where('id', $site->id)->first();
    }

    private function job(object $site, string $frequency = 'daily'): int
    {
        return $this->app->make(Sites::class)->saveJob($site, 0, ['name' => 'Prune exports', 'command' => '{php} artisan exports:prune', 'frequency' => $frequency, 'at' => '02:30', 'cron' => '', 'everywhere' => false]);
    }

    private function cron(object $site): string
    {
        return base64_decode($this->app->make(Deployer::class)->schedulerStep($site)['content_b64']);
    }

    public function test_a_monitored_scheduler_and_job_ping_after_a_successful_run(): void
    {
        $site = $this->site();
        $id = $this->job($site);
        $this->assertStringNotContainsString('curl', $this->cron($site), 'Off until asked for.');

        Heartbeats::enable((int) $site->id, null);
        Heartbeats::enable((int) $site->id, $id);
        Heartbeats::enable((int) $site->id, $id);
        $this->assertSame(2, DB::table('heartbeats')->count(), 'A second enable keeps the first token.');

        $lines = array_values(array_filter(explode("\n", $this->cron($site)), fn ($l) => str_contains($l, 'artisan') || str_contains($l, 'flock')));
        $this->assertMatchesRegularExpression('#schedule:run >> /dev/null 2>&1 && curl -fsS -m 10 --retry 2 -o /dev/null \'https://cp.example.com/heartbeat/[0-9a-f]{40}\'$#', $lines[0]);
        $this->assertMatchesRegularExpression('#cron-'.$id.'\.log 2>&1 && curl .*/heartbeat/[0-9a-f]{40}\'$#', $lines[1], 'The ping follows the run, so a failed run does not ping.');

        Heartbeats::disable((int) $site->id, $id);
        $this->assertStringNotContainsString('exports:prune\' >> '.'/home/deploy/shop/shared/storage/logs/cron-'.$id.'.log 2>&1 && curl', $this->cron($site));
    }

    public function test_the_token_is_stored_hashed_and_encrypted_and_the_ping_url_records_it(): void
    {
        $site = $this->site();
        Heartbeats::enable((int) $site->id, null);
        $url = Heartbeats::urls((int) $site->id)[0];
        $token = basename($url);

        $row = DB::table('heartbeats')->first();
        $this->assertNotSame($token, $row->token);
        $this->assertNotSame($token, $row->token_hash);
        $this->assertSame(hash('sha256', $token), $row->token_hash);

        $this->get("/heartbeat/{$token}")->assertNoContent();
        $this->assertNotNull(DB::table('heartbeats')->value('last_ping_at'));
        $this->post("/heartbeat/{$token}")->assertNoContent();
        $this->get('/heartbeat/'.str_repeat('a', 40))->assertNotFound();
        $this->get('/heartbeat/short')->assertNotFound();
    }

    public function test_a_quiet_scheduler_is_overdue_after_the_grace_and_a_ping_clears_it(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:30');
        $site = $this->site();
        Heartbeats::enable((int) $site->id, null);
        DB::table('heartbeats')->update(['created_at' => '2026-11-10 11:59:00']);

        $this->assertSame([], Heartbeats::overdue('org-1'), 'Monitoring only just began: nothing late yet.');
        DB::table('heartbeats')->update(['created_at' => '2026-11-10 11:00:00', 'last_ping_at' => '2026-11-10 11:50:00']);
        $alerts = Heartbeats::overdue('org-1');
        $this->assertCount(1, $alerts);
        $this->assertStringContainsString('The Laravel scheduler of shop did not run', array_values($alerts)[0]['title']);
        $this->assertStringContainsString("tab=scheduler", array_values($alerts)[0]['link']);

        DB::table('heartbeats')->update(['last_ping_at' => '2026-11-10 12:00:10']);
        $this->assertSame([], Heartbeats::overdue('org-1'));
    }

    public function test_a_daily_job_is_judged_against_its_own_schedule(): void
    {
        $site = $this->site();
        $id = $this->job($site);
        Heartbeats::enable((int) $site->id, $id);
        DB::table('heartbeats')->update(['created_at' => '2026-11-09 01:00:00', 'last_ping_at' => '2026-11-09 02:30:20']);

        Carbon::setTestNow('2026-11-10 02:33:00');
        $this->assertSame([], Heartbeats::overdue('org-1'), 'Within the grace.');
        Carbon::setTestNow('2026-11-10 02:40:00');
        $this->assertCount(1, Heartbeats::overdue('org-1'));
        Carbon::setTestNow('2026-11-10 14:00:00');
        $this->assertCount(1, Heartbeats::overdue('org-1'), 'Still missed until it reports.');

        DB::table('heartbeats')->update(['last_ping_at' => '2026-11-10 02:30:40']);
        $this->assertSame([], Heartbeats::overdue('org-1'));
    }

    public function test_a_paused_job_a_stopped_scheduler_and_an_undeployed_site_raise_nothing(): void
    {
        Carbon::setTestNow('2026-11-10 12:00:00');
        $site = $this->site();
        $id = $this->job($site, 'every_minute');
        Heartbeats::enable((int) $site->id, $id);
        Heartbeats::enable((int) $site->id, null);
        DB::table('heartbeats')->update(['created_at' => '2026-11-10 09:00:00']);
        $this->assertCount(2, Heartbeats::overdue('org-1'));

        $this->app->make(Sites::class)->setJobPaused($site, $id, true);
        $this->app->make(Sites::class)->setScheduler($site, false);
        $this->assertSame([], Heartbeats::overdue('org-1'));

        $this->app->make(Sites::class)->setJobPaused($site, $id, false);
        DB::table('sites')->where('id', $site->id)->update(['current_release' => null, 'scheduler' => true]);
        $this->assertSame([], Heartbeats::overdue('org-1'), 'Nothing runs before the first deploy.');
        $this->assertSame([], Heartbeats::overdue('org-2'));
    }

    public function test_heartbeats_go_with_their_job(): void
    {
        $site = $this->site();
        $id = $this->job($site);
        Heartbeats::enable((int) $site->id, $id);
        $this->app->make(Sites::class)->deleteJob($site, $id);
        $this->assertSame(0, DB::table('heartbeats')->count());
    }

    public function test_the_console_monitors_the_scheduler_and_a_job(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();
        $url = "/site/settings/{$site->id}";

        $this->get("/site/settings?site={$site->id}&tab=scheduler")->assertInertia(fn (Assert $page) => $page->where('data.schedulerMonitored', false));
        $this->post("{$url}/scheduler/monitor", ['on' => true]);
        $this->assertNotNull(Heartbeats::find((int) $site->id, null));
        $this->get("/site/settings?site={$site->id}&tab=scheduler")->assertInertia(fn (Assert $page) => $page->where('data.schedulerMonitored', true)->where('data.schedulerPing', fn ($ago) => $ago === null || $ago === '' || is_string($ago)));
        $this->post("{$url}/scheduler/monitor", ['on' => false]);
        $this->assertNull(Heartbeats::find((int) $site->id, null));

        $job = ['id' => 0, 'name' => 'Prune', 'command' => 'ls', 'frequency' => 'daily', 'at' => '02:30', 'cron' => '', 'everywhere' => false, 'monitored' => true];
        $this->post("{$url}/jobs", $job);
        $id = (int) DB::table('scheduled_jobs')->value('id');
        $this->assertNotNull(Heartbeats::find((int) $site->id, $id));
        $this->get("/site/settings?site={$site->id}&tab=scheduler")->assertInertia(fn (Assert $page) => $page->where('data.jobs.0.monitored', true));
        $this->post("{$url}/jobs", ['id' => $id, 'monitored' => false] + $job);
        $this->assertNull(Heartbeats::find((int) $site->id, $id));
    }
}
