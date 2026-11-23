<?php

declare(strict_types=1);

namespace App\Lib\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use InvalidArgumentException;
use Throwable;

/**
 * Inviting someone to the organisation: a single-use link, valid for a week.
 *
 * Only a hash of the link's secret is stored. The link is shown once to
 * whoever invites, and emailed when mail is configured, so an invitation
 * still works on a server that cannot send it.
 */
final class Invitations
{
    public const TTL_DAYS = 7;

    /**
     * @return array{link: string, emailed: bool}
     */
    public function invite(string $organizationId, string $email, string $invitedBy, string $role = Roles::DEVELOPER): array
    {
        $email = strtolower(trim($email));
        if (! in_array($role, Roles::all(), true)) {
            throw new InvalidArgumentException('That is not a role.');
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('That is not an email address.');
        }
        if (User::where('email', $email)->exists()) {
            throw new InvalidArgumentException("{$email} already has an account.");
        }

        // Inviting the same address again replaces the earlier link.
        DB::table('invitations')->where('organization_id', $organizationId)->where('email', $email)->whereNull('accepted_at')->delete();

        $secret = bin2hex(random_bytes(24));
        DB::table('invitations')->insert([
            'organization_id' => $organizationId,
            'email' => $email,
            'token_hash' => hash('sha256', $secret),
            'invited_by' => $invitedBy,
            'role' => $role,
            'expires_at' => now()->addDays(self::TTL_DAYS),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $link = route('invite', $secret);

        return ['link' => $link, 'emailed' => $this->email($organizationId, $email, $invitedBy, $link)];
    }

    /** @return list<object> */
    public function pending(string $organizationId): array
    {
        return DB::table('invitations')->where('organization_id', $organizationId)->whereNull('accepted_at')
            ->where('expires_at', '>', now())->orderByDesc('id')->get()->all();
    }

    public function revoke(string $organizationId, int $id): ?string
    {
        $row = DB::table('invitations')->where('organization_id', $organizationId)->where('id', $id)->whereNull('accepted_at')->first();
        DB::table('invitations')->where('id', $row?->id)->delete();

        return $row?->email;
    }

    /** The open invitation a link belongs to, or null if it is unknown, used or expired. */
    public function find(string $secret): ?object
    {
        return DB::table('invitations')->where('token_hash', hash('sha256', $secret))->whereNull('accepted_at')
            ->where('expires_at', '>', now())->first();
    }

    /** Creates the account and uses the invitation up, together or not at all. */
    public function accept(string $secret, string $name, string $password): ?User
    {
        return DB::transaction(function () use ($secret, $name, $password) {
            $invitation = $this->find($secret);
            // Claim it first, so two requests with one link cannot both create an account.
            if ($invitation === null || DB::table('invitations')->where('id', $invitation->id)->whereNull('accepted_at')->update(['accepted_at' => now()]) !== 1) {
                return null;
            }
            if (User::where('email', $invitation->email)->exists()) {
                return null;
            }

            $user = User::forceCreate(['name' => $name, 'email' => $invitation->email, 'password' => $password, 'email_verified_at' => now()]);
                        DB::table('memberships')->insert([
                'user_id' => $user->id, 'organization_id' => $invitation->organization_id, 'role' => $invitation->role,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            return $user;
        });
    }

    private function email(string $organizationId, string $to, string $invitedBy, string $link): bool
    {
        if (config('mail.default') === 'log') {
            return false;
        }
        $org = (string) (DB::table('organizations')->where('id', $organizationId)->value('name') ?: $organizationId);
        try {
            Mail::raw("{$invitedBy} invited you to {$org} on cloud-manager.\n\nCreate your account: {$link}\n\nThe link works once and expires in ".self::TTL_DAYS.' days.',
                fn ($m) => $m->to($to)->subject("[Unharbored] You're invited to {$org}"));

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
