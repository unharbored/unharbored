<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class ViewerPagesTest extends TestCase
{
    use RefreshDatabase;

    private function viewer(): User
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_pages_a_viewer_may_not_use_answer_403_not_500(): void
    {
        $this->actingAs($this->viewer());
        foreach (['/apps/new', '/audit', '/services/new', '/apps/container', '/tokens', '/servers', '/alerts', '/logs', '/databases'] as $path) {
            $status = $this->get($path)->status();
            $this->assertContains($status, [200, 302, 403], "{$path} answered {$status} to a Viewer");
        }
    }

    public function test_the_navigation_names_each_link_and_there_is_a_way_past_it(): void
    {
        // The navigation is the Vue layout: each tab is named, carries its shortcut, and the page has a skip link.
        $layout = file_get_contents(resource_path('js/Layouts/Console.vue'));
        $this->assertStringContainsString(':aria-label="label(tab)"', $layout);
        $this->assertStringContainsString(':aria-keyshortcuts=', $layout);
        $this->assertStringContainsString('href="#main"', $layout);
        $this->assertStringContainsString('id="main"', $layout);
        $this->actingAs($this->viewer())->get('/alerts')->assertOk();
    }

    public function test_the_navigation_does_not_offer_a_viewer_pages_they_cannot_open(): void
    {
        // The layout lists a tab only when its permission flag is true; a Viewer has neither.
        $can = $this->actingAs($this->viewer())->get('/alerts')->assertOk()->inertiaProps('can');
        $this->assertFalse($can['audit.read']);
        $this->assertFalse($can['create']);
        $this->assertTrue($can['tokens.read']);
    }

    public function test_a_viewer_sees_servers_and_settings_read_only_and_no_add_server_forms(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $site = DB::table('sites')->insertGetId(['organization_id' => 'org-1', 'name' => 'shop', 'domain' => 'shop.example.test', 'node_id' => 'web-1.org-1',
            'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'deploy_key_public' => 'x', 'project_id' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->viewer());

        $props = $this->get('/servers')->assertOk()->viewData('page')['props'];
        $this->assertFalse($props['can']['infrastructure']);
        $this->assertCount(1, $props['servers']);
        $this->get('/servers/web-1.org-1')->assertOk();
        // The page names the limit, switches the cards' controls off and hides the add-server forms.
        $index = (string) file_get_contents(resource_path('js/Pages/Servers/Index.vue'));
        $this->assertStringContainsString('You can look at the servers but not change them', $index);
        $this->assertMatchesRegularExpression('/<template v-if="can\.infrastructure">\s*<Drawer[^>]*title="Add your own server"/', $index);
        $this->assertMatchesRegularExpression('/<fieldset class="ro[^"]*" :disabled="readonly">/', (string) file_get_contents(resource_path('js/Components/Servers/Settings.vue')));

        $this->get("/site/settings?site={$site}")->assertOk()->assertInertia(fn ($page) => $page->where('can.edit', false));
        $settings = (string) file_get_contents(resource_path('js/Pages/Site/Settings.vue'));
        $this->assertStringContainsString('You can read these settings but not change them', $settings);
        $this->assertMatchesRegularExpression('/<fieldset class="[^"]*" :disabled="!canEdit">/', $settings);
    }

    public function test_a_developer_can_reveal_the_sudo_password_but_not_change_servers(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'developer', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        $props = $this->get('/servers')->assertOk()->viewData('page')['props'];
        $this->assertSame([false, true], [$props['can']['infrastructure'], $props['can']['console']]);
        // The Reveal button is offered outside the disabled fieldset; changing a server is refused.
        $this->postJson('/servers/web-1.org-1/sudo/reveal')->assertOk()->assertJsonStructure(['password']);
        $this->post('/servers/web-1.org-1/sudo/rotate')->assertForbidden();
        $this->post('/servers/web-1.org-1/harden', ['on' => true])->assertForbidden();
        $this->post('/servers', ['name' => 'web-2'])->assertForbidden();
    }

    public function test_an_empty_account_is_shown_the_steps_to_its_first_application_and_a_viewer_is_not(): void
    {
        $owner = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $owner->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($owner);
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->where('canCreate', true)->where('start.hasSites', false)->where('start.hasServer', false)
            ->where('start.hasGitHub', false)->where('start.hasAlerts', false)->etc());

        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->where('start.hasServer', true)->etc());

        // The page shows the panel only to someone who can create an application.
        $this->actingAs($this->viewer());
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->where('canCreate', false)->etc());
    }
}
