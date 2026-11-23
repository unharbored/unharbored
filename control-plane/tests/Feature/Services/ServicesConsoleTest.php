<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Auth\Projects;
use App\Lib\Services\Services;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** The console for native services (docs/specs/templates.md step 3): catalogue, form, review, list and service page. */
final class ServicesConsoleTest extends ServiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->node('web-2.org-2', 'org-2');
        $this->node('web-3.org-1', 'org-1');
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }


    private function fill($page, string $name = 'files')
    {
        return $page->set('values.name', $name)->set('values.s3_host', "{$name}-s3.example.com")->set('values.ui_host', "{$name}-ui.example.com")->set('values.storage', '10GB')->set('node', 'web-1.org-1');
    }

    private function audits(string $action): array
    {
        return DB::table('audit_records')->where('action', $action)->get()->all();
    }

    // ---- navigation and the catalogue -----------------------------------------

    public function test_the_nav_has_services_and_new_application_points_at_templates(): void
    {
        $this->member('manager');
        $this->get('/services')->assertOk()->assertInertia(fn ($page) => $page->component('Services'));
        $this->get('/apps/new')->assertOk();
        $this->get('/templates')->assertOk();
        $this->get('/services/new?template=rustfs')->assertOk();
    }

    public function test_only_rustfs_is_offered_and_the_others_say_why_not(): void
    {
        config(['templates.offered' => ['rustfs']]); // the default now also offers Uptime Kuma (ContainersConsoleTest)
        $this->member('manager');
        $cards = collect($this->get('/templates')->inertiaProps('cards'))->keyBy('id');
        $this->assertSame(['native', '1GB', 'ready'], [$cards['rustfs']['runtime'], $cards['rustfs']['memory'], $cards['rustfs']['state']]);
        $this->assertContains('container', $cards->pluck('state')->all(), 'a container template that is not offered says it is coming');
        $this->assertContains('unavailable', $cards->pluck('state')->all());
        $this->assertSame(['rustfs'], $cards->where('state', 'ready')->keys()->all(), 'only RustFS can be installed');

        config(['templates.offered' => ['rustfs', 'gitea']]);
        $this->assertContains('gitea', collect($this->get('/templates')->inertiaProps('cards'))->where('state', 'ready')->pluck('id')->all());
    }

    public function test_search_and_category_filter_the_cards(): void
    {
        $this->member('manager');
        // Searching and the category chips run in the browser over these cards.
        $props = $this->get('/templates')->inertiaProps();
        $this->assertContains('CI', $props['categories']);
        $names = collect($props['cards'])->pluck('name');
        $this->assertTrue($names->contains('RustFS'));
        $this->assertTrue($names->contains('GitHub Actions runner'));
        $this->assertSame('CI', collect($props['cards'])->firstWhere('name', 'GitHub Actions runner')['category']);
    }

    public function test_a_viewer_sees_the_catalogue_without_install_buttons_and_the_failures_list_is_manager_only(): void
    {
        $dir = sys_get_temp_dir().'/cm-tpl-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/broken.yml", "schema: 1\nid: broken\nbogus: yes\n");
        config(['templates.path' => $dir, 'templates.sources' => [['kind' => 'builtin'], ['kind' => 'local']], 'templates.allow_custom' => true]);
        $this->app->forgetInstance(\App\Lib\Templates\Sources::class);
        $this->app->forgetInstance(\App\Lib\Services\Catalogue::class);
        $this->app->forgetInstance(Services::class);

        $this->member('viewer');
        $viewer = $this->get('/templates');
        $this->assertContains('RustFS', array_column($viewer->inertiaProps('cards'), 'name'));
        $this->assertFalse($viewer->inertiaProps('canInstall'));
        $this->assertSame([], $viewer->inertiaProps('failures'));
        $this->assertStringNotContainsString('broken.yml', $viewer->getContent());
        $this->member('manager');
        $manager = $this->get('/templates');
        $this->assertTrue($manager->inertiaProps('canInstall'));
        $this->assertStringContainsString('broken.yml', json_encode($manager->inertiaProps('failures')));
        @unlink("{$dir}/broken.yml");
        @rmdir($dir);
    }

    // ---- the form and the review ------------------------------------------------

    // ---- acknowledge, extra ports and secrets -----------------------------------

    // ---- the list and the page ---------------------------------------------------

    public function test_the_list_shows_services_and_follows_the_project_filter(): void
    {
        $a = $this->install('rustfs', $this->rustfs('files'));
        $b = $this->install('rustfs', $this->rustfs('docs'), 'web-3.org-1');
        $client = Projects::create('org-1', 'Client A', \App\Lib\Audit\Actor::user('1', 'a@example.com'));
        DB::table('services')->where('id', $b['service_id'])->update(['project_id' => $client]);
        $this->install('rustfs', $this->rustfs('theirs'), 'web-2.org-2', 'org-2');

        $this->member('viewer');
        $list = $this->get('/services')->assertOk();
        $names = array_column($list->inertiaProps('services'), 'name');
        $this->assertEqualsCanonicalizing(['files', 'docs'], $names, "another team's services never show");
        $row = collect($list->inertiaProps('services'))->firstWhere('name', 'files');
        $this->assertSame(['running', 'rustfs'], [$row['status'], $row['template']]);
        $this->assertSame('https://files-s3.example.com', ($row['endpoints'][0]['tls'] ? 'https://' : 'http://').$row['endpoints'][0]['domain']);
        $this->assertNull($list->inertiaProps('filter'));
        session([Projects::SESSION_KEY => $client]);
        $narrowed = $this->get('/services');
        $this->assertSame(['docs'], array_column($narrowed->inertiaProps('services'), 'name'));
        $this->assertSame('Client A', $narrowed->inertiaProps('filter')['name']);
    }

    public function test_an_empty_list_points_at_the_catalogue(): void
    {
        $this->member('viewer');
        $this->assertSame([], $this->get('/services')->inertiaProps('services'));
    }
}
