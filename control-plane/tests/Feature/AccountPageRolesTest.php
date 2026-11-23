<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The Account page shows only what the person's role can use. */
class AccountPageRolesTest extends TestCase
{
    use RefreshDatabase;

    private function as(string $role): User
    {
        $u = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $u->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $u;
    }

    public function test_a_viewer_sees_no_controls(): void
    {
        $this->actingAs($this->as('viewer'));
        $people = $this->get('/account?tab=people')->assertOk()->getContent();
        $this->assertStringNotContainsString('account.invite', $people);
        $this->assertStringNotContainsString('Invite by email', $people);
        $this->assertStringNotContainsString('>Remove<', $people);

        $nav = $this->get('/account')->getContent();
        foreach (['tab=keys', 'tab=providers', 'tab=github'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $nav);
        }
        // A guessed address falls back to the first section instead of showing the panels.
        $this->get('/account?tab=providers')->assertOk()->assertDontSee('Connect DigitalOcean');
    }

    public function test_a_developer_gets_no_member_or_provider_controls_but_an_owner_gets_all(): void
    {
        $this->actingAs($this->as('developer'));
        // The invite form and the provider tabs are in the page only for those who hold the permission.
        $this->assertFalse($this->get('/account?tab=people')->inertiaProps('can.members'));
        $this->assertNotContains('providers', array_column($this->get('/account')->inertiaProps('tabs'), 'key'));

        $this->actingAs($this->as('owner'));
        $this->assertTrue($this->get('/account?tab=people')->inertiaProps('can.members'));
        $tabs = array_column($this->get('/account')->inertiaProps('tabs'), 'key');
        foreach (['keys', 'providers', 'github'] as $shown) {
            $this->assertContains($shown, $tabs);
        }
    }
}
