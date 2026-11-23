<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Changing your own password from the console: being signed in is enough, the
 * new one is checked, and every other session ends.
 */
final class ChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']);
    }

    private function change(array $over = []): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->user)->from('/account')->post('/account/password', $over + [
            'password' => 'a brand new passphrase',
            'password_confirmation' => 'a brand new passphrase',
        ]);
    }

    public function test_the_page_needs_a_session(): void
    {
        $this->get('/account')->assertRedirect('/login');
        $this->post('/account/password', [])->assertRedirect('/login');
    }

    public function test_the_page_shows_the_form_and_is_linked_from_the_header(): void
    {
        $this->actingAs($this->user)->get('/account?tab=login')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Account')->where('tab', 'login')->where('email', 'matt@example.com'));
        // The header's Account link comes from the shared shell.
        $this->assertSame(route('account'), $this->actingAs($this->user)->get('/alerts')->inertiaProps('shell.routes.account'));
    }

    public function test_a_signed_in_user_can_change_it_without_the_old_one_and_it_is_audited(): void
    {
        $this->change()->assertRedirectContains('/account')->assertSessionHas('status');

        $this->assertTrue(Hash::check('a brand new passphrase', $this->user->fresh()->password));
        $this->assertDatabaseHas('audit_records', ['action' => 'auth.password_changed']);
    }

    public function test_the_new_password_signs_in_and_the_old_one_does_not(): void
    {
        $this->change();
        auth()->logout();

        $this->post('/login', ['email' => 'matt@example.com', 'password' => 'correct horse battery'])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => 'matt@example.com', 'password' => 'a brand new passphrase'])->assertRedirect('/sites');
    }

    public function test_a_weak_unconfirmed_or_unchanged_password_is_refused(): void
    {
        $this->change(['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->change(['password_confirmation' => 'something else entirely'])->assertSessionHasErrors('password');
        $this->change(['password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery'])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('correct horse battery', $this->user->fresh()->password));
    }

    public function test_other_sessions_are_signed_out_and_the_remember_token_changes(): void
    {
        DB::table('sessions')->insert([
            ['id' => 'other-device', 'user_id' => $this->user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'someone-else', 'user_id' => $this->user->id + 100, 'payload' => '', 'last_activity' => time()],
        ]);
        $before = $this->user->fresh()->remember_token;

        $this->change();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        $this->assertDatabaseHas('sessions', ['id' => 'someone-else']);
        $this->assertNotSame($before, $this->user->fresh()->remember_token);
    }
}
