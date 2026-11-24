<?php

namespace Tests\Feature;

use App\Lib\Auth\Instance;
use App\Lib\Auth\Org;
use App\Lib\Auth\Roles;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The install itself: who administers it, and who may create a team. */
class InstanceTest extends TestCase
{
    use RefreshDatabase;

    private function member(string $role = 'owner'): User
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_anyone_may_create_a_team_by_default(): void
    {
        $user = $this->member('viewer');

        $this->assertSame('anyone', Instance::teamCreation());
        $this->assertTrue(Instance::canCreateTeam($user));
        $this->actingAs($user)->post('/organization', ['new_organization' => 'Globex'])->assertRedirectContains('/account');
        $this->assertTrue(DB::table('organizations')->where('name', 'Globex')->exists());
    }

    public function test_when_set_to_admin_only_an_ordinary_user_cannot_create_a_team_or_see_the_button(): void
    {
        $user = $this->member('owner');
        Instance::setTeamCreation('admin');

        $this->actingAs($user)->post('/organization', ['new_organization' => 'Globex'])->assertForbidden();
        $this->assertFalse(DB::table('organizations')->where('name', 'Globex')->exists());
        $this->actingAs($user)->get('/account?tab=team')->assertOk()->assertDontSee('New team');
    }

    public function test_an_instance_administrator_can_still_create_a_team_when_it_is_admin_only(): void
    {
        $admin = $this->member('viewer');
        Instance::setAdmin($admin, true);
        Instance::setTeamCreation('admin');

        $this->actingAs($admin)->get('/account?tab=team')->assertOk()->assertInertia(fn ($page) => $page->where('team.canCreateTeam', true));
        $this->actingAs($admin)->post('/organization', ['new_organization' => 'Globex'])->assertRedirectContains('/account');
        $this->assertTrue(DB::table('organizations')->where('name', 'Globex')->exists());
    }

    public function test_an_instance_administrator_gets_no_access_to_a_team_they_do_not_belong_to(): void
    {
        $admin = User::factory()->create();
        Instance::setAdmin($admin, true);
        DB::table('organizations')->insert(['id' => 'org-9', 'name' => 'Other', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('memberships')->insert(['user_id' => $admin->id, 'organization_id' => 'org-1', 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertNull(Roles::roleOf($admin, 'org-9'));
        $this->assertFalse(Roles::can(Roles::roleOf($admin, 'org-9'), 'view'));
    }

    public function test_an_unknown_setting_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Instance::setTeamCreation('nobody');
    }

    public function test_the_commands_set_the_admin_and_the_rule(): void
    {
        $user = User::factory()->create(['email' => 'boss@example.com']);

        $this->artisan('instance:admin', ['email' => 'boss@example.com'])->assertSuccessful();
        $this->assertTrue(Instance::isAdmin($user));
        $this->artisan('instance:team-creation', ['who' => 'admin'])->assertSuccessful();
        $this->assertSame('admin', Instance::teamCreation());
        $this->artisan('instance:team-creation', ['who' => 'bogus'])->assertFailed();
        $this->artisan('instance:admin', ['email' => 'boss@example.com', '--remove' => true])->assertSuccessful();
        $this->assertFalse(Instance::isAdmin($user));
        $this->artisan('instance:admin', ['email' => 'nobody@example.com'])->assertFailed();
    }

    public function test_the_first_user_created_is_the_instance_administrator_and_the_second_is_not(): void
    {
        $this->artisan('user:create', ['email' => 'first@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'long-enough-password')
            ->expectsQuestion('Again', 'long-enough-password')
            ->assertSuccessful();
        $this->artisan('user:create', ['email' => 'second@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'long-enough-password')
            ->expectsQuestion('Again', 'long-enough-password')
            ->assertSuccessful();

        $this->assertTrue(Instance::isAdmin(User::where('email', 'first@example.com')->first()));
        $this->assertFalse(Instance::isAdmin(User::where('email', 'second@example.com')->first()));
    }

    public function test_the_first_user_on_a_new_install_owns_the_default_team_so_they_can_use_the_console(): void
    {
        $this->artisan('user:create', ['email' => 'first@example.com'])
            ->expectsQuestion('Password (at least 12 characters)', 'long-enough-password')
            ->expectsQuestion('Again', 'long-enough-password')
            ->assertSuccessful();

        $first = User::where('email', 'first@example.com')->first();
        $this->assertSame(['org-1'], Org::mine($first->id));
        $this->assertDatabaseHas('memberships', ['user_id' => $first->id, 'organization_id' => 'org-1', 'role' => 'owner']);
        $this->assertDatabaseHas('organizations', ['id' => 'org-1']);
    }
}
