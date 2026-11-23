<?php

namespace App\Lib\Auth;

use Illuminate\Support\Facades\DB;

/**
 * The organisation the current request acts for. Every query scoped to an
 * organisation takes it from here. For a signed-in user it is the one chosen in
 * the switcher, and only if they still belong to it (checked on every call, not
 * trusted from the session); otherwise their first. Commands, jobs and guests
 * get the default.
 */
final class Org
{
    public const DEFAULT = 'org-1';

    public const SESSION_KEY = 'organization_id';

    public static function id(): string
    {
        $user = auth()->user();
        if ($user === null) {
            return self::DEFAULT;
        }

        $mine = self::mine($user->id);
        $chosen = app()->bound('session.store') ? (string) session(self::SESSION_KEY, '') : '';

        // Someone who belongs nowhere acts in no organisation (not the default one).
        return in_array($chosen, $mine, true) ? $chosen : ($mine[0] ?? '');
    }

    /** @return list<string> organisation ids the user belongs to, oldest first */
    public static function mine(int|string $userId): array
    {
        return DB::table('memberships')->where('user_id', $userId)->orderBy('id')->pluck('organization_id')->all();
    }
}
