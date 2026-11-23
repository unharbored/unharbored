<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** A refused page is a page: the console's own "Not allowed", not a bare error screen. */
final class ForbiddenPageTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_role_that_may_not_open_a_page_sees_not_allowed_in_the_console(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);

        // The audit log needs audit.read, which a Viewer does not have.
        $this->actingAs($user)->get('/audit')->assertForbidden()
            ->assertInertia(fn (Assert $page) => $page->component('Errors/Forbidden')->where('shell.team.id', 'org-1'));
    }

    public function test_someone_who_belongs_to_no_team_sees_it_without_the_console_frame(): void
    {
        $this->actingAs(User::factory()->create());
        DB::table('memberships')->truncate();

        $this->get('/sites')->assertForbidden()->assertInertia(fn (Assert $page) => $page->component('Errors/Forbidden'));
    }

    public function test_a_json_client_still_gets_json(): void
    {
        $this->actingAs(User::factory()->create());
        DB::table('memberships')->truncate();

        $this->getJson('/sites')->assertForbidden()->assertJsonMissing(['component' => 'Errors/Forbidden']);
    }
}
