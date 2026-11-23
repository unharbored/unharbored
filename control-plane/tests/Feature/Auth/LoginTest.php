<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * One account, signed in with a password. Nothing in the console answers
 * without a session, and guessing is rate limited.
 */
final class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']);
    }

    private function signIn(string $password = 'correct horse battery'): \Illuminate\Testing\TestResponse
    {
        return $this->post('/login', ['email' => 'matt@example.com', 'password' => $password]);
    }

    public function test_the_console_needs_a_session(): void
    {
        foreach (['/sites', '/site', '/operation', '/servers', '/audit'] as $page) {
            $this->get($page)->assertRedirect('/login');
        }
    }

    public function test_the_right_password_signs_in(): void
    {
        $this->signIn()->assertRedirect('/sites');

        $this->assertAuthenticated();
        $this->assertDatabaseHas('audit_records', ['action' => 'auth.signed_in']);
    }

    public function test_a_wrong_password_does_not(): void
    {
        $this->signIn('wrong')->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_the_sign_in_page_is_a_page_for_guests(): void
    {
        $this->get('/login')->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Login')->where('shell', null));
    }

    public function test_an_unknown_email_gets_the_same_answer(): void
    {
        $this->post('/login', ['email' => 'nobody@example.com', 'password' => 'whatever'])
            ->assertSessionHasErrors(['email' => 'Those details are not right.']);
    }

    public function test_attempts_are_rate_limited(): void
    {
        foreach (range(1, 5) as $_) {
            $this->signIn('wrong');
        }

        $this->signIn()->assertSessionHasErrors(['email' => 'Too many attempts. Try again in a minute.']);
        $this->assertGuest();
    }

    public function test_signing_out_ends_the_session(): void
    {
        $this->signIn();
        $this->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->get('/sites')->assertRedirect('/login');
    }
}
