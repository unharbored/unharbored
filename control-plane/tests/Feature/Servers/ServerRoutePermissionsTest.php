<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Every Servers action names the permission it needs (docs/specs/organisations-and-roles.md): changing
 * infrastructure is a Manager's, reading the sudo password a Developer's, looking at a Viewer's.
 */
final class ServerRoutePermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role): User
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insertOrIgnore(['node_id' => 'x.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    /** Actions that change a server: a Manager's. */
    private const INFRASTRUCTURE = [
        '/servers', '/servers/own',
        '/servers/droplets/1/destroy', '/servers/archives/1/delete',
        '/servers/x.org-1/disconnect', '/servers/x.org-1/remove-stale', '/servers/x.org-1/destroy', '/servers/x.org-1/repair', '/servers/x.org-1/upgrade-agent',
        '/servers/x.org-1/sudo/rotate', '/servers/x.org-1/harden', '/servers/x.org-1/firewall', '/servers/x.org-1/tailscale/join', '/servers/x.org-1/tailscale/leave', '/servers/x.org-1/keys/1',
        '/servers/x.org-1/php/8.4/settings', '/servers/x.org-1/php/8.4/install', '/servers/x.org-1/php/8.4/update', '/servers/x.org-1/services/pgsql',
        '/server/containers/prepare', '/server/containers/cleanup',
    ];

    public function test_a_viewer_can_look_but_not_act_or_read_the_sudo_password(): void
    {
        $this->actingAs($this->member('viewer'));

        $this->get('/servers')->assertOk();
        $this->get('/server/containers?node=x.org-1')->assertOk();
        $this->getJson('/servers/x.org-1/php/8.4')->assertOk();
        foreach ([...self::INFRASTRUCTURE, '/servers/x.org-1/sudo/reveal', '/server/containers/refresh'] as $url) {
            $this->postJson($url, ['node' => 'x.org-1'])->assertForbidden("{$url} should be refused to a Viewer");
        }
    }

    public function test_a_developer_reads_the_sudo_password_and_reads_containers_but_changes_nothing(): void
    {
        $this->actingAs($this->member('developer'));

        foreach (self::INFRASTRUCTURE as $url) {
            $this->postJson($url, ['node' => 'x.org-1'])->assertForbidden("{$url} should be refused to a Developer");
        }
        $this->postJson('/servers/x.org-1/sudo/reveal')->assertOk();
        $this->assertNotSame(403, $this->postJson('/server/containers/refresh', ['node' => 'x.org-1'])->status(), 'a developer may read what runs now');
    }

    public function test_a_manager_is_past_the_permission_and_met_by_validation_not_a_refusal(): void
    {
        $this->actingAs($this->member('manager'));

        $this->postJson('/servers', ['name' => 'Not A Name', 'region' => 'r', 'size' => 's'])->assertStatus(422)->assertJsonValidationErrors('name');
        $this->postJson('/servers/own', ['byoName' => 'Bad Name'])->assertStatus(422)->assertJsonValidationErrors('byoName');
        $this->assertSame(404, $this->postJson('/servers/nobody.org-1/destroy')->status(), 'a server that is not this team\'s does not exist');
    }

    public function test_a_session_is_needed(): void
    {
        $this->post('/servers/x.org-1/repair')->assertRedirect('/login');
        $this->post('/servers', ['name' => 'web-2'])->assertRedirect('/login');
    }
}
