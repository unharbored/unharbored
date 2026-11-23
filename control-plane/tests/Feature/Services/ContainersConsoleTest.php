<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Api\ApiTokens;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The console, API and commands for containers (docs/specs/templates.md step 4). */
final class ContainersConsoleTest extends ServiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function prepared(string $node = 'web-1.org-1'): void
    {
        DB::table('nodes')->where('node_id', $node)->update(['containers_ready_at' => now(), 'docker_version' => '29.0.1']);
    }

    /** The props of the Containers page for a server. */
    private function containers(string $node = 'web-1.org-1'): array
    {
        return $this->get('/server/containers?node='.$node)->assertOk()->viewData('page')['props'];
    }


    // ---- the catalogue and the form ----------------------------------------------

    /** The catalogue cards the Templates page returns, by template id. */
    private function cards(): \Illuminate\Support\Collection
    {
        return collect($this->get('/templates')->assertOk()->inertiaProps('cards'))->keyBy('id');
    }

    public function test_uptime_kuma_is_offered_only_when_the_team_has_a_prepared_server(): void
    {
        $this->member('manager');
        $cards = $this->cards();
        $this->assertSame('container', $cards['uptime-kuma']['runtime']);
        $this->assertSame('needs-containers', $cards['uptime-kuma']['state']);
        $this->assertSame(1, $cards->where('state', 'ready')->count(), 'only RustFS can be installed yet');
        $this->assertTrue($this->get('/templates')->inertiaProps('canInstall'));

        $this->prepared();
        $this->assertSame('ready', $this->cards()['uptime-kuma']['state']);

        // Another team's prepared server does not count.
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['containers_ready_at' => null]);
        $this->node('web-2.org-2', 'org-2');
        $this->prepared('web-2.org-2');
        $this->assertSame('needs-containers', $this->cards()['uptime-kuma']['state']);

        // A viewer is not told to prepare anything, and sees no install button.
        $this->member('viewer');
        $this->assertFalse($this->get('/templates')->inertiaProps('canInstall'), 'a viewer is told to ask a Manager, and gets no install or prepare button');
    }

    // ---- the Containers view -----------------------------------------------------

    public function test_an_unprepared_server_shows_the_prepare_button_to_managers_only(): void
    {
        $this->member('manager');
        $page = $this->containers();
        $this->assertFalse($page['view']['status']['prepared']);
        $this->assertTrue($page['can']['infrastructure']);
        $this->assertFalse($page['view']['small']);
        $this->member('developer');
        $this->assertFalse($this->containers()['can']['infrastructure'], 'a developer is told preparing needs the Manager role');
        $this->member('viewer');
        $viewer = $this->containers();
        $this->assertFalse($viewer['view']['status']['prepared']);
        $this->assertFalse($viewer['can']['infrastructure']);
    }

    public function test_a_small_server_shows_the_warning_and_preparing_needs_the_confirmation(): void
    {
        $this->node('small.org-1', 'org-1');
        DB::table('node_metrics')->where('node_id', 'small.org-1')->update(['mem_total' => 983 * 1024 ** 2]);
        $this->member('manager');
        $view = $this->containers('small.org-1')['view'];
        $this->assertTrue($view['small']);
        $this->assertSame('1 GB', $view['memory']);

        $this->postJson('/server/containers/prepare', ['node' => 'small.org-1', 'acknowledgeSmall' => false])->assertStatus(422)->assertJsonValidationErrors('prepare');
        $this->post('/server/containers/prepare', ['node' => 'small.org-1', 'acknowledgeSmall' => true])->assertRedirectContains('/operation?operation=');
        $this->assertSame(1, DB::table('operations')->where('kind', 'server_containers')->count());
    }

    public function test_a_prepared_server_lists_containers_with_service_links_and_disk_use(): void
    {
        $this->prepared();
        $this->member('manager');
        $made = $this->install('uptime-kuma', ['name' => 'kuma', 'host' => 'kuma.example.com']);
        $this->assertSame('succeeded', $made['status']);
        $this->execOutput = fn () => implode("\n", ['##version', '29.0.1', '##ps',
            json_encode(['Names' => 'cm-svc-kuma', 'State' => 'running', 'Status' => 'Up 2 minutes', 'Image' => 'louislam/uptime-kuma@sha256:abc']),
            '##stats', json_encode(['Name' => 'cm-svc-kuma', 'MemUsage' => '90MiB / 512MiB']),
            '##df', json_encode(['Type' => 'Images', 'TotalCount' => '2', 'Size' => '600MB', 'Reclaimable' => '100MB (16%)']),
            json_encode(['Type' => 'Local Volumes', 'TotalCount' => '1', 'Size' => '10MB', 'Reclaimable' => '0B']), '']);

        $view = $this->containers()['view'];
        $this->assertSame('29.0.1', $view['status']['docker_version']);
        $this->assertNull($view['status']['read_ago'], 'not read yet');
        $this->assertSame([], $view['rows']);
        $this->assertCount(0, $this->execs, 'a page load runs no command');

        $this->post('/server/containers/refresh', ['node' => 'web-1.org-1'])->assertRedirect();
        $view = $this->containers()['view'];
        $row = $view['rows'][0];
        $this->assertSame(['cm-svc-kuma', '90MiB / 512MiB', $made['service_id'], ['127.0.0.1:23000']], [$row['name'], $row['memory'], $row['service']['id'], $row['service']['addresses']]);
        $this->assertSame(['Images', 'Local Volumes'], array_column($view['status']['df'], 'type'));
        $this->assertSame('100MB (16%)', $view['status']['df'][0]['reclaimable']);
    }

    public function test_the_new_server_methods_check_the_team_and_the_state(): void
    {
        $this->member('manager');
        $this->postJson('/server/containers/cleanup', ['node' => 'web-1.org-1'])->assertStatus(422)->assertJsonValidationErrors('cleanup');
        $this->postJson('/server/containers/refresh', ['node' => 'web-1.org-1'])->assertStatus(422)->assertJsonValidationErrors('refresh');
        $this->node('web-2.org-2', 'org-2');
        $this->prepared('web-2.org-2');
        $this->postJson('/server/containers/cleanup', ['node' => 'web-2.org-2'])->assertStatus(422);
        $this->postJson('/server/containers/prepare', ['node' => 'web-2.org-2', 'acknowledgeSmall' => true])->assertStatus(422);
        $this->assertTrue($this->containers('web-2.org-2')['view']['missing'], 'another team\'s server is not shown');
        $this->assertSame(0, DB::table('operations')->count());

        $this->prepared();
        $this->post('/server/containers/cleanup', ['node' => 'web-1.org-1'])->assertRedirectContains('/operation?operation=');
        $this->assertSame(1, DB::table('operations')->where('kind', 'container_cleanup')->count());
    }

    public function test_the_servers_page_links_to_containers_and_the_firewall_says_why_ports_stay_private(): void
    {
        $this->member('manager');
        $this->assertSame('web-1.org-1', $this->get('/servers/web-1.org-1')->assertOk()->viewData('page')['props']['node']['id']);
        $this->assertStringContainsString('/server/containers?node=', (string) file_get_contents(resource_path('js/Components/Servers/SoftwareDrawer.vue')));
        $this->assertStringContainsString('every container port we publish is bound to 127.0.0.1', (string) file_get_contents(resource_path('js/Components/Servers/FirewallDrawer.vue')));
    }

    // ---- API and commands ----------------------------------------------------------

    public function test_the_api_shows_runtime_and_the_listen_address_and_no_secret(): void
    {
        $this->prepared();
        $made = $this->install('uptime-kuma', ['name' => 'kuma', 'host' => 'kuma.example.com']);
        $token = $this->app->make(ApiTokens::class)->issue('org-1', 't', 'read', 'me')['token'];
        $s = $this->getJson('/api/v1/services/kuma', ['Authorization' => "Bearer {$token}"])->assertOk()->json();
        $this->assertSame('container', $s['runtime']);
        $this->assertSame([['domain' => 'kuma.example.com', 'tls' => true, 'listen' => '127.0.0.1:23000', 'container_port' => 3001]], $s['endpoints']);
        $this->assertStringNotContainsString('env', json_encode($s));
        $this->assertSame('running', $s['status']);
        $this->assertSame($made['service_id'], $s['id']);
    }

    public function test_the_commands_prepare_clean_up_and_install_a_container_template(): void
    {
        $this->node('small.org-1', 'org-1');
        DB::table('node_metrics')->where('node_id', 'small.org-1')->update(['mem_total' => 983 * 1024 ** 2]);
        $this->artisan('server:containers small.org-1')->expectsOutputToContain('roughly 100 MB')->assertFailed();
        $this->artisan('server:containers small.org-1 --acknowledge-small')->assertSuccessful();
        $this->assertNotNull(DB::table('nodes')->where('node_id', 'small.org-1')->value('containers_ready_at'));

        $this->artisan('server:containers web-1.org-1')->assertSuccessful();
        $this->artisan('service:install web-1.org-1 uptime-kuma kuma --input=host=kuma.example.com')->assertSuccessful();
        $this->assertSame('running', $this->service('kuma')->status);
        $this->artisan('server:containers-cleanup web-1.org-1')->assertSuccessful();
        $docker = array_values(array_filter($this->execs, fn ($e) => $e['name'] === 'docker'));
        $this->assertSame([['image', 'prune', '-f'], ['builder', 'prune', '-f']], array_column($docker, 'args'));

        $dev = $this->member('developer');
        $this->artisan("server:containers web-1.org-1 --as={$dev->email}")->expectsOutputToContain('infrastructure permission')->assertFailed();
        $this->artisan('server:containers-cleanup nope.org-1')->expectsOutputToContain('no server')->assertFailed();
    }
}
