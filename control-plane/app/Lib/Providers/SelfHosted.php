<?php

declare(strict_types=1);

namespace App\Lib\Providers;

/**
 * A provider whose servers run on hardware the team already pays for (an Incus host), so the new-server form shows
 * what is left on the host instead of a price, and anything a server there cannot do.
 */
interface SelfHosted
{
    /** What the host has not yet given to servers, in words ("26 GB memory and 70 GB disk free"), or null when it cannot be asked. */
    public function capacity(string $organizationId): ?string;

    /** A limit every server on this provider has, said before one is created, or null. */
    public function warning(string $organizationId): ?string;
}
