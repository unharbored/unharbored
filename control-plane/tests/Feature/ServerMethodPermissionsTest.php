<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every server method is a public endpoint, so each one names the permission it
 * needs (docs/specs/organisations-and-roles.md), and the role table decides.
 */
class ServerMethodPermissionsTest extends TestCase
{
    use RefreshDatabase;

    /** Every action on the application pages that changes something, with a body that would otherwise be valid. */
    private const APPLICATION_ACTIONS = [
        '/site/deploy' => [], '/site/rollback' => [], '/site/certificate/check' => [], '/site/certificate/renew' => [], '/site/certificate/auto-renew' => [],
        '/site/certificate/upload' => ['certChain' => 'chain', 'certKey' => 'key'], '/site/certificate/revert' => [],
        '/site/container/settings' => ['port' => 80], '/site/container/registry' => ['registryUser' => 'u', 'registryToken' => 't'], '/site/container/check' => [], '/site/container/update' => ['digest' => 'x'],
        '/sites' => ['server' => 'a', 'name' => 'b', 'domain' => 'c', 'repository' => 'd'], '/operation/retry' => ['operation' => 1],
    ];

    private function member(string $role): User
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }


    public function test_a_viewer_can_read_but_not_act_or_see_secrets(): void
    {
        $this->actingAs($this->member('viewer'));

        $this->get('/sites')->assertOk();
        // The Settings actions have their own routes: see Sites/SiteSettingsPermissionsTest.
        foreach (['env' => ['envText' => 'A=1'], 'env/reveal' => [], 'env/injected' => [], 'clones' => ['name' => 'copy_a', 'sql' => 'UPDATE a SET b = 1;'], 'clones/sql' => ['sql' => 'UPDATE a SET b = 1;']] as $path => $data) {
            $this->postJson("/site/settings/1/{$path}", $data)->assertForbidden();
        }
    }

    public function test_a_developer_deploys_and_edits_but_cannot_create_or_delete(): void
    {
        $this->actingAs($this->member('developer'));

        $this->get('/audit')->assertOk();
        $this->post('/site/settings/1/delete', ['confirmName' => 'x'])->assertForbidden();
        $this->post('/site/settings/1/project', ['project' => 1])->assertForbidden();
    }

    public function test_a_viewer_cannot_use_any_application_page_action(): void
    {
        $this->actingAs($this->member('viewer'));

        foreach (self::APPLICATION_ACTIONS as $url => $data) {
            $this->post($url, ['site' => 1] + $data)->assertForbidden();
        }
        $this->get('/site?site=1')->assertOk();
    }

    public function test_a_developer_cannot_create_a_site_or_change_the_certificate_or_container_infrastructure(): void
    {
        $this->actingAs($this->member('developer'));

        foreach (['/sites' => ['server' => 'a', 'name' => 'b', 'domain' => 'c', 'repository' => 'd'], '/site/certificate/upload' => ['certChain' => 'chain', 'certKey' => 'key'], '/site/certificate/revert' => []] as $url => $data) {
            $this->post($url, ['site' => 1] + $data)->assertForbidden();
        }
    }

    public function test_a_developer_may_toggle_certificate_auto_renew(): void
    {
        $this->actingAs($this->member('developer'));

        $this->assertNotSame(403, $this->post('/site/certificate/auto-renew', ['site' => 1])->status());
    }

    public function test_a_developer_may_deploy_roll_back_and_check_or_renew_a_certificate(): void
    {
        $this->actingAs($this->member('developer'));

        foreach (['/site/deploy', '/site/rollback', '/site/certificate/check', '/site/certificate/renew'] as $url) {
            $this->assertNotSame(403, $this->post($url, ['site' => 1])->status(), "{$url} for a Developer");
        }
    }

    public function test_a_developer_may_drive_and_reveal_a_service_and_a_viewer_may_list_them(): void
    {
        $this->actingAs($this->member('viewer'));
        $this->get('/services')->assertOk();
        $this->get('/templates')->assertOk();
        $this->get('/service?service=1')->assertOk();

        $this->actingAs($this->member('developer'));
        $this->assertNotSame(403, $this->postJson('/service/1/act', ['action' => 'restart'])->status());
        $this->assertNotSame(403, $this->postJson('/service/1/reveal', ['key' => 'access_key'])->status());
        // A developer deploys and edits a container application (settings, registry login, image update) but cannot create one.
        foreach (['/site/container/settings', '/site/container/registry', '/site/container/check', '/site/container/update'] as $url) {
            $this->assertNotSame(403, $this->post($url, ['site' => 1])->status(), "{$url} for a Developer");
        }
    }

    public function test_the_audit_log_databases_and_tokens_pages_refuse_the_roles_that_may_not_act(): void
    {
        // A Viewer reads tokens and databases but not the audit log, and creates, revokes or provisions nothing.
        $this->actingAs($this->member('viewer'));
        $this->get('/tokens')->assertOk();
        $this->get('/databases')->assertOk();
        $this->get('/audit')->assertForbidden();
        $this->post('/tokens', ['name' => 'n', 'scope' => 'read'])->assertForbidden();
        $this->delete('/tokens/1')->assertForbidden();
        $this->post('/databases', ['name' => 'main', 'engine' => 'pg', 'region' => 'nyc3', 'size' => 'db-s-1vcpu-1gb'])->assertForbidden();
        $this->delete('/databases/1')->assertForbidden();

        // A Developer reads the audit log and makes tokens, but provisioning databases is infrastructure.
        $this->actingAs($this->member('developer'));
        $this->get('/audit')->assertOk();
        $this->post('/databases', ['name' => 'main', 'engine' => 'pg', 'region' => 'nyc3', 'size' => 'db-s-1vcpu-1gb'])->assertForbidden();
        $this->delete('/databases/1')->assertForbidden();
    }

    public function test_a_person_with_no_organisation_sees_nothing(): void
    {
        $this->actingAs(User::factory()->create());
        // actingAs gives a test user org-1 by default; take that away.
        DB::table('memberships')->delete();

        $this->get('/sites')->assertForbidden();
        $this->get('/account')->assertForbidden();
        $this->post('/site/deploy', ['site' => 1])->assertForbidden();
    }

    public function test_a_viewer_cannot_open_a_console_but_a_developer_can_reach_it(): void
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->member('viewer'));
        $this->postJson('/console/open', ['site' => 1, 'node' => 'x.org-1', 'as_user' => 'deploy'])->assertForbidden();

        $this->actingAs($this->member('developer'));
        $this->assertNotSame(403, $this->postJson('/console/open', ['site' => 1, 'node' => 'x.org-1', 'as_user' => 'deploy'])->status());
    }
}
