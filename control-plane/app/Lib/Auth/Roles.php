<?php

namespace App\Lib\Auth;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The role table of docs/specs/organisations-and-roles.md, in one place.
 * A permission is a name; a role holds the permissions from its own list and
 * every role below it. Check one with `Gate::allows('deploy')`, or `can()` here.
 */
final class Roles
{
    public const OWNER = 'owner';
    public const MANAGER = 'manager';
    public const DEVELOPER = 'developer';
    public const VIEWER = 'viewer';

    /** Lowest first; each role adds these to what the one before it has. */
    private const ADDS = [
        self::VIEWER => ['view', 'tokens.read'],
        self::DEVELOPER => ['view.secrets', 'deploy', 'edit', 'console', 'tokens.create', 'audit.read'],
        self::MANAGER => ['create', 'infrastructure', 'connections', 'members'],
        self::OWNER => ['billing', 'organization.delete', 'ownership'],
    ];

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::ADDS);
    }

    /** @return list<string> */
    public static function permissions(string $role): array
    {
        $held = [];
        foreach (self::ADDS as $name => $adds) {
            $held = array_merge($held, $adds);
            if ($name === $role) {
                return $held;
            }
        }

        return [];
    }

    public static function can(?string $role, string $permission): bool
    {
        return in_array($permission, self::permissions((string) $role), true);
    }

    public static function roleOf(User $user, string $organizationId): ?string
    {
        return DB::table('memberships')->where('user_id', $user->id)
            ->where('organization_id', $organizationId)->value('role');
    }

    /** @return list<string> every permission name */
    public static function allPermissions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::ADDS))));
    }

    /** Defines one gate per permission, checked against the current organisation. */
    public static function register(): void
    {
        foreach (self::allPermissions() as $permission) {
            Gate::define($permission, fn (User $user): bool => self::can(self::roleOf($user, Org::id()), $permission));
        }
    }
}
