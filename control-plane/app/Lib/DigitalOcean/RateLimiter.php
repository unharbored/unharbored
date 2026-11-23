<?php

declare(strict_types=1);

namespace App\Lib\DigitalOcean;

use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * A token bucket per DigitalOcean account.
 *
 * DigitalOcean allows roughly 5,000 requests an hour with a 250 per minute
 * burst, counted per account. Because every customer connects their own
 * account, the budget is per tenant and one noisy organisation must not be
 * able to exhaust another's.
 *
 * The bucket matters most during fan-out. Provisioning a load-balanced
 * environment touches a VPC, a firewall, several droplets and a load
 * balancer, and a multi-node deploy multiplies that. Discovering the limit by
 * receiving 429s means discovering it halfway through creating
 * infrastructure, which is the worst moment.
 */
final class RateLimiter
{
    /**
     * Burst capacity. Deliberately below DigitalOcean's 250 per minute: the
     * point is to stay away from the limit, not to ride it.
     */
    public const DEFAULT_CAPACITY = 200;

    /**
     * Seconds over which the bucket refills to capacity.
     */
    public const DEFAULT_WINDOW = 60;

    public function __construct(
        private readonly Cache $cache,
        private readonly int $capacity = self::DEFAULT_CAPACITY,
        private readonly int $window = self::DEFAULT_WINDOW,
        private readonly ?\Closure $clock = null,
    ) {
        // A bucket that can never refill would divide by zero when computing
        // a wait, and a window of zero would refill infinitely fast. Both are
        // misconfigurations, and both are better refused here than discovered
        // as an arithmetic error partway through provisioning.
        if ($capacity < 1) {
            throw new \InvalidArgumentException(
                'Rate limiter capacity must be at least 1; a bucket that never allows a request '
                .'would stall provisioning rather than slow it.'
            );
        }

        if ($window < 1) {
            throw new \InvalidArgumentException('Rate limiter window must be at least 1 second.');
        }
    }

    private function now(): float
    {
        return $this->clock !== null ? ($this->clock)() : microtime(true);
    }

    /**
     * Try to consume tokens.
     *
     * Returns the seconds to wait when the bucket is empty, or 0.0 when the
     * request may proceed. Returning a duration rather than sleeping keeps
     * the decision with the caller, which matters because a queued job should
     * release itself back to the queue rather than hold a worker idle.
     */
    public function consume(string $accountKey, int $tokens = 1): float
    {
        $key = $this->cacheKey($accountKey);
        $lock = $this->cache->lock($key.':lock', 5);

        return $lock->block(5, function () use ($key, $tokens): float {
            $now = $this->now();
            $state = $this->cache->get($key);

            $available = $this->capacity;
            $last = $now;

            if (is_array($state) && isset($state['tokens'], $state['at'])) {
                $available = (float) $state['tokens'];
                $last = (float) $state['at'];

                // Refill continuously rather than in steps, so a caller does
                // not have to wait for a window boundary to make progress.
                $elapsed = max(0.0, $now - $last);
                $available = min(
                    (float) $this->capacity,
                    $available + ($elapsed * $this->capacity / $this->window)
                );
            }

            if ($available >= $tokens) {
                $this->cache->put(
                    $key,
                    ['tokens' => $available - $tokens, 'at' => $now],
                    $this->window * 2
                );

                return 0.0;
            }

            // Persist the refilled state even on refusal, so the clock does
            // not restart on every rejected attempt.
            $this->cache->put($key, ['tokens' => $available, 'at' => $now], $this->window * 2);

            $shortfall = $tokens - $available;

            return $shortfall * $this->window / $this->capacity;
        });
    }

    /**
     * Tokens currently available, for diagnostics and tests.
     */
    public function available(string $accountKey): float
    {
        $state = $this->cache->get($this->cacheKey($accountKey));

        if (! is_array($state) || ! isset($state['tokens'], $state['at'])) {
            return (float) $this->capacity;
        }

        $elapsed = max(0.0, $this->now() - (float) $state['at']);

        return min(
            (float) $this->capacity,
            (float) $state['tokens'] + ($elapsed * $this->capacity / $this->window)
        );
    }

    /**
     * Fold DigitalOcean's own accounting into ours.
     *
     * When the API reports fewer remaining requests than our bucket believes,
     * believe the API. Our count can drift: other processes, other tools, and
     * the customer's own use of their account all spend from the same budget.
     */
    public function syncFromHeaders(string $accountKey, ?int $remaining): void
    {
        if ($remaining === null) {
            return;
        }

        $key = $this->cacheKey($accountKey);
        $lock = $this->cache->lock($key.':lock', 5);

        $lock->block(5, function () use ($key, $remaining): void {
            $capped = min((float) $remaining, (float) $this->capacity);
            $state = $this->cache->get($key);

            $current = is_array($state) && isset($state['tokens'])
                ? (float) $state['tokens']
                : (float) $this->capacity;

            if ($capped < $current) {
                $this->cache->put(
                    $key,
                    ['tokens' => $capped, 'at' => $this->now()],
                    $this->window * 2
                );
            }
        });
    }

    public function reset(string $accountKey): void
    {
        $this->cache->forget($this->cacheKey($accountKey));
    }

    private function cacheKey(string $accountKey): string
    {
        return 'do:ratelimit:'.sha1($accountKey);
    }
}
