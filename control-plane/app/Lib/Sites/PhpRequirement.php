<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Servers\ServerStack;

/**
 * The oldest PHP an application's Composer files allow: its own `require.php` and the `php` requirement of every package the lock
 * holds for a production install (`--no-dev`, as the deploy runs it). Only the lower end matters. A newer PHP than a package
 * declares is the person's to choose, and the deploy runs Composer with `php+` ignored (its upper limit) so the lock never refuses
 * it; an older PHP than a package needs cannot work, and Composer would fail on it.
 *
 * A branch is tested at its first and its last patch, since a constraint such as `<8.4.1` or `>=8.4.2` is about a patch.
 */
final class PhpRequirement
{
    /** Every branch a constraint could name, oldest first: what a newer PHP is measured against. */
    private const RELEASED = ['7.0', '7.1', '7.2', '7.3', '7.4', '8.0', '8.1', '8.2', '8.3', '8.4', '8.5', '8.6'];

    /**
     * The branches of ServerStack::PHP_VERSIONS that are not older than the application needs, newest first. A branch is old
     * enough to fail only when some constraint is satisfied by no version up to and including it. All of them when
     * `config.platform.php` is set, because Composer then checks against that version and not the running one, and when there is
     * nothing to read.
     *
     * @param  array<string, mixed>  $composer  decoded composer.json
     * @param  array<string, mixed>  $lock  decoded composer.lock
     * @return list<string>
     */
    public static function branches(array $composer, array $lock): array
    {
        $all = self::newestFirst(ServerStack::PHP_VERSIONS);
        if (isset($composer['config']['platform']['php'])) {
            return $all;
        }
        $constraints = [];
        if (is_string($composer['require']['php'] ?? null)) {
            $constraints[] = $composer['require']['php'];
        }
        foreach ((array) ($lock['packages'] ?? []) as $package) {
            if (is_string($package['require']['php'] ?? null)) {
                $constraints[] = $package['require']['php'];
            }
        }

        return array_values(array_filter($all, function (string $branch) use ($constraints): bool {
            $upTo = [];
            foreach (self::RELEASED as $released) {
                if (version_compare($released, $branch, '<=')) {
                    array_push($upTo, "{$released}.0", "{$released}.99");
                }
            }
            foreach ($constraints as $constraint) {
                if (array_filter($upTo, fn (string $version): bool => self::allows($constraint, $version)) === []) {
                    return false;
                }
            }

            return true;
        }));
    }

    /**
     * What to run an application on: its present branch when that fits, otherwise the newest branch that does and is installed on
     * the server, otherwise the newest that fits at all, to be installed. Null when nothing fits.
     *
     * @param  list<string>  $fits  from branches()
     * @param  list<string>  $installed
     * @return array{version: string, installed: bool}|null
     */
    public static function choose(array $fits, string $current, array $installed): ?array
    {
        if (in_array($current, $fits, true)) {
            return ['version' => $current, 'installed' => true];
        }
        foreach ($fits as $branch) {
            if (in_array($branch, $installed, true)) {
                return ['version' => $branch, 'installed' => true];
            }
        }

        return $fits === [] ? null : ['version' => $fits[0], 'installed' => false];
    }

    /** "PHP 8.2 or newer", for a message: the oldest branch of the list. @param  list<string>  $branches */
    public static function describe(array $branches): string
    {
        $oldest = array_reverse(self::newestFirst($branches))[0];

        return "PHP {$oldest} or newer";
    }

    /** True when a Composer version constraint allows the version. */
    public static function allows(string $constraint, string $version): bool
    {
        $constraint = trim(preg_replace('/@\w+/', '', $constraint) ?? '');
        if ($constraint === '' || $constraint === '*') {
            return true;
        }
        foreach (preg_split('/\s*\|\|?\s*/', $constraint) ?: [] as $alternative) {
            if (self::all($alternative, $version)) {
                return true;
            }
        }

        return false;
    }

    private static function all(string $alternative, string $version): bool
    {
        // "7.2 - 8.1": from the first to the last, where a partial last version includes everything under it.
        if (preg_match('/^\s*(\S+)\s+-\s+(\S+)\s*$/', $alternative, $m) === 1) {
            return self::compare($version, '>=', $m[1]) && (substr_count($m[2], '.') >= 2 ? self::compare($version, '<=', $m[2]) : self::compare($version, '<', self::bump($m[2], substr_count($m[2], '.'))));
        }
        $alternative = preg_replace('/(>=|<=|!=|[<>=^~])\s+/', '$1', trim($alternative)) ?? '';
        foreach (preg_split('/[\s,]+/', $alternative, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (! self::token($token, $version)) {
                return false;
            }
        }

        return true;
    }

    private static function token(string $token, string $version): bool
    {
        $token = ltrim($token, 'v');
        if ($token === '*') {
            return true;
        }
        if (preg_match('/^(>=|<=|!=|<>|>|<|=)v?(.+)$/', $token, $m) === 1) {
            return self::compare($version, $m[1] === '<>' ? '!=' : $m[1], $m[2]);
        }
        if (preg_match('/^([\^~])v?(\d+(?:\.\d+){0,2})/', $token, $m) === 1) {
            $parts = substr_count($m[2], '.') + 1;
            // ^ keeps the first number that is not zero; ~ keeps all but the last given number (but never fewer than the major).
            $upper = $m[1] === '^'
                ? (explode('.', $m[2])[0] !== '0' ? self::bump($m[2], 0) : self::bump($m[2], 1))
                : self::bump($m[2], max(0, $parts - 2));

            return self::compare($version, '>=', $m[2]) && self::compare($version, '<', $upper);
        }
        // 8.2.*, 8.*: everything under that prefix.
        if (preg_match('/^(\d+(?:\.\d+)*)\.\*$/', $token, $m) === 1) {
            return self::compare($version, '>=', $m[1]) && self::compare($version, '<', self::bump($m[1], substr_count($m[1], '.')));
        }

        return self::compare($version, '=', $token);
    }

    /** The version just past a prefix: bump("8.2", 1) is 8.3, bump("8.2.1", 0) is 9. The part counted from 0 is the one raised. */
    private static function bump(string $version, int $index): string
    {
        $parts = array_map('intval', explode('.', $version));
        $parts[$index] = ($parts[$index] ?? 0) + 1;

        return implode('.', array_slice($parts, 0, $index + 1)).str_repeat('.0', 2 - $index);
    }

    private static function compare(string $a, string $operator, string $b): bool
    {
        $pad = fn (string $v): string => implode('.', array_pad(array_slice(explode('.', preg_replace('/[^0-9.].*$/', '', $v) ?? ''), 0, 3), 3, '0'));

        return version_compare($pad($a), $pad($b), $operator === '=' ? '==' : $operator);
    }

    /** @param list<string> $versions @return list<string> */
    private static function newestFirst(array $versions): array
    {
        usort($versions, fn (string $a, string $b): int => version_compare($b, $a));

        return $versions;
    }
}
