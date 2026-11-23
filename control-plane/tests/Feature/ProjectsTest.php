<?php

namespace Tests\Feature;

use App\Lib\Api\ApiTokens;
use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
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
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/** Projects, steps 1 and 2 (docs/specs/projects.md): the data, the write paths, who may use them. */
class ProjectsTest extends TestCase
{
    use RefreshDatabase;

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

    private function make(string $name, string $node = 'web-1.org-1', string $org = 'org-1', ?int $project = null): object
    {
        return $this->app->make(Sites::class)->create($org, $node, $name, "{$name}.example.com", 'git@github.com:acme/shop.git', projectId: $project);
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }


    public function test_the_backfill_puts_every_site_and_preview_in_its_organisations_default_and_is_safe_to_rerun(): void
    {
        // The state before the migration: no projects, sites without one. org-9 has sites but no organisation row; org-2 has none.
        $migration = require database_path('migrations/2026_11_16_100000_create_projects.php');
        Schema::table('sites', fn ($t) => $t->unsignedBigInteger('project_id')->nullable()->change());
        DB::table('projects')->delete();
        $row = fn ($org, $name, $parent = null) => ['organization_id' => $org, 'node_id' => "n.{$org}", 'name' => $name, 'domain' => "{$name}.test", 'repository' => 'r', 'branch' => 'main', 'deploy_key_public' => 'k',
            'parent_site_id' => $parent, 'project_id' => null, 'created_at' => now(), 'updated_at' => now()];
        $shop = DB::table('sites')->insertGetId($row('org-1', 'shop'));
        $preview = DB::table('sites')->insertGetId($row('org-1', 'shop-pr', $shop));
        $ghost = DB::table('sites')->insertGetId($row('org-9', 'ghost'));

        $migration->up();
        $migration->up();

        $default = fn ($org) => DB::table('projects')->where('organization_id', $org)->where('slug', 'default')->value('id');
        $this->assertSame([1, 1, 1], array_map(fn ($o) => DB::table('projects')->where('organization_id', $o)->count(), ['org-1', 'org-2', 'org-9']));
        $this->assertSame('Default', DB::table('projects')->where('organization_id', 'org-2')->value('name'));
        $this->assertEquals([$default('org-1'), $default('org-1'), $default('org-9')], [DB::table('sites')->find($shop)->project_id, DB::table('sites')->find($preview)->project_id, DB::table('sites')->find($ghost)->project_id]);

        // Now NOT NULL: a site cannot be made without a project.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('sites')->insert(['organization_id' => 'org-1', 'name' => 'x', 'domain' => 'x', 'repository' => 'r', 'branch' => 'm', 'deploy_key_public' => 'k', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_new_organisation_gets_a_default_project_and_can_make_a_second(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post('/organization', ['new_organization' => 'Fresh'])->assertRedirect();
        $org = DB::table('organizations')->where('name', 'Fresh')->value('id');

        $this->assertSame(['Default'], DB::table('projects')->where('organization_id', $org)->pluck('name')->all());
        Projects::create($org, 'Client A', $this->actor());
        $this->assertSame(2, DB::table('projects')->where('organization_id', $org)->count());
    }

    public function test_names_and_slugs_are_unique_per_organisation_only(): void
    {
        Projects::create('org-1', 'Client A', $this->actor());
        Projects::create('org-2', 'Client A', $this->actor());
        $this->assertSame(2, DB::table('projects')->where('slug', 'client-a')->count());

        $this->expectExceptionMessage('already a project called');
        Projects::create('org-1', 'client a', $this->actor());
    }

