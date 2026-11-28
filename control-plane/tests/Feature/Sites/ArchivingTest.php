<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

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

/** Archiving an application: Unharbored stops managing it and touches nothing on its server. */
final class ArchivingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        Queue::fake();
        Http::fake();
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
    }

    private function site(string $name = 'shop', array $set = []): object
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', $name, "{$name}.example.com", 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(array_merge(['current_release' => 'r1'], $set));

        return DB::table('sites')->where('id', $site->id)->first();
    }

    public function test_archiving_moves_it_from_the_list_to_archived_and_sends_nothing_to_the_server(): void
    {
        $site = $this->site();
        $this->site('blog');

        $this->post("/site/settings/{$site->id}/archive")->assertRedirect();
        Http::assertNothingSent();

        $this->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('sites', fn ($sites) => collect($sites)->pluck('name')->all() === ['blog'])
            ->where('archived', fn ($archived) => collect($archived)->pluck('name')->all() === ['shop']));
        $this->assertSame(0, DB::table('operations')->count());
        $this->assertDatabaseHas('audit_records', ['action' => 'site.archived', 'resource_id' => 'web-1.org-1/shop']);
    }

    public function test_restoring_brings_it_back_to_the_list_and_audits_it(): void
    {
        $site = $this->site();
        $this->post("/site/settings/{$site->id}/archive");

        $this->post("/site/settings/{$site->id}/restore")->assertRedirect();
        Http::assertNothingSent();

        $this->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('sites', fn ($sites) => collect($sites)->pluck('name')->all() === ['shop'])
            ->where('archived', []));
        $this->assertDatabaseHas('audit_records', ['action' => 'site.restored', 'resource_id' => 'web-1.org-1/shop']);
    }

    public function test_the_settings_page_of_an_archived_application_says_it_is_archived(): void
    {
        $site = $this->site();
        $this->post("/site/settings/{$site->id}/archive");

        $this->get("/site/settings?site={$site->id}")->assertInertia(fn (Assert $page) => $page->where('site.archived', true));
    }

    public function test_an_archived_application_refuses_console_actions_with_a_message(): void
    {
        $site = $this->site();
        $this->post("/site/settings/{$site->id}/archive");

        $this->post("/site/settings/{$site->id}/deploy")->assertSessionHasErrors('archived');
        $this->post('/site/deploy', ['site' => $site->id])->assertSessionHasErrors('archived');
        $this->postJson("/site/settings/{$site->id}/env", ['envText' => 'A=1'])->assertStatus(409);
        $this->assertSame(0, DB::table('operations')->count());
    }

    public function test_the_operation_queue_refuses_an_archived_application_whoever_asks(): void
    {
        $site = $this->site();
        $this->post("/site/settings/{$site->id}/archive");

        $this->expectException(SiteArchived::class);
        $this->app->make(OperationRunner::class)->queueDeploy((int) $site->id, 'api', 'token');
    }

    public function test_a_push_to_an_archived_application_does_not_deploy_it(): void
    {
        $site = $this->site('shop', ['push_to_deploy' => true]);
        $secret = $this->app->make(Sites::class)->webhookSecret($site);
        $this->post("/site/settings/{$site->id}/archive");

        $this->postJson("/api/git/hook/{$site->id}/{$secret}", ['ref' => 'refs/heads/main'])->assertOk()->assertJsonPath('ignored', 'this application is archived');
        $this->assertSame(0, DB::table('operations')->count());
    }

    public function test_the_scheduled_commands_skip_an_archived_application(): void
    {
        $site = $this->site('shop', ['tls' => true, 'cert_source' => 'letsencrypt', 'backups' => true, 'push_to_deploy' => true, 'git_source' => 'https', 'git_seen_sha' => str_repeat('9', 40), 'repository' => 'https://git.example.com/team/app.git']);
        $this->post("/site/settings/{$site->id}/archive");

        $this->artisan('certificates:check')->assertSuccessful();
        $this->artisan('sites:backup')->assertSuccessful();
        $this->artisan('sites:poll-git')->assertSuccessful();

        $this->assertSame(0, DB::table('operations')->count());
        Http::assertNothingSent();
    }

    public function test_restoring_makes_the_scheduled_commands_pick_it_up_again(): void
    {
        $site = $this->site('shop', ['tls' => true, 'cert_source' => 'letsencrypt']);
        $this->post("/site/settings/{$site->id}/archive");
        $this->post("/site/settings/{$site->id}/restore");

        $this->artisan('certificates:check')->expectsOutputToContain('1 check(s) queued')->assertSuccessful();
    }

    public function test_an_archived_application_still_blocks_its_name_on_the_same_server(): void
    {
        $site = $this->site();
        $this->post("/site/settings/{$site->id}/archive");

        $this->post('/sites', ['server' => 'web-1.org-1', 'name' => 'shop', 'domain' => 'other.example.com', 'repository' => 'git@github.com:acme/shop.git'])
            ->assertSessionHasErrors(['newName' => 'web-1.org-1 already has a site called shop (archived: it still runs there).']);
    }
}
