<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\SiteArchived;
use App\Lib\Sites\Sites;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Archiving a server or a database cluster: Unharbored stops managing it and touches nothing on the machine or at the provider. */
final class ServerArchivingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        Queue::fake();
        Http::fake();
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['web-1', 'web-2', 'db-main'] as $slug) {
            DB::table('nodes')->insert(['node_id' => "{$slug}.org-1", 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        }
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
    }

    private function site(string $name, string $node = 'web-1.org-1'): object
    {
        $site = $this->app->make(Sites::class)->create('org-1', $node, $name, "{$name}.example.com", 'git@github.com:acme/shop.git');

        return DB::table('sites')->where('id', $site->id)->first();
    }

    /** A cluster that is a server of its own (db-main), or a managed one with no machine of ours. */
    private function cluster(string $slug = 'main', bool $selfRun = true): int
    {
        return DB::table('resources')->insertGetId(['organization_id' => 'org-1', 'type' => 'database_cluster', 'slug' => $slug, 'state' => 'active', 'desired_state' => 'active',
            'provider' => $selfRun ? 'custom' : 'digitalocean', 'attributes' => json_encode(['engine' => 'pg'] + ($selfRun ? ['mode' => 'self_run', 'own_server' => true, 'server' => 'db-main'] : [])),
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function attach(object $site, int $cluster): void
    {
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster, 'database_mode' => 'cluster']);
    }

    private function archivedNames(string $table, string $column): array
    {
        return DB::table($table)->whereNotNull('archived_at')->orderBy($column)->pluck($column)->all();
    }

    public function test_archiving_a_server_moves_it_and_its_applications_to_archived_and_sends_nothing(): void
    {
        $this->site('shop');
        $this->site('blog');
        $this->site('elsewhere', 'web-2.org-1');

        $this->post('/servers/web-1.org-1/archive')->assertRedirect();
        Http::assertNothingSent();

        $this->assertSame(['blog', 'shop'], $this->archivedNames('sites', 'name'));
        $this->assertSame(0, DB::table('operations')->count());
        $this->get('/servers')->assertInertia(fn (Assert $page) => $page
            ->where('servers', fn ($rows) => collect($rows)->pluck('name')->sort()->values()->all() === ['db-main', 'web-2'])
            ->where('archived', fn ($rows) => collect($rows)->pluck('name')->all() === ['web-1']));
        $this->assertDatabaseHas('audit_records', ['action' => 'server.archived', 'resource_id' => 'web-1.org-1']);
    }

    public function test_restoring_a_server_restores_what_was_archived_with_it_and_not_what_was_archived_before(): void
    {
        $this->site('shop');
        $old = $this->site('old');
        $this->post("/site/settings/{$old->id}/archive");
        $this->travel(5)->seconds();
        $this->post('/servers/web-1.org-1/archive');

        $this->post('/servers/web-1.org-1/restore')->assertRedirect();
        Http::assertNothingSent();

        $this->assertSame(['old'], $this->archivedNames('sites', 'name'));
        $this->assertNull(DB::table('nodes')->where('node_id', 'web-1.org-1')->value('archived_at'));
        $this->assertDatabaseHas('audit_records', ['action' => 'server.restored', 'resource_id' => 'web-1.org-1']);
    }

    public function test_an_application_cannot_be_restored_while_its_server_is_archived(): void
    {
        $shop = $this->site('shop');
        $this->post('/servers/web-1.org-1/archive');

        $this->post("/site/settings/{$shop->id}/restore")->assertSessionHasErrors('archived');

        $this->assertSame(['shop'], $this->archivedNames('sites', 'name'));
    }

    public function test_a_server_that_others_depend_on_is_refused_with_who(): void
    {
        $cluster = $this->cluster();
        $this->attach($this->site('billing', 'web-2.org-1'), $cluster);

        $this->post('/servers/db-main.org-1/archive')->assertSessionHasErrors(['archive' => 'db-main is the database server main, used by billing on other servers. Archive or detach them first.']);

        $this->assertNull(DB::table('nodes')->where('node_id', 'db-main.org-1')->value('archived_at'));
    }

    public function test_a_server_that_is_also_a_secondary_of_another_application_is_refused(): void
    {
        $shop = $this->site('shop', 'web-2.org-1');
        DB::table('site_nodes')->insert(['site_id' => $shop->id, 'node_id' => 'web-1.org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->post('/servers/web-1.org-1/archive')->assertSessionHasErrors('archive');

        $this->assertNull(DB::table('nodes')->where('node_id', 'web-1.org-1')->value('archived_at'));
    }

    public function test_a_cluster_is_refused_while_a_live_application_uses_it_and_archived_ones_may_stay(): void
    {
        $cluster = $this->cluster('shared', selfRun: false);
        $live = $this->site('live');
        $gone = $this->site('gone');
        $this->attach($live, $cluster);
        $this->attach($gone, $cluster);
        $this->post("/site/settings/{$gone->id}/archive");

        $this->post("/databases/{$cluster}/archive")->assertSessionHasErrors(['archive' => 'shared is still used by live. Archive or detach them first.']);

        $this->post("/site/settings/{$live->id}/archive");
        $this->post("/databases/{$cluster}/archive")->assertRedirect()->assertSessionHasNoErrors();
        Http::assertNothingSent();
        $this->get('/databases')->assertInertia(fn (Assert $page) => $page
            ->where('clusters', [])
            ->where('archived', fn ($rows) => collect($rows)->pluck('slug')->all() === ['shared']));
        $this->assertDatabaseHas('audit_records', ['action' => 'database_cluster.archived', 'resource_id' => 'shared']);

        $this->post("/databases/{$cluster}/restore")->assertRedirect();
        $this->get('/databases')->assertInertia(fn (Assert $page) => $page
            ->where('clusters', fn ($rows) => collect($rows)->pluck('slug')->all() === ['shared'])
            ->where('archived', []));
        $this->assertDatabaseHas('audit_records', ['action' => 'database_cluster.restored', 'resource_id' => 'shared']);
    }

    public function test_a_database_server_and_its_cluster_are_archived_and_restored_together(): void
    {
        $cluster = $this->cluster();

        $this->post("/databases/{$cluster}/archive")->assertRedirect();
        $this->assertNotNull(DB::table('nodes')->where('node_id', 'db-main.org-1')->value('archived_at'));
        $this->post("/databases/{$cluster}/restore");
        $this->assertNull(DB::table('nodes')->where('node_id', 'db-main.org-1')->value('archived_at'));
        $this->assertNull(DB::table('resources')->where('id', $cluster)->value('archived_at'));

        $this->post('/servers/db-main.org-1/archive')->assertRedirect();
        $this->assertNotNull(DB::table('resources')->where('id', $cluster)->value('archived_at'));
        $this->post('/servers/db-main.org-1/restore');
        $this->assertNull(DB::table('resources')->where('id', $cluster)->value('archived_at'));
        Http::assertNothingSent();
    }

    public function test_every_write_to_an_archived_server_or_cluster_is_refused(): void
    {
        $cluster = $this->cluster();
        $this->post("/databases/{$cluster}/archive");
        $this->post('/servers/web-1.org-1/archive');

        $this->post('/servers/web-1.org-1/repair')->assertSessionHasErrors('archived');
        $this->post('/servers/web-1.org-1/upgrade-agent')->assertSessionHasErrors('archived');
        $this->post("/databases/{$cluster}/certificate")->assertSessionHasErrors('archived');
        $this->assertSame(0, DB::table('operations')->count());

        try {
            $this->app->make(OperationRunner::class)->queueStack('web-1.org-1', 'api', 'token');
            $this->fail('An operation was queued on an archived server.');
        } catch (SiteArchived) {
        }
        try {
            $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'new', 'new.example.com', 'git@github.com:acme/shop.git');
            $this->fail('An application was created on an archived server.');
        } catch (SiteArchived) {
        }
        $this->artisan('node:upgrade-agent', ['node' => 'web-1.org-1'])->assertFailed();
        Http::assertNothingSent();

        $this->delete("/databases/{$cluster}")->assertSessionHasNoErrors();
        $this->assertSame(1, DB::table('operations')->where('kind', 'database_destroy')->count(), 'Destroying an archived cluster is let through.');
    }

    public function test_an_archived_server_is_still_let_through_to_delete(): void
    {
        $shop = $this->site('shop');
        $this->post('/servers/web-1.org-1/archive');

        $this->post('/servers/web-1.org-1/delete', ['confirm' => 'web-1', 'choices' => ["app:{$shop->id}" => 'erase']])->assertSessionHasNoErrors();

        $this->assertSame(1, DB::table('operations')->where('kind', 'server_delete')->count());
    }
}
