<?php

declare(strict_types=1);

namespace Tests\Feature\Scaling;

use App\Lib\Agent\AgentRelease;
use App\Lib\Alerts\Alerts;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CloudInit;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Providers;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Scaling\EdgeDriver;
use App\Lib\Scaling\ServerScaler;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

/**
 * One autoscaler for every provider: every rule it keeps is tested with a
 * clock and numbers, then the operations that add and remove a server are
 * run against a faked provider and gateway.
 */
final class ServerScalerTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private HttpFactory $http;

    /** @var list<Request> */
    private array $sent = [];

    /** @var array<string, bool> node => connected */
    private array $connected = [];

    private int $nextDroplet = 500;

    /** @var list<string> what the balancer was told */
    public static array $applied = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/scaler-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/cloud-manager-agent-linux-amd64", 'amd64 build');
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.agent_directory' => $this->dir,
            'control_plane.public_url' => 'https://agent.example.com', 'control_plane.server_name' => 'agent.example.com',
            'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        Queue::fake();
        Cache::store('array')->clear();
        self::$applied = [];
        $this->http = new HttpFactory;
        $this->app->instance(HttpFactory::class, $this->http);
        foreach ([KeyManager::class, Vault::class, AgentRelease::class, CloudInit::class, CredentialStore::class, EnrollmentService::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $this->app->instance(CredentialStore::class, new CredentialStore($this->app->make(Vault::class), $this->http, Cache::store('array'), new AuditLog));
        $this->app->bind(EdgeDriver::class, fn () => new class implements EdgeDriver
        {
            public function apply(stdClass $site): string
            {
                ServerScalerTest::$applied[] = implode(',', Sites::nodes($site));

                return 'balancer updated';
            }

            public function syncCertificate(stdClass $site): string
            {
                return '';
            }
        });
        $this->http->fake(function (Request $r) {
            $this->sent[] = $r;
            $url = $r->url();
            if (preg_match('#gw\.test/v1/nodes/([^/]+)$#', $url, $m)) {
                return $this->http->response(['connected' => $this->connected[$m[1]] ?? false]);
            }
            if (str_contains($url, 'gw.test')) {
                return $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '', 'run' => ['steps' => []]]]);
            }
            if ($r->method() === 'POST' && str_ends_with($url, '/droplets')) {
                return $this->http->response(['droplet' => ['id' => $this->nextDroplet++]], 202);
            }
            if ($r->method() === 'GET' && preg_match('#/droplets/(\d+)$#', $url, $m)) {
                return $this->http->response(['droplet' => ['id' => (int) $m[1], 'status' => 'active', 'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.50']]]]]);
            }

            return $this->http->response([], 204);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog));
        foreach ([ServerScaler::class, ServerProvisioner::class, Providers::class, OperationRunner::class] as $c) {
            $this->app->forgetInstance($c);
        }
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->make(CredentialStore::class)->connect('org-1', 'dop_v1_test_token');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    /** Real records for what autoscaling needs attached, so nothing relies on which auto-increment id a row happens to get. @return array<string, int> */
    private function attached(): array
    {
        $resources = $this->app->make(ResourceManager::class);
        $ids = [];
        foreach (['load_balancer_id' => ['load_balancer', 'shop-lb', 'lb-ext'], 'bucket_id' => ['backup_bucket', 'shop-bucket', 'bucket-ext'], 'database_cluster_id' => ['database_cluster', 'shop-db', 'db-ext']] as $column => [$type, $slug, $external]) {
            $record = $resources->declare('org-1', $type, $slug, ['region' => 'nyc3']);
            $resources->recordExternalId($record, $external);
            $ids[$column] = (int) $record->id;
        }

        return $ids;
    }

    private function scaler(): ServerScaler
    {
        return $this->app->make(ServerScaler::class);
    }

    /** A DigitalOcean application with everything autoscaling needs. */
    private function site(string $provider = 'digitalocean', bool $ready = true): stdClass
    {
        $resources = $this->app->make(ResourceManager::class);
        $web1 = $resources->declare('org-1', 'server', 'web-1', ['region' => 'nyc3'], provider: $provider);
        $resources->recordExternalId($web1, '111');
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'provider' => $provider, 'created_at' => now(), 'updated_at' => now()]);
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['provider' => $provider] + ($ready ? [...$this->attached(), 'current_release' => 'r1'] : []));
        if ($ready) {
            // What attaching a cluster and a cache does: every server reaches the same ones.
            $this->app->make(Sites::class)->setInjected(DB::table('sites')->find($site->id), ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'CACHE_STORE' => 'redis', 'SESSION_DRIVER' => 'redis', 'QUEUE_CONNECTION' => 'redis'], 'test cluster');
        }

        return DB::table('sites')->find($site->id);
    }

    private function scaling(array $spec = []): stdClass
    {
        $site = $this->site();
        $this->scaler()->create($site, $spec + ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);

        return DB::table('sites')->find($site->id);
    }

    /** Samples at 15 second intervals for the last $minutes, all at $cpu percent. */
    private function cpu(string $node, float $cpu, int $minutes = 12): void
    {
        for ($t = $minutes * 60; $t >= 0; $t -= 15) {
            DB::table('node_metrics')->insert(['organization_id' => 'org-1', 'node_id' => $node, 'collected_at' => now()->subSeconds($t), 'cpu' => $cpu, 'load_1' => 0,
                'mem_total' => 1, 'mem_available' => 1, 'disk_total' => 1, 'disk_free' => 1, 'uptime' => 1]);
        }
    }

    /** A server the scaler made, joined to the site. */
    private function member(stdClass $site, string $slug, bool $joined = true, ?string $enteredAt = null): string
    {
        $pool = (int) $site->autoscale_pool_id;
        $resources = $this->app->make(ResourceManager::class);
        $r = $resources->declare('org-1', 'server', $slug, ['pool' => $pool, 'scaler' => true, 'region' => 'nyc3']);
        $r = $resources->transition($r, ResourceState::Provisioning, reason: 't');
        $r = $resources->recordExternalId($r, (string) $this->nextDroplet++);
        $resources->transition($r, ResourceState::Active, reason: 't');
        if ($enteredAt !== null) {
            DB::table('resources')->where('slug', $slug)->update(['state_entered_at' => $enteredAt]);
        }
        $node = "{$slug}.org-1";
        DB::table('nodes')->insert(['node_id' => $node, 'organization_id' => 'org-1', 'provider' => 'digitalocean', 'created_at' => now(), 'updated_at' => now()]);
        if ($joined) {
            DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => $node, 'autoscale_pool_id' => $pool, 'created_at' => now(), 'updated_at' => now()]);
        }

        return $node;
    }

    // ---- the rules, as pure decisions ------------------------------------------------------

    private function load(float $avg, ?float $latest = null, ?float $peak = null, int $samples = 20): array
    {
        return ['avg' => $avg, 'latest' => $latest ?? $avg, 'peak' => $peak ?? $avg, 'samples' => $samples];
    }

    public function test_the_decision_rules(): void
    {
        $now = CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC');
        $a = ['min' => 1, 'max' => 3, 'cpu' => 0.6];

        foreach ([
            'below the minimum' => [$a, 0, null, null, 'up'],
            'above the maximum' => [$a, 4, null, null, 'down'],
            'hot and sustained' => [$a, 1, $this->load(0.8), $this->load(0.8), 'up'],
            'hot but already at the maximum' => [$a, 3, $this->load(0.9), $this->load(0.9), null],
            'hot but too few samples' => [$a, 1, $this->load(0.9, samples: 2), null, null],
            'hot on average but already cooling' => [$a, 1, $this->load(0.7, latest: 0.4), $this->load(0.7), null],
            'well under, more than the minimum' => [$a, 2, $this->load(0.1), $this->load(0.2, peak: 0.25), 'down'],
            'well under but at the minimum' => [$a, 1, $this->load(0.1), $this->load(0.1), null],
            'under the target but not well under' => [$a, 2, $this->load(0.4), $this->load(0.4), null],
            'one server still busy' => [$a, 2, $this->load(0.2), $this->load(0.25, peak: 0.7), null],
            'nothing measured' => [$a, 2, null, null, null],
        ] as $name => [$attributes, $count, $up, $down, $expected]) {
            $this->assertSame($expected, ServerScaler::decide($attributes, $count, $up, $down, $now)[0], $name);
        }
    }

    public function test_a_cooldown_follows_every_action_even_below_the_minimum(): void
    {
        $now = CarbonImmutable::parse('2026-11-10 12:00:00', 'UTC');
        $a = ['min' => 2, 'max' => 3, 'cpu' => 0.6, 'cooldown_minutes' => 5, 'last_action_at' => $now->subMinutes(3)->toIso8601String()];

        $this->assertSame([null, 'cooling down'], ServerScaler::decide($a, 0, null, null, $now));
        $this->assertSame('up', ServerScaler::decide($a, 0, null, null, $now->addMinutes(3))[0]);
    }

    public function test_a_schedule_raises_the_minimum_inside_its_window_only(): void
    {
        $a = ['min' => 1, 'max' => 5, 'cpu' => 0.6, 'schedule' => [['days' => 'every', 'from' => 9, 'to' => 17, 'min' => 3]]];

        $this->assertSame('up', ServerScaler::decide($a, 2, null, null, CarbonImmutable::parse('2026-11-10 10:00:00', 'UTC'))[0]);
        $this->assertNull(ServerScaler::decide($a, 2, null, null, CarbonImmutable::parse('2026-11-10 20:00:00', 'UTC'))[0]);
    }

    // ---- turning it on ----------------------------------------------------------------------

    public function test_turning_it_on_records_our_own_pool_and_needs_what_a_new_server_must_find_off_the_server(): void
    {
        $site = $this->site('digitalocean', ready: false);
        try {
            $this->scaler()->create($site, ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('needs a load balancer', $e->getMessage());
        }

        DB::table('sites')->where('id', $site->id)->update([...$this->attached(), 'current_release' => 'r1']);
        $this->app->make(Sites::class)->setInjected(DB::table('sites')->find($site->id), ['DB_CONNECTION' => 'pgsql'], 'test cluster');
        $site = DB::table('sites')->find($site->id);
        $this->scaler()->create($site, ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
        $site = DB::table('sites')->find($site->id);
        $this->assertTrue(ServerScaler::manages($site));
        $pool = $this->scaler()->of($site);
        $this->assertSame(['scaler', 'digitalocean', 'nyc3'], [$pool->attributes['engine'], $pool->attributes['provider'], $pool->attributes['region']]);
        $this->assertDatabaseHas('audit_records', ['action' => 'site.autoscaler_enabled']);
    }

    public function test_a_provider_that_cannot_balance_is_refused_with_the_reason(): void
    {
        $site = $this->site('aws');
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Autoscaling needs a load balancer. AWS does not offer one yet.');
        $this->scaler()->create($site, ['min' => 1, 'max' => 3, 'size' => 't3.small', 'cpu' => 0.6]);
    }

    public function test_a_native_pool_is_not_ours(): void
    {
        $site = $this->site();
        $this->app->make(ResourceManager::class)->declare('org-1', 'autoscale_pool', 'shop-pool', ['min' => 1, 'max' => 2]);
        DB::table('sites')->where('id', $site->id)->update(['autoscale_pool_id' => DB::table('resources')->where('slug', 'shop-pool')->value('id')]);

        $this->assertFalse(ServerScaler::manages(DB::table('sites')->find($site->id)));
    }

    // ---- the loop ---------------------------------------------------------------------------

    public function test_below_the_minimum_it_adds_a_server_and_then_cools_down(): void
    {
        $site = $this->scaling();

        $said = $this->scaler()->tick($site);

        $this->assertStringContainsString('adding shop-s1', (string) $said);
        $op = DB::table('operations')->where('kind', 'scaler_up')->first();
        $this->assertSame('shop-s1', json_decode($op->meta, true)['slug']);
        $this->assertNull($this->scaler()->tick($site), 'One action at a time: an add is already running.');

        DB::table('operations')->update(['status' => 'succeeded']);
        $this->assertNull($this->scaler()->tick($site), 'And a cooldown after it.');
        $this->assertDatabaseHas('audit_records', ['action' => 'site.autoscaled']);
    }

    public function test_sustained_heat_adds_one_and_the_next_name_is_never_reused(): void
    {
        $site = $this->scaling();
        $this->member($site, 'shop-s1');
        $this->cpu('web-1.org-1', 90);
        $this->cpu('shop-s1.org-1', 90);

        $this->assertStringContainsString('adding shop-s2', (string) $this->scaler()->tick($site));
    }

    public function test_sustained_calm_removes_the_newest_server_it_made_never_the_primary(): void
    {
        $site = $this->scaling(['min' => 1]);
        $one = $this->member($site, 'shop-s1');
        $two = $this->member($site, 'shop-s2');
        foreach (['web-1.org-1', $one, $two] as $node) {
            $this->cpu($node, 5);
        }

        $this->assertStringContainsString('removing shop-s2', (string) $this->scaler()->tick($site));
        $op = DB::table('operations')->where('kind', 'scaler_down')->first();
        $this->assertSame($two, json_decode($op->meta, true)['node']);
    }

    public function test_it_never_goes_below_the_minimum_when_calm(): void
    {
        $site = $this->scaling(['min' => 1]);
        $one = $this->member($site, 'shop-s1');
        foreach (['web-1.org-1', $one] as $node) {
            $this->cpu($node, 1);
        }

        $this->assertNull($this->scaler()->tick($site));
        $this->assertSame(0, DB::table('operations')->where('kind', 'scaler_down')->count());
    }

    public function test_a_server_that_never_joined_is_destroyed_rather_than_left_billing(): void
    {
        $site = $this->scaling();
        $this->member($site, 'shop-s1', joined: false, enteredAt: now()->subMinutes(30)->toDateTimeString());

        $this->assertStringContainsString('never joined', (string) $this->scaler()->tick($site));
        $op = DB::table('operations')->where('kind', 'scaler_down')->first();
        $this->assertTrue(json_decode($op->meta, true)['stranded']);
    }

    public function test_a_deploy_in_progress_holds_the_scaler_still(): void
    {
        $site = $this->scaling();
        DB::table('operations')->insert(['organization_id' => 'org-1', 'kind' => 'deploy', 'title' => 'd', 'status' => 'running', 'trigger' => 'x', 'actor' => 'x',
            'site_id' => $site->id, 'node_id' => $site->node_id, 'stages' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNull($this->scaler()->tick($site));
    }

    // ---- adding a server --------------------------------------------------------------------

    public function test_adding_creates_the_server_waits_for_its_agent_then_hands_over_to_the_join(): void
    {
        $site = $this->scaling();
        $runner = $this->app->make(OperationRunner::class);
        $op = $runner->queueScalerUp($site->id, 'shop-s1', 'autoscale', 'autoscaler');

        $this->assertSame('waiting', $runner->run($op), 'Created, but its agent has not connected.');
        $record = $this->app->make(ResourceManager::class)->find('org-1', 'server', 'shop-s1');
        $this->assertSame([(int) $site->autoscale_pool_id, true, 'digitalocean'], [$record->attributes['pool'], $record->attributes['scaler'], $record->provider]);
        $create = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/droplets'));
        $this->assertSame(['nyc3', 's-1vcpu-1gb'], [$create['region'], $create['size']]);
        $this->assertContains('resource:shop-s1', $create['tags']);

        $this->connected['shop-s1.org-1'] = true;
        DB::table('nodes')->updateOrInsert(['node_id' => 'shop-s1.org-1'], ['organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('succeeded', $runner->run($op));
        $join = DB::table('operations')->where('kind', 'pool_member')->first();
        $this->assertSame('shop-s1.org-1', $join->node_id);
        $this->assertSame('autoscaler', $join->actor);
    }

    public function test_a_server_whose_agent_never_connects_is_destroyed_so_it_does_not_bill(): void
    {
        $site = $this->scaling();
        $runner = $this->app->make(OperationRunner::class);
        $op = $runner->queueScalerUp($site->id, 'shop-s1', 'autoscale', 'autoscaler');
        $this->assertSame('waiting', $runner->run($op));

        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame('failed', $runner->run($op));

        $this->assertStringContainsString('never connected', DB::table('operations')->find($op)->error);
        $this->assertNotNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'DELETE' && preg_match('#/droplets/\d+$#', $r->url())), 'The Droplet was deleted.');
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'shop-s1')->value('state'));
    }

    // ---- removing a server ------------------------------------------------------------------

    public function test_removing_drains_first_then_destroys_and_revokes(): void
    {
        $site = $this->scaling();
        $node = $this->member($site, 'shop-s1');
        $runner = $this->app->make(OperationRunner::class);
        $op = $runner->queueScalerDown($site->id, $node, 'autoscale', 'autoscaler');

        $status = $runner->run($op);
        $this->assertSame('waiting', $status, 'Off the balancer, draining. '.DB::table('operations')->find($op)->error);
        $this->assertSame(0, DB::table('site_nodes')->where('node_id', $node)->count());
        $this->assertSame(['web-1.org-1'], array_map(fn ($a) => $a, self::$applied));
        $this->assertNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), '/droplets/')), 'Nothing destroyed while draining.');

        Carbon::setTestNow(now()->addSeconds(ServerScaler::DRAIN_SECONDS + 1));
        $this->assertSame('succeeded', $runner->run($op));
        $this->assertNotNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'DELETE' && preg_match('#/droplets/\d+$#', $r->url())));
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'shop-s1')->value('state'));
    }

    public function test_a_stranded_server_skips_the_drain(): void
    {
        $site = $this->scaling();
        $node = $this->member($site, 'shop-s1', joined: false);
        $runner = $this->app->make(OperationRunner::class);

        $op = $runner->queueScalerDown($site->id, $node, 'autoscale', 'autoscaler', stranded: true);

        $this->assertSame('succeeded', $runner->run($op));
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'shop-s1')->value('state'));
    }

    public function test_it_refuses_to_destroy_the_primary_or_a_server_it_did_not_make(): void
    {
        $site = $this->scaling();
        $other = $this->app->make(ResourceManager::class)->declare('org-1', 'server', 'web-2', ['region' => 'nyc3']);
        $this->app->make(ResourceManager::class)->recordExternalId($other, '222');
        $runner = $this->app->make(OperationRunner::class);

        foreach (['web-1.org-1', 'web-2.org-1'] as $node) {
            $op = $runner->queueScalerDown($site->id, $node, 'autoscale', 'autoscaler');
            $this->assertSame('failed', $runner->run($op));
            $this->assertStringContainsString('not a server the autoscaler made', DB::table('operations')->find($op)->error);
        }
        $this->assertNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'DELETE'));
    }

    // ---- limits, schedule and turning it off, through the same operations as before ---------

    public function test_the_existing_resize_and_stop_operations_drive_the_scaler(): void
    {
        $site = $this->scaling();
        $node = $this->member($site, 'shop-s1');
        $runner = $this->app->make(OperationRunner::class);

        $this->assertSame('succeeded', $runner->run($runner->queuePoolResize($site->id, 2, 5, 'console', 'matt')));
        $attrs = $this->scaler()->of($site)->attributes;
        $this->assertSame([2, 5, 2], [$attrs['min'], $attrs['max'], $attrs['live_min']]);

        $this->assertSame('succeeded', $runner->run($runner->queuePoolRemove($site->id, 'console', 'matt')));
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('autoscale_pool_id'));
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'shop-s1')->value('state'));
        $this->assertSame(0, DB::table('site_nodes')->where('node_id', $node)->count());
    }

    public function test_the_minutely_command_visits_only_our_sites_and_the_old_reconcile_skips_them(): void
    {
        $site = $this->scaling();

        $this->artisan('sites:scale-servers')->expectsOutputToContain('adding shop-s1')->assertSuccessful();
        $this->artisan('sites:reconcile-pools')->assertSuccessful();
        $this->assertSame(0, DB::table('operations')->where('kind', 'pool_leave')->count());
    }

    // ---- stopping a fault that repeats ------------------------------------------------------

    public function test_an_application_whose_variables_still_point_at_sqlite_cannot_be_autoscaled_and_is_told_which(): void
    {
        $site = $this->site('digitalocean', ready: false);
        DB::table('sites')->where('id', $site->id)->update([...$this->attached(), 'current_release' => 'r1']);
        $sites = $this->app->make(Sites::class);
        $sites->setInjected(DB::table('sites')->find($site->id), ['DB_CONNECTION' => 'pgsql'], 'cluster');
        $sites->setEnvValues(DB::table('sites')->find($site->id), ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/home/deploy/shop/shared/database.sqlite']);

        try {
            $this->scaler()->create(DB::table('sites')->find($site->id), ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('uses SQLite', $e->getMessage());
            $this->assertStringContainsString('override DB_CONNECTION', $e->getMessage());
            $this->assertStringContainsString('remove them from Variables', $e->getMessage());
        }
    }

    public function test_a_scaler_already_running_on_such_an_application_stops_at_once_instead_of_making_servers(): void
    {
        $site = $this->scaling();
        $this->app->make(Sites::class)->setEnvValues($site, ['DB_CONNECTION' => 'sqlite']);

        $this->assertNull($this->scaler()->tick(DB::table('sites')->find($site->id)));

        $pool = $this->scaler()->of($site);
        $this->assertTrue($this->scaler()->paused($pool));
        $this->assertStringContainsString('uses SQLite', $pool->attributes['paused_because']);
        $this->assertSame(0, DB::table('operations')->where('kind', 'scaler_up')->count(), 'Not one server was made.');
        $this->assertDatabaseHas('audit_records', ['action' => 'site.autoscaler_paused']);
    }

    public function test_file_based_sessions_or_queue_are_refused_too(): void
    {
        $site = $this->scaling();
        $this->app->make(Sites::class)->setEnvValues($site, ['QUEUE_CONNECTION' => 'file']);

        $this->assertStringContainsString("a server's own disk", (string) $this->scaler()->problem(DB::table('sites')->find($site->id)));
    }

    public function test_servers_that_fail_to_join_in_a_row_stop_the_scaler_and_a_join_starts_the_count_again(): void
    {
        $site = $this->scaling();
        $runner = $this->app->make(OperationRunner::class);
        $fail = fn () => $this->scaler()->recordFailure($site, 'queue:restart failed: no database file');

        $fail();
        $fail();
        $this->assertFalse($this->scaler()->paused($this->scaler()->of($site)));
        $this->scaler()->recordJoined($site);
        $this->assertSame(0, $this->scaler()->of($site)->attributes['failures'], 'A server that joined means the fault is not there.');

        $fail();
        $fail();
        $fail();
        $pool = $this->scaler()->of($site);
        $this->assertTrue($this->scaler()->paused($pool));
        $this->assertStringContainsString('3 servers in a row could not join', $pool->attributes['paused_because']);
        $this->assertStringContainsString('no database file', $pool->attributes['paused_because']);

        $this->assertNull($this->scaler()->tick(DB::table('sites')->find($site->id)), 'Paused: nothing is made, though it is below the minimum.');
        $this->assertSame(0, DB::table('operations')->where('kind', 'scaler_up')->count());
    }

    public function test_an_operation_that_fails_for_an_autoscaled_server_counts_against_it(): void
    {
        $site = $this->scaling();
        $runner = $this->app->make(OperationRunner::class);
        // A join that cannot work: the member is not known, so it fails at once.
        foreach (range(1, 3) as $i) {
            $op = $runner->queuePoolJoin($site->id, "ghost-{$i}.org-1");
            DB::table('operations')->where('id', $op)->update(['kind' => 'pool_join', 'meta' => json_encode(['node' => "ghost-{$i}.org-1"])]);
            $runner->run($op);
        }

        $this->assertTrue($this->scaler()->paused($this->scaler()->of($site)));
    }

    public function test_it_will_not_resume_until_the_cause_is_put_right_and_then_carries_on(): void
    {
        $site = $this->scaling();
        $sites = $this->app->make(Sites::class);
        $sites->setEnvValues($site, ['DB_CONNECTION' => 'sqlite']);
        $this->scaler()->tick(DB::table('sites')->find($site->id));

        try {
            $this->scaler()->resume(DB::table('sites')->find($site->id));
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('cannot carry on yet', $e->getMessage());
        }

        $sites->setEnvValues(DB::table('sites')->find($site->id), ['DB_CONNECTION' => 'pgsql']);
        DB::table('sites')->where('id', $site->id)->update(['updated_at' => now()]);
        $sites->releaseInjected(DB::table('sites')->find($site->id), []);
        $this->scaler()->resume(DB::table('sites')->find($site->id));

        $pool = $this->scaler()->of($site);
        $this->assertFalse($this->scaler()->paused($pool));
        $this->assertSame(0, $pool->attributes['failures']);
        $this->assertStringContainsString('adding shop-s1', (string) $this->scaler()->tick(DB::table('sites')->find($site->id)));
    }

    public function test_a_stopped_scaler_is_an_alert(): void
    {
        $site = $this->scaling();
        $this->scaler()->recordFailure($site, 'x');
        $this->scaler()->recordFailure($site, 'x');
        $this->scaler()->recordFailure($site, 'the real reason');
        $this->http->fake(['gw.test/*' => $this->http->response(['nodes' => []])]);

        $alerts = (new \ReflectionMethod(Alerts::class, 'conditions'));
        $found = $alerts->invoke($this->app->make(Alerts::class), 'org-1');

        $alert = collect($found)->first(fn ($a, $k) => str_starts_with((string) $k, 'autoscaler:'));
        $this->assertSame('critical', $alert['severity']);
        $this->assertStringContainsString('has stopped adding servers', $alert['title']);
        $this->assertStringContainsString('the real reason', $alert['detail']);
    }
}
