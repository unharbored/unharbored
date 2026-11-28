<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Providers;
use App\Lib\Resources\ResourceManager;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use stdClass;
use Tests\TestCase;

/** A server made for an application, and added to it as a web or a worker server, in one operation. */
final class WorkerServerCreateTest extends TestCase
{
    use RefreshDatabase;

    private stdClass $site;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $this->site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:acme/demo.git');
        DB::table('sites')->where('id', $this->site->id)->update(['provider' => 'hetzner', 'database_cluster_id' => 1]);
        $this->app->make(Sites::class)->setEnv($this->site, "DB_CONNECTION=mysql\n", 'a managed database is attached');
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function connectHetzner(): void
    {
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'hetzner', 'vault_key' => 'cloud.hetzner.token', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @return array<string, string> */
    private function choice(string $role = 'worker'): array
    {
        $catalog = app(Providers::class)->get('hetzner')->catalog();

        return ['role' => $role, 'region' => $catalog->defaultRegion, 'size' => array_key_first($catalog->sizes)];
    }

    private function make(array $over = [])
    {
        return $this->post("/site/settings/{$this->site->id}/servers/new", $over + $this->choice());
    }

    public function test_the_page_offers_a_new_server_only_at_the_applications_connected_provider_to_someone_who_may(): void
    {
        $this->member('owner');
        $page = fn () => $this->get("/site/settings?site={$this->site->id}&tab=servers");

        $page()->assertInertia(fn (Assert $p) => $p->where('data.newServer', null));

        $this->connectHetzner();
        $page()->assertInertia(fn (Assert $p) => $p->where('data.newServer.label', 'Hetzner')->has('data.newServer.regions')->has('data.newServer.sizes'));

        DB::table('sites')->where('id', $this->site->id)->update(['provider' => 'digitalocean']);
        $page()->assertInertia(fn (Assert $p) => $p->where('data.newServer', null));
    }

    public function test_a_server_is_queued_for_the_application_with_its_role_and_a_free_name(): void
    {
        $this->member('owner');
        $this->connectHetzner();

        $this->make()->assertSessionHasNoErrors();

        $op = DB::table('operations')->where('kind', 'site_server_create')->sole();
        $this->assertSame(['server', 'add'], array_column(json_decode($op->stages, true), 'name'));
        $this->assertSame((int) $this->site->id, (int) $op->site_id);
        $meta = json_decode($op->meta, true);
        $this->assertSame(['demo-worker', 'hetzner', 'worker'], [$meta['server']['slug'], $meta['server']['provider'], $meta['role']]);
        $this->assertNull(DB::table('nodes')->where('node_id', 'demo-worker.org-1')->first(), 'the server is made by the operation, not by the request');
    }

    public function test_it_is_refused_without_a_shared_database_a_provider_a_real_size_or_a_known_role(): void
    {
        $this->member('owner');
        $refused = function (string $why, array $over = []) {
            $this->make($over)->assertSessionHasErrors('server');
            $this->assertSame(0, DB::table('operations')->where('kind', 'site_server_create')->count(), $why);
        };

        $refused('no provider connected');
        $this->connectHetzner();
        $refused('not a size it offers', ['size' => 'no-such-size']);
        $refused('not a region it offers', ['region' => 'nowhere']);
        $refused('not a role', ['role' => 'primary']);
        DB::table('sites')->where('id', $this->site->id)->update(['database_cluster_id' => null]);
        $refused('SQLite cannot be shared');
    }

    public function test_the_operation_makes_the_server_then_adds_it_with_the_role_and_finishes_with_the_add(): void
    {
        $this->member('owner');
        $this->connectHetzner();
        $this->make(['role' => 'worker']);
        $id = (int) DB::table('operations')->where('kind', 'site_server_create')->value('id');
        $runner = $this->app->make(OperationRunner::class);

        $this->assertSame('waiting', $runner->run($id));
        $provision = DB::table('operations')->where('kind', 'provision')->sole();
        $this->assertSame('demo-worker.org-1', $provision->node_id);
        $this->assertSame('waiting', $runner->run($id), 'the server is still being made');
        $this->assertSame(0, DB::table('operations')->where('kind', 'server_add')->count());

        DB::table('operations')->where('id', $provision->id)->update(['status' => 'succeeded']);
        DB::table('nodes')->insert(['node_id' => 'demo-worker.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame('waiting', $runner->run($id));
        $add = DB::table('operations')->where('kind', 'server_add')->sole();
        $this->assertSame((int) $this->site->id, (int) $add->site_id);
        $meta = json_decode($add->meta, true);
        $this->assertSame(['demo-worker.org-1', 'worker'], [$meta['node'], $meta['role']]);

        DB::table('operations')->where('id', $add->id)->update(['status' => 'succeeded']);
        $this->assertSame('succeeded', $runner->run($id));
    }

    public function test_a_server_that_could_not_be_added_is_kept_and_the_operation_says_so(): void
    {
        $this->member('owner');
        $this->connectHetzner();
        $this->make();
        $id = (int) DB::table('operations')->where('kind', 'site_server_create')->value('id');
        $runner = $this->app->make(OperationRunner::class);
        $runner->run($id);
        DB::table('operations')->where('kind', 'provision')->update(['status' => 'succeeded']);
        $runner->run($id);
        DB::table('operations')->where('kind', 'server_add')->update(['status' => 'failed', 'error' => 'deploy failed on it']);

        $this->assertSame('failed', $runner->run($id));
        $this->assertStringContainsString('still there', (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertStringContainsString('deploy failed on it', (string) DB::table('operations')->where('id', $id)->value('error'));
    }

    private function workerServer(bool $madeHere): void
    {
        DB::table('nodes')->insert(['node_id' => 'demo-worker.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('site_nodes')->insert(['site_id' => $this->site->id, 'node_id' => 'demo-worker.org-1', 'role' => 'worker', 'created_at' => now(), 'updated_at' => now()]);
        if ($madeHere) {
            $server = $this->app->make(ResourceManager::class)->declare('org-1', 'server', 'demo-worker', [], provider: 'hetzner');
            $this->app->make(ResourceManager::class)->recordExternalId($server, '4242');
        }
    }

    public function test_a_worker_server_is_destroyed_with_its_removal_only_when_this_platform_made_it(): void
    {
        $this->member('owner');
        $this->workerServer(false);
        $remove = fn (array $over = []) => $this->post("/site/settings/{$this->site->id}/servers/remove", ['node' => 'demo-worker.org-1'] + $over);

        $remove(['destroy' => true])->assertSessionHasErrors('server');
        $this->assertSame(0, DB::table('operations')->where('kind', 'server_remove')->count(), 'A server the user brought is never destroyed.');
        $remove()->assertRedirect()->assertSessionHasNoErrors();
        $this->assertFalse(json_decode((string) DB::table('operations')->where('kind', 'server_remove')->sole()->meta, true)['destroy']);

        DB::table('operations')->delete();
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'provider' => 'hetzner', 'slug' => 'demo-worker', 'external_id' => '4242', 'state' => 'active',
            'desired_state' => 'active', 'idempotency_key' => 'k', 'version' => 1, 'state_entered_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $remove(['destroy' => true])->assertRedirect()->assertSessionHasNoErrors();
        $op = DB::table('operations')->where('kind', 'server_remove')->sole();
        $this->assertTrue(json_decode((string) $op->meta, true)['destroy']);
        $this->assertStringContainsString('and destroy it', $op->title);
    }

    public function test_removing_with_destroy_takes_it_off_the_application_then_queues_its_destruction_and_keeps_it_if_another_site_uses_it(): void
    {
        $this->member('owner');
        $this->workerServer(true);
        DB::table('sites')->where('id', $this->site->id)->update(['database_cluster_id' => null]);
        Http::fake(['*' => Http::response(['outcome_known' => true, 'status' => 'succeeded', 'detail' => ['run' => ['steps' => []]]])]);
        $runner = $this->app->make(OperationRunner::class);

        $id = $runner->queueServerRemove($this->site->id, 'demo-worker.org-1', 'console', 'a@b.c', true);
        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $this->assertSame(0, DB::table('site_nodes')->where('node_id', 'demo-worker.org-1')->count(), 'off the application first');
        $destroy = DB::table('operations')->where('kind', 'server_destroy')->sole();
        $this->assertSame('demo-worker.org-1', $destroy->node_id);

        // Another application on the same server: removed from this one, kept.
        DB::table('operations')->delete();
        $other = $this->app->make(Sites::class)->create('org-1', 'demo-worker.org-1', 'other', 'other.example.com', 'git@github.com:acme/other.git');
        DB::table('site_nodes')->insert(['site_id' => $this->site->id, 'node_id' => 'demo-worker.org-1', 'role' => 'worker', 'created_at' => now(), 'updated_at' => now()]);
        $id = $runner->queueServerRemove($this->site->id, 'demo-worker.org-1', 'console', 'a@b.c', true);
        $this->assertSame('succeeded', $runner->run($id));
        $this->assertSame(0, DB::table('operations')->where('kind', 'server_destroy')->count());
        $this->assertStringContainsString('kept, not destroyed', (string) DB::table('operations')->where('id', $id)->value('log'));
    }
}
