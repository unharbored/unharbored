<?php

declare(strict_types=1);

namespace App\Support;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Reports which release is currently serving.
 *
 * The platform deploys into a timestamped directory and points a "current"
 * symlink at it. Resolving the real path of the application directory is
 * therefore the most honest answer to "what is actually running", and the
 * quickest way to tell whether a deploy took effect or silently did not.
 */
final class Release
{
    public static function id(): string
    {
        // base_path() resolves through the symlink, so this is the release
        // directory name once deployed, and something else in development.
        $real = realpath(base_path());

        if ($real === false) {
            return 'unknown';
        }

        $name = basename($real);

        // Release ids look like 20261104T143022Z-a1b2c3d.
        return preg_match('/^\d{8}T\d{6}Z-/', $name) === 1 ? $name : 'not-deployed';
    }

    public static function deployedAt(): ?string
    {
        $id = self::id();

        if (! str_contains($id, '-')) {
            return null;
        }

        $stamp = explode('-', $id)[0];
        $parsed = DateTimeImmutable::createFromFormat('Ymd\THis\Z', $stamp, new DateTimeZone('UTC'));

        return $parsed === false ? null : $parsed->format(DATE_RFC3339);
    }

    public static function commit(): string
    {
        // Guard the sentinel values first. Splitting "not-deployed" on the
        // hyphen otherwise reports the commit as "deployed", which is both
        // wrong and confusingly plausible.
        if (! self::isDeployed()) {
            return 'unknown';
        }

        $parts = explode('-', self::id(), 2);

        return $parts[1] ?? 'unknown';
    }

    public static function isDeployed(): bool
    {
        return self::id() !== 'not-deployed' && self::id() !== 'unknown';
    }
}
