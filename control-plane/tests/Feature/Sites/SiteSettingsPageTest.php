<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Sites\SettingsPage;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** An application's Settings page: every tab opens, and an unknown or foreign application is "No such site". */
final class SiteSettingsPageTest extends TestCase
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
        $this->site = (new Sites($this->app->make(Vault::class), new AuditLog))->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:acme/demo.git');
    }

    public function test_every_tab_opens_with_its_data(): void
    {
        foreach (SettingsPage::TABS as $tab) {
            $this->get("/site/settings?site={$this->site->id}&tab={$tab}")->assertOk()->assertInertia(fn (Assert $page) => $page
                ->component('Site/Settings')->where('missing', false)->where('tab', $tab)->where('site.name', 'demo')->where('site.domain', 'demo.example.com')->has('data'));
        }
    }

    public function test_the_first_tab_is_the_variables_and_an_unknown_tab_falls_back_to_it(): void
    {
        $this->get("/site/settings?site={$this->site->id}")->assertInertia(fn (Assert $page) => $page->where('tab', 'env'));
        $this->get("/site/settings?site={$this->site->id}&tab=nonsense")->assertInertia(fn (Assert $page) => $page->where('tab', 'env'));
    }

    public function test_an_unknown_application_is_no_such_site(): void
    {
        $this->get('/site/settings?site=9999')->assertOk()->assertInertia(fn (Assert $page) => $page->where('missing', true));
    }

    public function test_another_teams_application_is_no_such_site(): void
    {
        $id = DB::table('sites')->insertGetId(['project_id' => 1, 'organization_id' => 'org-2', 'node_id' => 'web-1.org-2', 'name' => 'theirs', 'domain' => 'theirs.example.com',
            'repository' => 'git@github.com:acme/theirs.git', 'branch' => 'main', 'deploy_key_public' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $page = $this->get("/site/settings?site={$id}");
        $page->assertInertia(fn (Assert $p) => $p->where('missing', true));
        $this->assertStringNotContainsString('theirs.example.com', $page->getContent());
        $this->post("/site/settings/{$id}/workers", ['workers' => 2])->assertNotFound();
    }

    public function test_the_header_names_the_application_and_the_pending_notice_follows_a_change(): void
    {
        $this->get("/site/settings?site={$this->site->id}")->assertInertia(fn (Assert $page) => $page
            ->where('shell.site.app.name', 'demo')->where('site.pending', false));

        $this->post("/site/settings/{$this->site->id}/workers", ['workers' => 2]);

        $this->get("/site/settings?site={$this->site->id}")->assertInertia(fn (Assert $page) => $page->where('site.pending', true));
    }

    public function test_a_deploy_from_the_pending_notice_queues_the_operation(): void
    {
        Queue::fake();

        $this->post("/site/settings/{$this->site->id}/deploy")->assertRedirect();

        $this->assertSame(1, DB::table('operations')->where('kind', 'deploy')->count());
    }
}
