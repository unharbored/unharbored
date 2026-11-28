<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

/** A server an application runs on is a web server or a worker server; each runs the processes meant for its role. */
final class WorkerRolesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
    }

    private function sites(): Sites
    {
        return $this->app->make(Sites::class);
    }

    private function site(): stdClass
    {
        return $this->sites()->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:acme/demo.git');
    }

    private function addServer(stdClass $site, string $node, string $role = 'web'): void
    {
        DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => $node, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
    }

    private function supervisorConf(stdClass $site, string $node): string
    {
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gateway.test', 'secret', new HttpFactory, new AuditLog), new AuditLog, new GitHubApp(new HttpFactory, $this->app->make(Vault::class), new AuditLog));
        $steps = array_column($deployer->afterSwitchRecipe(Sites::on($site, $node))['steps'], null, 'id');

        // A server with no processes has no file at all.
        return base64_decode($steps['supervisor-conf']['content_b64'] ?? '');
    }

    public function test_servers_are_web_unless_made_workers_and_the_primary_is_always_web(): void
    {
        $site = $this->site();
        $this->addServer($site, 'web-2.org-1');
        $this->addServer($site, 'work-1.org-1', 'worker');
        // A row for the primary saying worker cannot make it one.
        $this->addServer($site, 'web-1.org-1', 'worker');

        $this->assertSame(['web-1.org-1', 'web-2.org-1', 'work-1.org-1'], Sites::nodes($site));
        $this->assertSame(['work-1.org-1'], Sites::workerNodes($site));
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], Sites::webNodes($site));
        $this->assertSame('worker', Sites::on($site, 'work-1.org-1')->node_role);
        $this->assertSame('web', Sites::on($site, 'web-2.org-1')->node_role);
        $this->assertSame('web', Sites::on($site, 'web-1.org-1')->node_role);
    }

    public function test_an_application_on_one_server_is_unchanged(): void
    {
        $site = $this->site();

        $this->assertSame(['web-1.org-1'], Sites::webNodes($site));
        $this->assertSame([], Sites::workerNodes($site));
        $this->assertSame('web', Sites::on($site, 'web-1.org-1')->node_role);
    }

    public function test_a_worker_server_runs_the_processes_meant_for_it_and_none_that_are_web_only(): void
    {
        $site = $this->site();
        $this->addServer($site, 'work-1.org-1', 'worker');
        $site = $this->sites()->enableReverb($site);
        $site = $this->sites()->setDaemon($site, 'everywhere', 'php artisan a');
        $site = $this->sites()->setDaemon($site, 'web-only', 'php artisan b', extra: ['runs_on' => 'web']);
        $site = $this->sites()->setDaemon($site, 'worker-only', 'php artisan c', extra: ['runs_on' => 'worker']);

        $web = $this->supervisorConf($site, 'web-1.org-1');
        $worker = $this->supervisorConf($site, 'work-1.org-1');

        foreach (['cm-demo-worker', 'cm-demo-reverb', 'cm-demo-everywhere', 'cm-demo-web-only'] as $program) {
            $this->assertStringContainsString("[program:{$program}]", $web);
        }
        $this->assertStringNotContainsString('cm-demo-worker-only', $web);

        foreach (['cm-demo-worker', 'cm-demo-everywhere', 'cm-demo-worker-only'] as $program) {
            $this->assertStringContainsString("[program:{$program}]", $worker);
        }
        $this->assertStringNotContainsString('cm-demo-reverb', $worker, 'Reverb is behind the web server');
        $this->assertStringNotContainsString('cm-demo-web-only', $worker);
    }

    public function test_a_process_that_runs_on_something_else_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->sites()->setDaemon($this->site(), 'x', 'php artisan x', extra: ['runs_on' => 'primary']);
    }

    public function test_a_worker_server_gets_no_web_server_settings_but_every_other_server_does(): void
    {
        $site = $this->site();
        $this->addServer($site, 'work-1.org-1', 'worker');
        $ids = fn (string $node) => array_column($this->sites()->setupRecipe(Sites::on($site, $node))['steps'], 'id');

        foreach (['vhost', 'nginx-names'] as $id) {
            $this->assertContains($id, $ids('web-1.org-1'));
            $this->assertNotContains($id, $ids('work-1.org-1'));
        }
        foreach (['env', 'deploy-key'] as $id) {
            $this->assertContains($id, $ids('work-1.org-1'), 'It still needs the application and its variables.');
        }
    }

    public function test_the_built_in_queue_workers_can_be_placed_on_worker_servers(): void
    {
        $site = $this->site();
        $this->addServer($site, 'work-1.org-1', 'worker');
        $site = $this->sites()->setWorkersOn($site, 'worker');

        $this->assertStringNotContainsString('cm-demo-worker]', $this->supervisorConf($site, 'web-1.org-1'));
        $this->assertStringContainsString('[program:cm-demo-worker]', $this->supervisorConf($site, 'work-1.org-1'));

        $this->expectException(InvalidArgumentException::class);
        $this->sites()->setWorkersOn($site, 'primary');
    }

    public function test_the_console_adds_a_server_as_a_worker_and_refuses_an_unknown_role(): void
    {
        Queue::fake();
        $site = $this->site();
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'work-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => 1]);
        $this->sites()->setEnv($site, "DB_CONNECTION=mysql\n", 'a managed database is attached');
        $owner = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $owner->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $add = fn (string $role) => $this->actingAs($owner)->post("/site/settings/{$site->id}/servers/add", ['server' => 'work-1.org-1', 'role' => $role]);

        $add('primary')->assertSessionHasErrors('server');
        $this->assertSame(0, DB::table('operations')->where('kind', 'server_add')->count());

        $this->sites()->setEnv($site, "DB_CONNECTION=mysql\nCACHE_STORE=file\n", 'test');
        $add('worker')->assertSessionHasErrors('server');
        $this->assertSame(0, DB::table('operations')->where('kind', 'server_add')->count(), 'a cache on one server\'s disk cannot be shared');
        $this->sites()->setEnv($site, "DB_CONNECTION=mysql\nCACHE_STORE=database\n", 'test');

        $add('worker')->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('worker', json_decode((string) DB::table('operations')->where('kind', 'server_add')->sole()->meta, true)['role']);
    }

    public function test_adding_a_server_records_its_role_and_the_primary_cannot_be_a_worker(): void
    {
        $site = $this->site();
        Sites::addNode($site, 'work-1.org-1', 'worker');
        Sites::addNode($site, 'web-2.org-1');

        $this->assertSame(['work-1.org-1'], Sites::workerNodes($site));
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], Sites::webNodes($site));

        $this->expectException(InvalidArgumentException::class);
        Sites::addNode($site, 'web-1.org-1', 'worker');
    }

    public function test_the_console_shows_each_servers_role_and_where_processes_can_run(): void
    {
        $site = $this->site();
        Sites::addNode($site, 'work-1.org-1', 'worker');
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $owner = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $owner->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($owner);

        $this->get("/site/settings?site={$site->id}&tab=servers")->assertInertia(fn (Assert $page) => $page
            ->where('data.servers.0.role', 'web')->where('data.servers.1.role', 'worker'));
        $this->get("/site/settings?site={$site->id}&tab=processes")->assertInertia(fn (Assert $page) => $page
            ->where('data.serverCount', 2)->where('data.workerServers', 1)->where('data.workersOn', 'all'));
    }
}
