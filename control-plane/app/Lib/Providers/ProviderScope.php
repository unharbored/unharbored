<?php

declare(strict_types=1);

namespace App\Lib\Providers;

use InvalidArgumentException;
use stdClass;

/**
 * Providers are never mixed: an application's servers, database, balancer and
 * bucket all live on the application's provider. Called at every point where
 * one thing is attached to another.
 */
final class ProviderScope
{
    public static function assertSame(string $siteProvider, string $otherProvider, string $what): void
    {
        // A machine the user provided (custom) may already use a provider's
        // services: that is how web-1 runs today. Two different api providers
        // are what must never meet.
        if ($siteProvider !== $otherProvider && $siteProvider !== 'custom' && $otherProvider !== 'custom') {
            throw new InvalidArgumentException("{$what} is on {$otherProvider}, but this application is on {$siteProvider}. An application lives wholly on one provider.");
        }
    }

    /** A DigitalOcean-only service (balancer, bucket, managed database, pool) against a site. */
    public static function assertDigitalOcean(stdClass $site, string $what): void
    {
        self::assertSame((string) ($site->provider ?? 'digitalocean'), 'digitalocean', $what);
    }
}
