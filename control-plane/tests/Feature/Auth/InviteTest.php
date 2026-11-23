<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The account page's organisation: renaming it, inviting people by a
 * single-use link, and removing them.
 */
final class InviteTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $this->me = User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']);
        DB::table('memberships')->insert(['user_id' => $this->me->id, 'organization_id' => 'org-1', 'role' => 'owner', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Invites an address and returns the link that was shown once. */
    private function invite(string $email = 'new@example.com'): string
    {
        $response = $this->actingAs($this->me)->post('/account/invitations', ['email' => $email]);
        $response->assertRedirectContains('/account');

        return (string) session('invite.link');
    }

    public function test_it_all_needs_a_session(): void
    {
        $this->post('/account/organization', ['name' => 'x'])->assertRedirect('/login');
        $this->post('/account/invitations', ['email' => 'a@b.co'])->assertRedirect('/login');
        $this->delete('/account/users/1')->assertRedirect('/login');
    }

    public function test_renaming_the_organisation_shows_in_the_header_and_is_audited(): void
    {
        $this->actingAs($this->me)->post('/account/organization', ['name' => '  Globex  '])->assertRedirectContains('/account');

        $this->assertSame('Globex', DB::table('organizations')->where('id', 'org-1')->value('name'));
        $this->assertSame('Globex', $this->actingAs($this->me)->get('/alerts')->inertiaProps('shell.team.name'));
        $this->assertDatabaseHas('audit_records', ['action' => 'organization.renamed']);
        $this->actingAs($this->me)->post('/account/organization', ['name' => ''])->assertSessionHasErrors('name');
    }

    public function test_an_invitation_keeps_only_a_hash_and_shows_the_link_once(): void
    {
        $link = $this->invite();
        $secret = basename($link);

        $this->assertMatchesRegularExpression('/^[a-f0-9]{48}$/', $secret);
        $this->assertDatabaseHas('invitations', ['email' => 'new@example.com', 'token_hash' => hash('sha256', $secret), 'invited_by' => 'matt@example.com']);
        $this->assertDatabaseMissing('invitations', ['token_hash' => $secret]);
        $this->assertDatabaseHas('audit_records', ['action' => 'user.invited']);
        $this->assertStringNotContainsString($secret, (string) json_encode(DB::table('audit_records')->get()));

        $this->actingAs($this->me)->get('/account?tab=people')->assertSee('new@example.com');
    }

    public function test_the_invitation_is_emailed_when_mail_is_set_up_and_shown_otherwise(): void
    {
        config(['mail.default' => 'log']);
        $this->invite('a@example.com');
        $this->assertFalse(session('invite.emailed'));

        config(['mail.default' => 'array']);
        app('mail.manager')->forgetMailers();
        $link = $this->invite('b@example.com');
        $this->assertTrue(session('invite.emailed'));
        $sent = app('mailer')->getSymfonyTransport()->messages();
        $this->assertCount(1, $sent);
        $mail = quoted_printable_decode($sent->first()->toString());
        $this->assertStringContainsString($link, $mail);
        $this->assertStringContainsString('To: b@example.com', $mail);
    }

    public function test_you_cannot_invite_someone_who_already_has_an_account_or_a_bad_address(): void
    {
        $this->actingAs($this->me)->post('/account/invitations', ['email' => 'MATT@example.com'])->assertSessionHasErrors('email');
        $this->actingAs($this->me)->post('/account/invitations', ['email' => 'nope'])->assertSessionHasErrors('email');
        $this->assertSame(0, DB::table('invitations')->count());
    }

    public function test_inviting_again_replaces_the_earlier_link(): void
    {
        $first = basename($this->invite());
        $second = basename($this->invite());

        $this->assertSame(1, DB::table('invitations')->count());
        $this->post('/logout');
        $this->get("/invite/{$first}")->assertInertia(fn (Assert $page) => $page->component('Auth/Invite')->where('invitation', null));
        $this->get("/invite/{$second}")->assertInertia(fn (Assert $page) => $page->component('Auth/Invite')->where('invitation.email', 'new@example.com'));
    }

    public function test_following_the_link_creates_the_account_and_signs_in(): void
    {
        $secret = basename($this->invite());
        auth()->logout();

        $this->get("/invite/{$secret}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Auth/Invite')->where('invitation.email', 'new@example.com')->has('invitation.invited_by'));
        $this->post("/invite/{$secret}", ['name' => 'Newbie', 'password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase'])
            ->assertRedirect('/sites');

        $user = User::where('email', 'new@example.com')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        $this->assertTrue(Hash::check('a brand new passphrase', $user->password));
        $this->assertSame('Newbie', $user->name);
        $this->assertDatabaseHas('audit_records', ['action' => 'user.joined']);
    }

    public function test_a_link_works_once(): void
    {
        $secret = basename($this->invite());
        auth()->logout();
        $form = ['name' => 'Newbie', 'password' => 'a brand new passphrase', 'password_confirmation' => 'a brand new passphrase'];

        $this->post("/invite/{$secret}", $form)->assertRedirect('/sites');
        auth()->logout();

        $this->post("/invite/{$secret}", ['name' => 'Someone else'] + $form)->assertInertia(fn (Assert $page) => $page->component('Auth/Invite')->where('invitation', null));
        $this->assertSame(1, User::where('email', 'new@example.com')->count());
        $this->assertGuest();
    }

    public function test_an_expired_withdrawn_or_made_up_link_is_refused(): void
    {
        $secret = basename($this->invite());
        auth()->logout();
        DB::table('invitations')->update(['expires_at' => now()->subMinute()]);
        $this->get("/invite/{$secret}")->assertInertia(fn (Assert $page) => $page->where('invitation', null));

        $id = DB::table('invitations')->value('id');
        DB::table('invitations')->update(['expires_at' => now()->addDay()]);
        $this->actingAs($this->me)->delete("/account/invitations/{$id}")->assertRedirectContains('/account');
        auth()->logout();
        $this->get("/invite/{$secret}")->assertInertia(fn (Assert $page) => $page->where('invitation', null));

        $this->get('/invite/'.str_repeat('a', 48))->assertInertia(fn (Assert $page) => $page->where('invitation', null));
        $this->get('/invite/short')->assertNotFound();
    }

    public function test_the_new_password_is_checked(): void
    {
        $secret = basename($this->invite());
        auth()->logout();

        $this->post("/invite/{$secret}", ['name' => 'N', 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post("/invite/{$secret}", ['name' => 'N', 'password' => 'a brand new passphrase', 'password_confirmation' => 'different one entirely'])->assertSessionHasErrors('password');
        $this->assertNull(User::where('email', 'new@example.com')->first());
    }

    public function test_removing_someone_signs_them_out_and_you_cannot_remove_yourself(): void
    {
        $other = User::forceCreate(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'another long passphrase']);
        DB::table('memberships')->insert(['user_id' => $other->id, 'organization_id' => 'org-1', 'role' => 'developer', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sessions')->insert(['id' => 'theirs', 'user_id' => $other->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->me)->delete("/account/users/{$other->id}")->assertRedirectContains('/account');
        $this->assertNull(User::find($other->id));
        $this->assertDatabaseMissing('sessions', ['id' => 'theirs']);
        $this->assertDatabaseHas('audit_records', ['action' => 'user.removed']);

        $this->actingAs($this->me)->from('/account')->delete("/account/users/{$this->me->id}")->assertSessionHasErrors('remove');
        $this->assertNotNull(User::find($this->me->id));
    }

    public function test_the_account_page_lists_people_and_pending_invitations(): void
    {
        $other = User::forceCreate(['name' => 'Other', 'email' => 'other@example.com', 'password' => 'another long passphrase']);
        DB::table('memberships')->insert(['user_id' => $other->id, 'organization_id' => 'org-1', 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
        $this->invite('pending@example.com');

        $page = $this->actingAs($this->me)->get('/account?tab=people')->assertOk();
        $this->assertContains('other@example.com', array_column($page->inertiaProps('people.users'), 'email'));
        $this->assertContains('pending@example.com', array_column($page->inertiaProps('people.invites'), 'email'));
        $this->assertTrue($page->inertiaProps('can.members'), 'the Withdraw buttons are offered');
        $this->assertSame('Acme', $page->inertiaProps('org'));
    }
}
