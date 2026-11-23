<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The invite box suggests people from the user's other organisations, and still takes any address. */
class InviteSuggestionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggests_people_from_my_other_organisations_only(): void
    {
        foreach (['org-1', 'org-2', 'org-3'] as $id) {
            DB::table('organizations')->insert(['id' => $id, 'name' => $id, 'created_at' => now(), 'updated_at' => now()]);
        }
        $me = User::factory()->create();
        $sadie = User::factory()->create(['name' => 'Sadie Test', 'email' => 'sadie@example.com']);
        $already = User::factory()->create(['email' => 'already@example.com']);
        $stranger = User::factory()->create(['email' => 'stranger@example.com']);
        $m = fn ($u, $o) => DB::table('memberships')->insert(['user_id' => $u->id, 'organization_id' => $o, 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
        $m($me, 'org-1'); $m($me, 'org-2');
        $m($sadie, 'org-2');
        $m($already, 'org-1'); $m($already, 'org-2');
        $m($stranger, 'org-3');

        $suggested = array_column($this->actingAs($me)->get('/account?tab=people')->assertOk()->inertiaProps('people.suggestions'), 'email');

        $this->assertContains('sadie@example.com', $suggested);
        $this->assertNotContains('stranger@example.com', $suggested);
        $this->assertNotContains('already@example.com', $suggested);
    }
}
