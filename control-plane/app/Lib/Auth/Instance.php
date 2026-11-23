<?php

declare(strict_types=1);

namespace App\Lib\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The install itself, as opposed to one team: who runs it and the few settings
 * that belong to the whole install.
 *
 * An instance administrator sets the system up and decides who may create teams.
 * It is not a power over teams: it opens no team's servers, secrets or audit log,
 * and a team's Owner is the only way in. The column is `users.is_platform_admin`
 * (the name is kept; the console and the command line say "instance administrator").
 */
final class Instance
{
    public const TEAM_CREATION = 'team_creation';

    /** Anyone signed in may create a team. */
    public const ANYONE = 'anyone';

    /** Only an instance administrator may. */
    public const ADMIN_ONLY = 'admin';

    public static function isAdmin(?User $user): bool
    {
        return $user !== null && (bool) DB::table('users')->where('id', $user->id)->value('is_platform_admin');
    }

    public static function anyAdmin(): bool
    {
        return DB::table('users')->where('is_platform_admin', true)->exists();
    }

    public static function setAdmin(User $user, bool $on): void
    {
        DB::table('users')->where('id', $user->id)->update(['is_platform_admin' => $on, 'updated_at' => now()]);
    }

    /** Who may create a team: "anyone" (the default) or "admin". */
    public static function teamCreation(): string
    {
        $value = DB::table('instance_settings')->where('key', self::TEAM_CREATION)->value('value');

        return $value === self::ADMIN_ONLY ? self::ADMIN_ONLY : self::ANYONE;
    }

    public static function setTeamCreation(string $who): void
    {
        if (! in_array($who, [self::ANYONE, self::ADMIN_ONLY], true)) {
            throw new \InvalidArgumentException('Team creation is "anyone" or "admin".');
        }
        DB::table('instance_settings')->updateOrInsert(['key' => self::TEAM_CREATION], ['value' => $who, 'updated_at' => now(), 'created_at' => now()]);
    }

    public static function canCreateTeam(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        return self::teamCreation() === self::ANYONE || self::isAdmin($user);
    }
}
