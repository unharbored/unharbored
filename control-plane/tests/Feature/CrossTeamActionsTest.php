<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Roles;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A person with a role in one team must not reach another team's servers, droplets, archives,
 * operations or previews by changing an id in a request, and the guards the page shows
 * (typing the application's name) hold on the server too.
 */
final class CrossTeamActionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        foreach (['org-1' => 'Mine', 'org-2' => 'Theirs'] as $id => $name) {
            DB::table('organizations')->insert(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => Roles::MANAGER, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        DB::table('nodes')->insert(['node_id' => 'web-9.org-2', 'organization_id' => 'org-2', 'agent_version' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $name): object
    {
        return (new Sites($this->app->make(Vault::class), new AuditLog))->create('org-1', 'web-1.org-1', $name, "{$name}.example.com", "git@github.com:acme/{$name}.git");
    }

    public function test_another_teams_server_cannot_be_hardened_disconnected_or_cleared(): void
    {
        $this->post('/servers/web-9.org-2/harden', ['on' => 1])->assertNotFound();
        $this->post('/servers/web-9.org-2/disconnect')->assertNotFound();
        $this->post('/servers/web-9.org-2/remove-stale')->assertNotFound();

        $this->assertSame(0, DB::table('operations')->count());
        $this->assertTrue(DB::table('nodes')->where('node_id', 'web-9.org-2')->exists());
    }

    public function test_an_id_that_is_not_this_teams_droplet_or_archive_is_not_found(): void
    {
        $this->post('/servers/droplets/999/destroy')->assertNotFound();
        $this->post('/servers/archives/999/delete')->assertNotFound();
    }

    public function test_another_teams_operation_cannot_be_retried(): void
    {
        $this->post('/operation/retry', ['operation' => 999])->assertNotFound();
    }

    public function test_a_preview_cannot_be_deleted_through_another_applications_url(): void
    {
        $a = (int) $this->site('a')->id;
        $b = $this->site('b');
        DB::table('sites')->insert(['organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'name' => 'b-pr-1', 'domain' => 'pr1.example.com', 'repository' => $b->repository, 'branch' => 'feature', 'parent_site_id' => $b->id, 'project_id' => $b->project_id, 'pr_number' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $preview = (int) DB::table('sites')->where('name', 'b-pr-1')->value('id');

        $this->post("/site/settings/{$a}/previews/{$preview}/delete")->assertNotFound();
        $this->assertSame(0, DB::table('operations')->count());
    }

    public function test_a_secret_from_a_failed_form_is_not_kept_in_the_session(): void
    {
        $this->post('/servers/connect', ['host' => '', 'private_key' => 'PRIVATE-KEY-TEXT', 'passphrase' => 'hunter2']);

        $this->assertNull(session()->getOldInput('private_key'));
        $this->assertNull(session()->getOldInput('passphrase'));
    }

    public function test_someone_in_no_team_gets_the_plain_page_and_the_real_message(): void
    {
        $this->be(User::factory()->create()); // actingAs() would make them an Owner of org-1

        $this->get('/sites')->assertForbidden()->assertInertia(fn ($page) => $page->component('Errors/Forbidden')->where('shell', null)->where('message', 'You do not belong to a team. Ask an Owner to invite you.'));
    }
}
