<?php

namespace Tests\Feature;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Projects;
use App\Lib\Sites\Previews;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** Projects, step 3 (docs/specs/projects.md): the switcher, the filtered Applications list, the Projects tab, the organisation-wide notes. */
class ProjectsConsoleTest extends TestCase
{
    use RefreshDatabase;

    private const WORDING = 'Projects group applications; they do not restrict access';

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.preview_domain' => 'preview.example.com']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'cloud.digitalocean.token', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['web-1.org-1' => ['org-1', null], 'preview-1.org-1' => ['org-1', 'preview'], 'web-2.org-2' => ['org-2', null]] as $node => [$org, $role]) {
            DB::table('nodes')->insert(['node_id' => $node, 'provider' => 'digitalocean', 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function actor(): Actor
    {
        return Actor::user('1', 'a@example.com');
    }

    private function make(string $name, ?int $project = null, string $node = 'web-1.org-1', string $org = 'org-1'): object
    {
        return $this->app->make(Sites::class)->create($org, $node, $name, "{$name}.example.com", 'git@github.com:acme/shop.git', projectId: $project);
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }


    /** Org-1 with an owner, application "a" in Default and "b" (plus a preview) in Client A. */
    private function two(): array
    {
        $this->actingAs($this->member('owner'));
        $this->make('a');
        $client = Projects::create('org-1', 'Client A', $this->actor());
        $b = $this->make('b', $client);
        $preview = app(Previews::class)->create(DB::table('sites')->find($b->id), 'feature/x', false);

        return [$client, $b, $preview];
    }

    public function test_the_switcher_shows_only_with_two_or_more_projects_and_carries_the_wording(): void
    {
        $this->actingAs($this->member('owner'));
        // The switcher is in the Vue layout: it shows when the shell lists two or more projects, and carries the wording itself.
        $this->assertCount(1, $this->get('/alerts')->assertOk()->inertiaProps('shell.projects'));

        Projects::create('org-1', 'Client A', $this->actor());
        $projects = $this->get('/alerts')->assertOk()->inertiaProps('shell.projects');
        $this->assertSame(['Default', 'Client A'], array_column($projects, 'name'));
        $this->assertStringContainsString(self::WORDING, file_get_contents(resource_path('js/Layouts/Console.vue')));
    }

    public function test_the_filter_narrows_the_list_and_previews_follow_their_parent(): void
    {
        [$client, $b, $preview] = $this->two();
        $this->assertSame((int) $client, (int) $preview->project_id);

        session([Projects::SESSION_KEY => $client]);
        $rows = $this->get('/sites')->inertiaProps('sites');
        $this->assertContains((int) $preview->id, array_column($rows, 'id'));
        $this->assertNotContains('a', array_column($rows, 'name'));
        $this->assertSame('Client A', $this->get('/sites')->inertiaProps('filter.name'));


        $this->post('/project/switch', ['project' => ''])->assertRedirect();
        $this->assertNull(session(Projects::SESSION_KEY));
        $this->assertCount(3, $this->get('/sites')->inertiaProps('sites'));
        $this->assertSame([], $this->get('/sites')->inertiaProps('filter'));
    }

    public function test_the_activity_list_follows_the_filter(): void
    {
        [$client, $b] = $this->two();
        $a = DB::table('sites')->where('name', 'a')->first();
        foreach ([$a, $b] as $s) {
            DB::table('operations')->insert(['organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'site_id' => $s->id, 'kind' => 'deploy', 'trigger' => 'console', 'actor' => 'a@example.com', 'stages' => '[]', 'title' => "Deploy {$s->name}", 'status' => 'succeeded', 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->assertCount(2, $this->get('/sites')->inertiaProps('activity'));
        session([Projects::SESSION_KEY => $client]);
        $this->assertSame(['Deploy b'], array_column($this->get('/sites')->inertiaProps('activity'), 'title'));
    }

    public function test_the_query_string_sets_the_filter_and_an_unknown_slug_is_ignored(): void
    {
        [$client] = $this->two();

        $this->get('/sites?project=client-a')->assertOk();
        $this->assertSame($client, session(Projects::SESSION_KEY));
        $this->get('/sites?project=nope')->assertOk();
        $this->assertSame($client, session(Projects::SESSION_KEY));
        $this->get('/sites?project=')->assertOk();
        $this->assertNull(session(Projects::SESSION_KEY));
        $this->get('/sites?project=default')->assertOk();
        $this->assertSame(Projects::ensureDefault('org-1'), session(Projects::SESSION_KEY));
    }

    public function test_a_slug_of_another_organisation_is_ignored(): void
    {
        $this->actingAs($this->member('owner'));
        Projects::create('org-2', 'Theirs', $this->actor());
        $this->get('/sites?project=theirs')->assertOk();
        $this->assertNull(session(Projects::SESSION_KEY));
    }

    public function test_switching_organisation_clears_the_project(): void
    {
        [$client] = $this->two();
        $user = $this->member('owner', 'org-2');
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user)->withSession([Projects::SESSION_KEY => $client]);
        $this->post('/organization/switch', ['organization' => 'org-2'])->assertRedirect();
        $this->assertNull(session(Projects::SESSION_KEY));
    }

    public function test_the_projects_tab_flows_and_shows_errors_inline(): void
    {
        $this->actingAs($this->member('owner'));
        $this->make('a');
        $this->get('/account?tab=projects')->assertOk()->assertInertia(fn ($page) => $page->component('Account')->where('tab', 'projects'));

        $this->post('/account/projects', ['name' => 'Client A'])->assertSessionHasNoErrors();
        $this->post('/account/projects', ['name' => 'client a'])->assertSessionHasErrors('newName');
        $this->post('/account/projects', ['name' => ''])->assertSessionHasErrors('newName');
        $id = (int) DB::table('projects')->where('slug', 'client-a')->value('id');
        $this->patch("/account/projects/{$id}", ['name' => 'Client B'])->assertSessionHasNoErrors();
        $listing = collect($this->get('/account?tab=projects')->inertiaProps('projects'));
        $this->assertSame(1, $listing->firstWhere('slug', 'default')['applications']);
        $this->assertSame('Client B', $listing->firstWhere('id', $id)['name']);
        $default = Projects::ensureDefault('org-1');
        $this->patch("/account/projects/{$default}", ['name' => 'X'])->assertSessionHasErrors('editName');
        $this->delete("/account/projects/{$default}")->assertSessionHasErrors('delete');
        $this->delete("/account/projects/{$id}")->assertSessionHasNoErrors();
        $this->assertFalse(DB::table('projects')->where('id', $id)->exists());
    }

    public function test_the_projects_tab_gate_is_create(): void
    {
        $this->actingAs($this->member('developer'));
        $this->post('/account/projects', ['name' => 'X'])->assertForbidden();
        $this->get('/account?tab=projects')->assertOk();
    }

    public function test_infrastructure_pages_say_they_are_organisation_wide(): void
    {
        $this->actingAs($this->member('owner'));
        $this->get('/servers')->assertOk()->assertInertia(fn ($page) => $page->component('Servers/Index'));
        $this->assertStringContainsString('not filtered by project', (string) file_get_contents(resource_path('js/Pages/Servers/Index.vue')));
        $this->get('/databases')->assertOk()->assertInertia(fn ($page) => $page->component('Databases'));
    }

    public function test_server_cards_list_applications_through_the_one_door_and_ignore_the_switcher(): void
    {
        [$client, $b, $preview] = $this->two();
        session([Projects::SESSION_KEY => $client]);
        $this->assertSame(['a', $b->name, $preview->name], Projects::visibleSites()->orderBy('name')->pluck('name')->all());
        $this->assertSame([$b->name, $preview->name], Projects::sites()->orderBy('name')->pluck('name')->all());
    }
}