    public function test_every_way_of_making_an_application_sets_its_project(): void
    {
        $shop = $this->make('shop');
        $default = Projects::ensureDefault('org-1');
        $this->assertSame($default, (int) $shop->project_id);

        $second = Projects::create('org-1', 'Client A', $this->actor());
        $this->assertSame($second, (int) $this->make('blog', project: $second)->project_id);

        // The console wizard: its plan carries the chosen project, else Default.
        $plan = fn (array $in) => $this->app->make(\App\Lib\Sites\NewApplications::class)->plan('org-1', $in + ['name' => 'wiz', 'repository' => 'acme/wiz', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1']]);
        $this->assertSame($default, $plan([])['project']);
        $this->assertSame($second, $plan(['project' => $second])['project']);

        // Another organisation's project is refused, not guessed.
        $theirs = Projects::create('org-2', 'Theirs', $this->actor());
        $this->expectExceptionMessage('project:');
        $this->make('stolen', project: $theirs);
    }

    public function test_a_preview_takes_its_parents_project_even_after_a_move(): void
    {
        $shop = $this->make('shop');
        $client = Projects::create('org-1', 'Client A', $this->actor());
        Projects::move('org-1', $shop->id, $client, $this->actor());

        $preview = $this->app->make(Previews::class)->create(DB::table('sites')->find($shop->id), 'feature/x', false);
        $this->assertSame($client, (int) $preview->project_id);
    }

    public function test_moving_takes_previews_along_audits_and_keeps_the_chain_intact(): void
    {
        $shop = $this->make('shop');
        DB::table('sites')->insert(['project_id' => $shop->project_id, 'organization_id' => 'org-1', 'node_id' => 'preview-1.org-1', 'name' => 'shop-pr', 'domain' => 'p', 'repository' => 'r', 'branch' => 'b', 'deploy_key_public' => 'k', 'parent_site_id' => $shop->id, 'created_at' => now(), 'updated_at' => now()]);
        $client = Projects::create('org-1', 'Client A', $this->actor());

        Projects::move('org-1', $shop->id, $client, $this->actor());

        $this->assertSame([$client, $client], DB::table('sites')->orderBy('id')->pluck('project_id')->map(fn ($i) => (int) $i)->all());
        $record = DB::table('audit_records')->where('action', 'site.moved')->first();
        $meta = json_decode($record->metadata, true);
        $this->assertSame(['Default', 'Client A', $client], [$meta['from'], $meta['to'], $meta['project_id']]);
        $this->assertTrue($this->app->make(AuditLog::class)->verify('org-1')->intact);
    }

    public function test_a_project_cannot_be_moved_into_from_another_organisation(): void
    {
        $shop = $this->make('shop');
        $theirs = Projects::create('org-2', 'Theirs', $this->actor());
        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        Projects::move('org-1', $shop->id, $theirs, $this->actor());
    }

    public function test_deleting_is_refused_while_it_has_applications_and_default_is_protected(): void
    {
        $client = Projects::create('org-1', 'Client A', $this->actor());
        $this->make('blog', project: $client);
        try {
            Projects::delete('org-1', $client, $this->actor());
            $this->fail('A project with applications must not be deleted.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('applications first', $e->getMessage());
        }

        $default = Projects::ensureDefault('org-1');
        foreach ([fn () => Projects::delete('org-1', $default, $this->actor()), fn () => Projects::rename('org-1', $default, 'Other', $this->actor())] as $try) {
            try {
                $try();
                $this->fail('Default is protected.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }

        Projects::move('org-1', DB::table('sites')->value('id'), $default, $this->actor());
        Projects::rename('org-1', $client, 'Client B', $this->actor());
        Projects::delete('org-1', $client, $this->actor());
        $this->assertSame(['project.created', 'site.moved', 'project.renamed', 'project.deleted'], DB::table('audit_records')->where('action', 'like', 'project.%')->orWhere('action', 'site.moved')->orderBy('sequence')->pluck('action')->all());
        $this->assertSame('Client A', Projects::listing('org-1')[0]['name'] === 'Default' ? 'Client A' : '');
        $this->assertTrue($this->app->make(AuditLog::class)->verify('org-1')->intact);
        $this->assertSame((string) $client, json_decode(DB::table('audit_records')->where('action', 'project.deleted')->value('metadata'), true)['project_id'] . '');
    }

    public function test_the_current_filter_is_validated_and_sites_is_narrowed(): void
    {
        $this->actingAs($this->member('owner'));
        $a = $this->make('a');
        $client = Projects::create('org-1', 'Client A', $this->actor());
        $this->make('b', project: $client);
        $theirs = Projects::create('org-2', 'Theirs', $this->actor());

        $this->assertNull(Projects::current());
        $this->assertSame(2, Projects::sites()->count());
        session([Projects::SESSION_KEY => $client]);
        $this->assertSame($client, Projects::current());
        $this->assertSame(['b'], Projects::sites()->pluck('name')->all());
        session([Projects::SESSION_KEY => $theirs]);
        $this->assertNull(Projects::current());
        $this->assertSame(2, Projects::sites()->count());
    }

    public function test_the_api_site_summary_names_the_project(): void
    {
        $this->make('shop');
        $token = $this->app->make(ApiTokens::class)->issue('org-1', 't', 'read', 'me')['token'];
        $this->withToken($token)->getJson('/api/v1/sites')->assertOk()->assertJsonPath('sites.0.project.name', 'Default')->assertJsonPath('sites.0.project.slug', 'default');
    }

    public function test_managers_manage_projects_and_developers_and_viewers_cannot(): void
    {
        $shop = $this->make('shop');
        $client = Projects::create('org-1', 'Client A', $this->actor());

        foreach (['developer', 'viewer'] as $role) {
            $this->actingAs($this->member($role));
            $this->post('/account/projects', ['name' => 'X'])->assertForbidden();
            $this->patch("/account/projects/{$client}", ['name' => 'Y'])->assertForbidden();
            $this->delete("/account/projects/{$client}")->assertForbidden();
            $this->post("/site/settings/{$shop->id}/project", ['project' => $client])->assertForbidden();
            $this->get('/account')->assertOk();
        }
        $this->assertSame((int) $shop->project_id, (int) DB::table('sites')->value('project_id'));

        $this->actingAs($this->member('manager'));
        $this->post('/account/projects', ['name' => 'Client B'])->assertSessionHasNoErrors();
        $this->post("/site/settings/{$shop->id}/project", ['project' => $client])->assertSessionHasNoErrors();
        $this->assertSame($client, (int) DB::table('sites')->value('project_id'));
        $this->delete("/account/projects/{$client}")->assertSessionHasErrors('delete');
        $this->post("/site/settings/{$shop->id}/project", ['project' => Projects::ensureDefault('org-2')])->assertNotFound();
    }

    public function test_the_wizard_asks_for_a_project_only_with_two_or_more(): void
    {
        $this->actingAs($this->member('owner'));
        $this->assertSame([], $this->get('/apps/new')->inertiaProps('options')['projects']);
        Projects::create('org-1', 'Client A', $this->actor());
        $this->assertCount(2, $this->get('/apps/new')->inertiaProps('options')['projects']);
    }

    public function test_the_pages_render(): void
    {
        $this->actingAs($this->member('owner'));
        $shop = $this->make('shop');
        Projects::create('org-1', 'Client A', $this->actor());

        $this->get('/account?tab=projects')->assertOk()->assertInertia(fn ($page) => $page->where('tab', 'projects'));
        $this->get("/site/settings?site={$shop->id}&tab=general")->assertOk();
        $this->get('/apps/new')->assertOk();
    }
}
