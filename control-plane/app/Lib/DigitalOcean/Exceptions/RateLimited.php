<?php

declare(strict_types=1);

namespace App\Lib\DigitalOcean\Exceptions;

/**
 * The account's request budget is exhausted.
 *
 * Carries how long to wait so a queued job can release itself back to the
 * queue with a sensible delay rather than holding a worker idle.
 */
final class RateLimited extends DigitalOceanException
{
    public function __construct(string $message, public readonly float $retryAfter = 0.0)
    {
        parent::__construct($message);
    }
}
