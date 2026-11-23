<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Sites\WorkerScaling;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;
use Tests\TestCase;

/**
 * Workers follow the queue: up while jobs wait, back to the fewest (zero
 * allowed) only after the queue has stayed empty, without disturbing the
 * workers that keep running.
 */
final class WorkerScalingTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    private int $depth = 0;

    /** @var list<array{node: string, args: list<string>}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->http = new HttpFactory();
        $this->http->fake(['gw.test/*' => function (Request $r) {
            preg_match('#/v1/nodes/([^/]+)/commands#', $r->url(), $m);
            $args = $r['args']['args'] ?? [];
            $this->calls[] = ['node' => $m[1], 'args' => $args];
            $output = in_array('queue:monitor', $args, true)
                ? json_encode([['connection' => 'redis', 'queue' => 'default', 'size' => $this->depth]])
                : '';

            return $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $output]]);
        }]);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()));
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog());
    }

    private function scaling(): WorkerScaling
    {
        return $this->app->make(WorkerScaling::class);
    }

    private function site(int $min = 0, int $max = 4): stdClass
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['current_release' => 'r1']);
        DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->scaling()->enable($this->sites()->find('web-1.org-1', 'shop'), $min, $max);

        return $this->sites()->find('web-1.org-1', 'shop');
    }

    public function test_the_target_grows_with_waiting_jobs_and_shrinks_only_after_an_idle_spell(): void
    {
        $now = Carbon::parse('2026-11-07 12:00:00');
        $t = fn (int $current, int $depth, ?Carbon $idle = null) => WorkerScaling::target($current, 0, 5, $depth, 2, $idle, $now);

        $this->assertSame(1, $t(0, 1), 'From zero, one job wakes a worker.');
        $this->assertSame(3, $t(1, 60), '60 jobs over two servers at 10 each.');
        $this->assertSame(5, $t(1, 900), 'Never past the most.');
        $this->assertSame(4, $t(4, 3), 'Never fewer while jobs wait.');
        $this->assertSame(4, $t(4, 0, $now->copy()->subMinutes(2)), 'Empty, but not for long enough.');
        $this->assertSame(0, $t(4, 0, $now->copy()->subMinutes(5)), 'Empty for five minutes: back to the fewest.');
        $this->assertSame(2, WorkerScaling::target(0, 2, 5, 0, 1, null, $now), 'Never below the fewest.');
    }

    public function test_scaling_starts_and_stops_single_processes_and_stops_in_the_background(): void
    {
        $this->assertSame(
            'supervisorctl start cm-shop-worker:cm-shop-worker_00 cm-shop-worker:cm-shop-worker_01 >/dev/null 2>&1; '
            .'setsid -f supervisorctl stop cm-shop-worker:cm-shop-worker_02 >/dev/null 2>&1; true',
            WorkerScaling::command('shop', 2, 3),
        );
        $this->assertStringNotContainsString('start', WorkerScaling::command('shop', 0, 2), 'Zero: all stopped.');
    }

    public function test_a_queue_that_fills_wakes_workers_on_every_server_and_an_idle_one_puts_them_to_sleep(): void
    {
        $site = $this->site(min: 0, max: 4);
        $this->assertSame(1, (int) $site->queue_workers, 'The running count is kept while it is within the limits.');

        $this->depth = 25;
        $this->assertSame('1 → 2 workers per server, 25 waiting', $this->scaling()->tick($site));
        $applied = array_values(array_filter($this->calls, fn ($c) => ($c['args'][0] ?? null) === '-c'));
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], array_column($applied, 'node'));
        $this->assertSame(WorkerScaling::command('shop', 2, 4), $applied[0]['args'][1]);
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.workers_scaled')->count());

        $this->depth = 0;
        $site = $this->sites()->find('web-1.org-1', 'shop');
        $this->assertNull($this->scaling()->tick($site), 'Just emptied: they stay.');
        $this->travel(6)->minutes();
        $site = $this->sites()->find('web-1.org-1', 'shop');
        $this->assertSame('2 → 0 workers per server, 0 waiting', $this->scaling()->tick($site));
        $this->assertSame(0, (int) $this->sites()->find('web-1.org-1', 'shop')->queue_workers);
    }

    public function test_a_deploy_configures_the_most_and_runs_only_what_scaling_wants(): void
    {
        $site = $this->site(min: 1, max: 4);
        $deployer = new Deployer($this->sites(), $this->app->make(GatewayClient::class), new AuditLog(),
            new GitHubApp($this->http, $this->app->make(Vault::class), new AuditLog()));

        $steps = collect($deployer->afterSwitchRecipe($site)['steps'])->keyBy('id');
        $this->assertStringContainsString('numprocs=4', base64_decode($steps['supervisor-conf']['content_b64']));
        $this->assertSame(WorkerScaling::command('shop', 1, 4), $steps['workers-scaled']['args'][1]);

        $this->scaling()->disable($site, 2);
        $fixed = $this->sites()->find('web-1.org-1', 'shop');
        $steps = collect($deployer->afterSwitchRecipe($fixed)['steps'])->keyBy('id');
        $this->assertStringContainsString('numprocs=2', base64_decode($steps['supervisor-conf']['content_b64']));
        $this->assertFalse($steps->has('workers-scaled'));
    }

    public function test_depth_is_read_from_the_table_when_json_is_not_there(): void
    {
        $this->assertSame(7, WorkerScaling::parseDepth(" Queue name .. Size / Status \n [redis] default .. [7] OK \n"));
        $this->assertSame(3, WorkerScaling::parseDepth('[{"queue":"default","size":3}]'));
    }

    public function test_limits_that_make_no_sense_are_refused(): void
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $this->expectException(\InvalidArgumentException::class);
        $this->scaling()->enable($site, 3, 2);
    }
}
