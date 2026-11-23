<?php

declare(strict_types=1);

namespace App\Lib\Scaling;

use stdClass;

/**
 * The balancer in front of an application, as the scaler needs it: made to
 * match the application's servers as they are now. Adding a server to the
 * application's list and calling apply() puts it behind the balancer; taking
 * it off the list and calling apply() drains it. A second provider's balancer
 * (or an HAProxy we run ourselves) implements this beside DigitalOcean's.
 */
interface EdgeDriver
{
    /** Make the balancer send traffic to exactly the application's servers. Returns what it did, for the log. */
    public function apply(stdClass $site): string;

    /** Put the primary's current certificate on the balancer. Returns what it did. */
    public function syncCertificate(stdClass $site): string;
}
