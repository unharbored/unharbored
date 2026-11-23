<?php

namespace Tests\Feature;

use App\Lib\Auth\Roles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_each_role_has_what_the_spec_table_says(): void
    {
        $this->assertTrue(Roles::can('viewer', 'view'));
        $this->assertFalse(Roles::can('viewer', 'deploy'));
        $this->assertFalse(Roles::can('viewer', 'view.secrets'));
        $this->assertTrue(Roles::can('developer', 'deploy'));
        $this->assertTrue(Roles::can('developer', 'console'));
        $this->assertFalse(Roles::can('developer', 'create'));
        $this->assertTrue(Roles::can('manager', 'create'));
        $this->assertTrue(Roles::can('manager', 'members'));
        $this->assertFalse(Roles::can('manager', 'billing'));
        $this->assertFalse(Roles::can('manager', 'ownership'));
        $this->assertTrue(Roles::can('owner', 'billing'));
        $this->assertFalse(Roles::can(null, 'view'));
    }

    public function test_the_gate_uses_the_members_role_in_the_current_organisation(): void
    {
        $user = User::factory()->create();
        $this->assertFalse(Gate::forUser($user)->allows('view'));

        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'developer', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertTrue(Gate::forUser($user)->allows('deploy'));
        $this->assertFalse(Gate::forUser($user)->allows('billing'));
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_a_developer_cannot_invite_and_a_manager_cannot_invite_an_owner(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($this->member('developer'))->post('/account/invitations', ['email' => 'a@example.com'])->assertForbidden();

        $manager = $this->member('manager');
        $this->actingAs($manager)->post('/account/invitations', ['email' => 'b@example.com', 'role' => 'owner'])->assertSessionHasErrors('role');
        $this->actingAs($manager)->post('/account/invitations', ['email' => 'c@example.com', 'role' => 'viewer'])->assertSessionHasNoErrors();
        $this->assertSame('viewer', DB::table('invitations')->where('email', 'c@example.com')->value('role'));
    }

    public function test_the_last_owner_and_owners_in_general_are_protected_from_a_manager(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $owner = $this->member('owner');
        $manager = $this->member('manager');

        $this->actingAs($manager)->from('/account')->delete("/account/users/{$owner->id}")->assertSessionHasErrors('remove');
        $this->assertSame('owner', Roles::roleOf($owner, 'org-1'));

        $other = $this->member('owner');
        $this->actingAs($owner)->delete("/account/users/{$other->id}")->assertRedirectContains('/account');
        $this->actingAs($manager)->from('/account')->delete("/account/users/{$owner->id}")->assertSessionHasErrors('remove');
    }

    public function test_the_switcher_only_accepts_organisations_the_user_belongs_to(): void
    {
        foreach (['org-1', 'org-2', 'org-3'] as $id) {
            DB::table('organizations')->insert(['id' => $id, 'name' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $user = $this->member('developer');
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-2', 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($user)->post('/organization/switch', ['organization' => 'org-2'])->assertRedirect();
        $this->assertSame('org-2', \App\Lib\Auth\Org::id());

        $this->actingAs($user)->post('/organization/switch', ['organization' => 'org-3']);
        $this->assertSame('org-2', \App\Lib\Auth\Org::id());
        $this->assertSame('viewer', Roles::roleOf($user, \App\Lib\Auth\Org::id()));
    }

    public function test_a_new_organisation_is_owned_by_its_creator_and_separate_from_the_first(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $user = $this->member('manager');

        $this->actingAs($user)->post('/organization', ['new_organization' => ' Globex '])->assertRedirectContains('/account');

        $new = DB::table('organizations')->where('name', 'Globex')->first();
        $this->assertNotNull($new);
        $this->assertSame('owner', Roles::roleOf($user, $new->id));
        $this->assertSame('manager', Roles::roleOf($user, 'org-1'));
        $this->assertSame($new->id, \App\Lib\Auth\Org::id());
        $this->assertTrue(Gate::forUser($user)->allows('billing'));

        // Nothing of org-1 shows in the new organisation's People list.
        $this->get('/account?tab=team')->assertOk()->assertSee('Globex');
    }

    public function test_roles_can_be_changed_within_the_rules(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $owner = $this->member('owner');
        $manager = $this->member('manager');
        $dev = $this->member('developer');

        // A Manager can move a Developer to Viewer, and it is audited.
        $this->actingAs($manager)->post("/account/users/{$dev->id}/role", ['role' => 'viewer'])->assertRedirectContains('/account');
        $this->assertSame('viewer', Roles::roleOf($dev, 'org-1'));
        $this->assertDatabaseHas('audit_records', ['action' => 'user.role_changed']);

        // But not to Owner, and not an Owner at all.
        $this->actingAs($manager)->from('/account')->post("/account/users/{$dev->id}/role", ['role' => 'owner'])->assertSessionHasErrors('remove');
        $this->actingAs($manager)->from('/account')->post("/account/users/{$owner->id}/role", ['role' => 'viewer'])->assertSessionHasErrors('remove');
        $this->assertSame('owner', Roles::roleOf($owner, 'org-1'));

        // Nobody changes their own role, so the last Owner cannot demote themselves.
        $this->actingAs($owner)->from('/account')->post("/account/users/{$owner->id}/role", ['role' => 'viewer'])->assertSessionHasErrors('remove');

        // A Developer cannot change roles at all.
        $this->actingAs($dev)->post("/account/users/{$manager->id}/role", ['role' => 'viewer'])->assertForbidden();

        // An Owner can promote, and can then demote the other Owner.
        $this->actingAs($owner)->post("/account/users/{$manager->id}/role", ['role' => 'owner']);
        $this->assertSame('owner', Roles::roleOf($manager, 'org-1'));
        $this->actingAs($owner)->post("/account/users/{$manager->id}/role", ['role' => 'manager']);
        $this->assertSame('manager', Roles::roleOf($manager, 'org-1'));
    }

    public function test_the_account_page_has_sections_and_hides_its_forms_until_asked(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $owner = $this->member('owner');

        $page = $this->actingAs($owner)->get('/account')->assertOk();
        $this->assertContains('people', array_column($page->inertiaProps('tabs'), 'key'));
        $this->assertTrue($page->inertiaProps('team.canCreateTeam'));
        $this->assertTrue($page->inertiaProps('can.members'));
        // The People section is not on the Team tab.
        $this->assertNull($page->inertiaProps('people'));

        $people = $this->get('/account?tab=people')->assertOk();
        $this->assertNotNull($people->inertiaProps('people'));
        $this->assertNull($people->inertiaProps('team'));
    }
}
