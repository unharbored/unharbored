<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The Servers page: what each card says, what blocks removing a server, and creating one. */
final class ServersPageTest extends TestCase
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
        $this->site = $sites->markTls($sites->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:acme/demo.git'));
    }

    /** @return array<string, mixed> */
    private function props(string $query = ''): array
    {
        return $this->get('/servers'.$query)->assertOk()->viewData('page')['props'];
    }

    /** @return array<string, mixed> */
    private function showProps(string $node = 'web-1.org-1', string $query = ''): array
    {
        return $this->get("/servers/{$node}".$query)->assertOk()->viewData('page')['props'];
    }

    private function node(string $node = 'web-1.org-1', string $query = ''): array
    {
        return $this->showProps($node, $query)['node'];
    }

    private function connectWeb1(): void
    {
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't',
            tap(new Factory, fn ($http) => $http->fake(['*' => $http->response(['nodes' => [['node_id' => 'web-1.org-1', 'connected_at' => now()->toIso8601String()]]])])),
            new AuditLog));
    }

    private function connectDigitalOcean(): void
    {
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'cloud.digitalocean.token', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_server_charts_cover_the_chosen_range(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'agent_version' => 'x', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([2 * 1440, 600, 30, 2, 1] as $i => $minutesAgo) {
            DB::table('node_metrics')->insert(['organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'collected_at' => now()->subMinutes($minutesAgo),
                'cpu' => [88, 70, 40, 20, 12][$i], 'load_1' => 0, 'mem_total' => 1000, 'mem_available' => 500, 'disk_total' => 100, 'disk_free' => 20, 'uptime' => 1]);
        }

        $hour = $this->showProps();
        $this->assertSame([12, 50, 80, 40], [$hour['node']['metrics']['cpu'], $hour['node']['metrics']['mem'], $hour['node']['metrics']['disk'], $hour['node']['metrics']['peak']]);
        $this->assertSame('1h', $hour['chart']);

        $week = $this->showProps('web-1.org-1', '?chart=7d');
        $this->assertSame(['7d', 88], [$week['chart'], $week['node']['metrics']['peak']]);
        $this->assertSame('1h', $this->showProps('web-1.org-1', '?chart=nonsense')['chart'], 'an unknown range is the hour');
    }

    public function test_the_page_lists_nodes_and_refuses_a_duplicate_server(): void
    {
        Queue::fake();
        $this->connectDigitalOcean();
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'agent_version' => 'old', 'created_at' => now(), 'updated_at' => now()]);

        $node = $this->node();
        $this->assertSame(['web-1', [['id' => $this->site->id, 'name' => 'demo']]], [$node['name'], $node['sites']]);

        $catalog = $this->props()['catalogs']['digitalocean'];
        $this->postJson('/servers', ['name' => 'web-1', 'region' => $catalog['region'], 'size' => $catalog['size']])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(0, DB::table('operations')->where('kind', 'provision')->count());
    }

    public function test_the_machine_under_a_database_cluster_cannot_be_removed_on_its_own(): void
    {
        DB::table('nodes')->insert(['node_id' => 'db-main.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['server', 'db-main', ['database_cluster' => 'main']], ['database_cluster', 'main', ['engine' => 'pg', 'mode' => 'self_run']]] as [$type, $slug, $attributes]) {
            DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => $type, 'slug' => $slug, 'external_id' => '1', 'state' => 'active', 'desired_state' => 'active',
                'attributes' => json_encode($attributes), 'created_at' => now(), 'updated_at' => now()]);
        }

        $node = $this->node('db-main.org-1');

        $this->assertContains('It is the database server of cluster main. Destroy the cluster on the Databases page instead.', $node['blockers']);
        $this->assertContains('It is the database server of cluster main. Destroy the cluster on the Databases page instead.', $node['destroyBlockers']);

        // Once the cluster is gone, the machine is an ordinary server again.
        DB::table('resources')->where('type', 'database_cluster')->update(['state' => 'deleted']);
        $this->assertNotContains('It is the database server of cluster main. Destroy the cluster on the Databases page instead.', $this->node('db-main.org-1')['blockers']);
    }

    public function test_the_page_explains_why_a_droplet_cannot_be_destroyed(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $node = $this->node();
        $this->assertContains('Hosts demo. Delete those applications first, or use Delete server, which lets you decide for each.', $node['blockers']);
        $this->assertContains('Not a Droplet this platform created.', $node['destroyBlockers']);
    }

    public function test_server_buttons_say_what_they_do_and_a_disabled_one_says_why(): void
    {
        // Offline, hosting an application: Repair and Upgrade need the agent; Disconnect and Destroy are held by the application.
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'agent_version' => 'old', 'created_at' => now(), 'updated_at' => now()]);

        $offline = $this->node();
        $this->assertFalse($offline['connected']);
        $this->assertStringStartsWith('Unavailable: Not connected. The agent is offline', $offline['tips']['repair']);
        $this->assertStringContainsString('Unavailable: Hosts demo. Delete those applications first, or use Delete server, which lets you decide for each.', $offline['tips']['disconnect']);
        $this->assertSame(['8.4'], $offline['php']);
        $this->assertContains('8.3', $this->showProps()['phpVersions']);

        // Online and idle, hosting nothing: every button says what it does.
        DB::table('sites')->delete();
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['agent_version' => null]);
        $this->connectWeb1();

        $online = $this->node();
        $this->assertTrue($online['connected']);
        $this->assertStringStartsWith('Re-apply the base server software (nginx, PHP-FPM, Composer, git, supervisor)', $online['tips']['repair']);
        $this->assertStringStartsWith('Stop managing this server', $online['tips']['disconnect']);
    }

    public function test_the_page_offers_to_remove_a_stale_entry(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-9.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-9', 'external_id' => '999',
            'state' => 'deleted', 'desired_state' => 'active', 'attributes' => json_encode(['pool' => 7]),
            'created_at' => now(), 'updated_at' => now()]);

        $node = $this->node('web-9.org-1');
        $this->assertTrue($node['stale']);
        $this->assertNotContains('Managed by an autoscale pool. Stop autoscaling that application instead.', $node['blockers']);
    }

    public function test_the_page_lists_retained_data_and_disconnected_droplets(): void
    {
        DB::table('site_archives')->insert([
            'organization_id' => 'org-1', 'original_site_id' => 1, 'name' => 'demo', 'domain' => 'demo.example.com',
            'node_id' => 'web-1.org-1', 'servers' => json_encode(['web-1.org-1']), 'directory' => '/home/deploy/demo',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'old-1', 'external_id' => '777',
            'state' => 'active', 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $props = $this->props();
        $this->assertSame(['/home/deploy/demo', 'web-1'], [$props['archives'][0]['directory'], $props['archives'][0]['servers']]);
        $this->assertSame('old-1', $props['disconnected'][0]['name']);
    }

    public function test_a_new_server_is_queued_as_a_provision_operation(): void
    {
        $this->connectDigitalOcean();
        Queue::fake();

        $this->post('/servers', ['name' => 'web-2', 'region' => 'sfo3', 'size' => $this->props()['catalogs']['digitalocean']['size']])
            ->assertRedirect('/operation?operation='.DB::table('operations')->where('kind', 'provision')->value('id'));

        $op = DB::table('operations')->where('kind', 'provision')->sole();
        $this->assertSame('web-2.org-1', $op->node_id);
        $this->assertSame(['preflight', 'create', 'enroll', 'stack'], array_column(json_decode($op->stages, true), 'name'));
    }

    public function test_an_unlisted_region_is_refused(): void
    {
        $this->connectDigitalOcean();
        Queue::fake();

        $this->postJson('/servers', ['name' => 'web-2', 'region' => 'mars1', 'size' => $this->props()['catalogs']['digitalocean']['size']])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->assertSame(0, DB::table('operations')->where('kind', 'provision')->count());
    }

    public function test_the_page_lists_sites_a_server_serves_as_a_secondary(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-2.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([], $this->node('web-2.org-1')['sites']);

        DB::table('site_nodes')->insert(['site_id' => $this->site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame(['demo'], array_column($this->node('web-2.org-1')['sites'], 'name'));
    }

    public function test_disconnecting_a_server_with_an_application_is_refused_with_its_reason(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->postJson('/servers/web-1.org-1/disconnect')->assertStatus(422)->assertJsonValidationErrors('remove');
        $this->assertTrue(DB::table('nodes')->where('node_id', 'web-1.org-1')->exists());
    }

    public function test_an_install_command_for_your_own_server_is_shown_once_on_the_page(): void
    {
        $this->post('/servers/own', ['byoName' => 'hetzner-1'])->assertRedirect()->assertSessionHas('byo');

        $byo = session('byo');
        $this->assertStringContainsString('/install/', $byo['command'] ?? '');
        $this->assertArrayHasKey('operation', $byo);
    }
}
