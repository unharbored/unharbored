<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The server list (pools grouped, price, load), one server's page, and one pool's page. */
final class ServerPagesTest extends TestCase
{
    use RefreshDatabase;

    private object $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $sites = new Sites($this->app->make(Vault::class), new AuditLog);
        $this->site = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
    }

    private function server(string $slug, string $size = 's-1vcpu-2gb'): void
    {
        DB::table('nodes')->insert(['node_id' => "{$slug}.org-1", 'organization_id' => 'org-1', 'agent_version' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => $slug, 'provider' => 'digitalocean', 'external_id' => (string) random_int(1, 999999),
            'state' => 'active', 'desired_state' => 'active', 'attributes' => json_encode(['region' => 'nyc3', 'size' => $size, 'ipv4' => '203.0.113.9']), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** web-1 as the primary of an autoscaled application with one server the scaler added. */
    private function pool(): int
    {
        $this->server('web-1');
        $this->server('shop-pool-1');
        $pool = DB::table('resources')->insertGetId(['organization_id' => 'org-1', 'type' => 'autoscale_pool', 'slug' => 'shop-pool', 'provider' => 'digitalocean', 'state' => 'active',
            'desired_state' => 'active', 'attributes' => json_encode(['engine' => 'scaler', 'min' => 1, 'max' => 3, 'cpu' => 0.6,
                'schedule' => [['days' => 'weekdays', 'from' => 13, 'to' => 22, 'min' => 2]]]), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sites')->where('id', $this->site->id)->update(['autoscale_pool_id' => $pool]);
        DB::table('site_nodes')->insert(['site_id' => $this->site->id, 'node_id' => 'shop-pool-1.org-1', 'autoscale_pool_id' => $pool, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->where('slug', 'shop-pool-1')->update(['attributes' => json_encode(['region' => 'nyc3', 'size' => 's-1vcpu-2gb', 'pool' => $pool])]);

        return $this->site->id;
    }

    public function test_the_list_shows_where_each_server_runs_with_its_price_and_an_hour_of_load(): void
    {
        $this->server('web-1');
        DB::table('node_metrics')->insert(['organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'collected_at' => now()->subMinute(), 'cpu' => 30, 'load_1' => 0,
            'mem_total' => 1000, 'mem_available' => 500, 'disk_total' => 100, 'disk_free' => 50, 'uptime' => 1]);

        $this->get('/servers')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Servers/Index')
            ->where('servers.0.name', 'web-1')
            ->where('servers.0.compute.providerLabel', 'DigitalOcean')
            ->where('servers.0.compute.price', 12)
            ->where('servers.0.apps', 1)
            ->where('servers.0.metrics.cpu', 30)
            ->where('servers.0.pool', null)
            ->where('pools', []));
    }

    public function test_servers_of_an_autoscale_pool_are_marked_so_the_list_can_group_them(): void
    {
        $this->pool();

        $this->get('/servers')->assertInertia(fn (Assert $page) => $page
            ->where('pools.0.name', 'shop-pool')
            ->where('pools.0.site.name', 'shop')
            ->where('pools.0.extra', 1)
            ->where('pools.0.min', 1)->where('pools.0.max', 3)->where('pools.0.target', 60)
            ->where('servers.0.name', 'shop-pool-1')->where('servers.0.pool', ['site' => $this->site->id, 'primary' => false])
            ->where('servers.1.name', 'web-1')->where('servers.1.pool', ['site' => $this->site->id, 'primary' => true]));
    }

    public function test_one_servers_page_carries_its_tab_and_what_has_been_run_on_it(): void
    {
        $this->server('web-1');
        DB::table('operations')->insert(['organization_id' => 'org-1', 'kind' => 'deploy', 'node_id' => 'web-1.org-1', 'title' => 'Deploy shop', 'trigger' => 'console', 'actor' => 'matt@example.com',
            'status' => 'succeeded', 'stages' => '[]', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/servers/web-1.org-1?tab=commands')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Servers/Show')
            ->where('tab', 'commands')
            ->where('node.name', 'web-1')->where('node.ip', '203.0.113.9')
            ->where('commands.operations.0.title', 'Deploy shop')
            ->where('consoles.enabled', false));
        $this->get('/servers/web-1.org-1?tab=nonsense')->assertInertia(fn (Assert $page) => $page->where('tab', 'overview'));
    }

    public function test_a_server_of_another_team_or_that_does_not_exist_is_a_404(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-9.org-2', 'organization_id' => 'org-2', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/servers/web-9.org-2')->assertNotFound();
        $this->get('/servers/nope.org-1')->assertNotFound();
    }

    public function test_disconnecting_returns_to_the_list_since_the_servers_page_is_gone(): void
    {
        DB::table('sites')->delete();
        $this->server('web-2');
        DB::table('resources')->where('slug', 'web-2')->update(['state' => 'deleted']);

        $this->post('/servers/web-2.org-1/remove-stale')->assertRedirect('/servers');
    }

    public function test_a_pool_page_has_its_settings_servers_and_the_history_the_scaler_wrote(): void
    {
        $id = $this->pool();
        $audit = new AuditLog;
        $audit->record('org-1', Actor::system('autoscaler'), 'site.autoscaled', resourceType: 'site', resourceId: 'web-1.org-1/shop',
            metadata: ['action' => 'up', 'reason' => 'CPU 71% over 60% for 5 minutes', 'servers_before' => 1]);
        $audit->record('org-1', Actor::system('autoscaler'), 'site.autoscaler_paused', resourceType: 'site', resourceId: 'web-1.org-1/shop', metadata: ['because' => '3 servers in a row could not join']);

        $this->get("/servers/pool/{$id}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Servers/Pool')
            ->where('pool.name', 'shop-pool')->where('pool.managed', true)
            ->where('pool.min', 1)->where('pool.max', 3)->where('pool.target', 60)
            ->where('pool.schedule.0.min', 2)
            ->where('pool.members.0.primary', true)->where('pool.members.1.name', 'shop-pool-1')
            ->where('pool.costNow', 24)->where('pool.costMax', 48)
            ->where('pool.history.0.title', 'Stopped adding servers')->where('pool.history.0.kind', 'bad')
            ->where('pool.history.1.title', 'Added a server')->where('pool.history.1.why', 'CPU 71% over 60% for 5 minutes'));
    }

    public function test_an_application_without_a_pool_has_no_pool_page(): void
    {
        $this->get("/servers/pool/{$this->site->id}")->assertNotFound();
    }
}
