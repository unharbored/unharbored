<?php

declare(strict_types=1);

namespace Tests\Feature\DigitalOcean;

use App\Lib\DigitalOcean\Client;
use App\Lib\DigitalOcean\Exceptions\AuthenticationFailed;
use App\Lib\DigitalOcean\Exceptions\RateLimited;
use App\Lib\DigitalOcean\Exceptions\RequestFailed;
use App\Lib\DigitalOcean\RateLimiter;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

final class ClientTest extends TestCase
{
    private HttpFactory $http;

    /** @var array<int, float> */
    private array $slept = [];

    protected function setUp(): void
    {
        parent::setUp();

        Cache::store('array')->clear();
        $this->http = new HttpFactory();
        $this->slept = [];
    }

    private function client(string $account = 'acct-1', ?RateLimiter $limiter = null): Client
    {
        return new Client(
            token: 'dop_v1_test_token',
            accountKey: $account,
            http: $this->http,
            rateLimiter: $limiter ?? new RateLimiter(Cache::store('array')),
            sleeper: function (float $seconds): void {
                $this->slept[] = $seconds;
            },
        );
    }

    // ---- basics ----

    public function test_get_returns_the_decoded_body(): void
    {
        $this->http->fake([
            '*' => $this->http->response(['droplets' => [['id' => 1]]], 200),
        ]);

        $result = $this->client()->get('droplets');

        $this->assertSame([['id' => 1]], $result['droplets']);
    }

    public function test_requests_are_authenticated_and_target_the_v2_api(): void
    {
        $this->http->fake(['*' => $this->http->response([], 200)]);

        $this->client()->get('droplets', ['per_page' => 50]);

        $this->http->assertSent(function (Request $request): bool {
            return str_starts_with($request->url(), Client::BASE_URL.'/droplets')
                && $request->hasHeader('Authorization', 'Bearer dop_v1_test_token')
                && str_contains($request->url(), 'per_page=50');
        });
    }

    /**
     * The token is the customer's entire infrastructure. It must not reach a
     * log, an exception message, or a ticket.
     */
    public function test_the_token_never_appears_in_an_exception(): void
    {
        $this->http->fake(['*' => $this->http->response(['message' => 'not found'], 404)]);

        try {
            $this->client()->get('droplets/999');
            $this->fail('Expected RequestFailed.');
        } catch (RequestFailed $e) {
            $this->assertStringNotContainsString('dop_v1_test_token', $e->getMessage());
            $this->assertStringNotContainsString('dop_v1_test_token', (string) $e);
        }
    }

    public function test_an_empty_token_is_refused_at_construction(): void
    {
        $this->expectException(AuthenticationFailed::class);

        new Client('', 'acct-1', $this->http, new RateLimiter(Cache::store('array')));
    }

    // ---- idempotency ----

    /**
     * A create that times out may already have succeeded. The idempotency key
     * is what makes attempting it again safe, and is the difference between
     * one droplet and two.
     */
    public function test_post_sends_the_idempotency_key(): void
    {
        $this->http->fake(['*' => $this->http->response(['droplet' => ['id' => 7]], 201)]);

        $this->client()->post('droplets', ['name' => 'web-1'], 'key-abc-123');

        $this->http->assertSent(fn (Request $r) => $r->hasHeader('Idempotency-Key', 'key-abc-123'));
    }

    /**
     * Without a key, a timed-out POST must not be retried. Retrying it is how
     * a customer ends up paying for two droplets.
     */
    public function test_a_post_without_an_idempotency_key_is_not_retried(): void
    {
        // Count calls directly. A fake that throws never records a sent
        // request, so assertSentCount would report zero regardless.
        $attempts = 0;
        $this->http->fake(function () use (&$attempts) {
            $attempts++;

            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        });

        try {
            $this->client()->post('droplets', ['name' => 'web-1']);
            $this->fail('Expected RequestFailed.');
        } catch (RequestFailed $e) {
            $this->assertStringContainsString('1 attempt', $e->getMessage());
        }

        $this->assertSame(1, $attempts, 'A keyless POST must be attempted exactly once.');
    }

    public function test_a_post_with_an_idempotency_key_is_retried(): void
    {
        $attempts = 0;
        $this->http->fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts < 3) {
                throw new \Illuminate\Http\Client\ConnectionException('timed out');
            }

            return $this->http->response(['droplet' => ['id' => 7]], 201);
        });

        $result = $this->client()->post('droplets', ['name' => 'web-1'], 'key-abc');

        $this->assertSame(7, $result['droplet']['id']);
        $this->assertSame(3, $attempts);
    }

    public function test_delete_is_retried_because_repeating_it_is_harmless(): void
    {
        $attempts = 0;
        $this->http->fake(function () use (&$attempts) {
            $attempts++;

            return $attempts < 2
                ? $this->http->response(['message' => 'server error'], 500)
                : $this->http->response([], 204);
        });

        $this->client()->delete('droplets/7');

        $this->assertSame(2, $attempts);
    }

    // ---- error handling ----

    public function test_revoked_credentials_raise_authentication_failed(): void
    {
        $this->http->fake(['*' => $this->http->response(['message' => 'Unable to authenticate'], 401)]);

        $this->expectException(AuthenticationFailed::class);
        $this->client()->get('droplets');
    }

    public function test_a_401_is_not_retried(): void
    {
        $this->http->fake(['*' => $this->http->response([], 401)]);

        try {
            $this->client()->get('droplets');
        } catch (AuthenticationFailed) {
            // expected
        }

        // Retrying a revoked grant achieves nothing and burns the budget.
        $this->http->assertSentCount(1);
    }

    public function test_a_client_error_is_not_retried(): void
    {
        $this->http->fake(['*' => $this->http->response(['message' => 'size is invalid'], 422)]);

        try {
            $this->client()->get('droplets');
            $this->fail('Expected RequestFailed.');
        } catch (RequestFailed $e) {
            $this->assertSame(422, $e->status);
            $this->assertStringContainsString('size is invalid', $e->getMessage());
        }

        $this->http->assertSentCount(1);
    }

    public function test_server_errors_are_retried_then_surface(): void
    {
        $this->http->fake(['*' => $this->http->response(['message' => 'oops'], 503)]);

        $this->expectException(RequestFailed::class);

        try {
            $this->client()->get('droplets');
        } finally {
            $this->http->assertSentCount(Client::MAX_ATTEMPTS);
        }
    }

    // ---- rate limiting ----

    public function test_a_429_is_retried_honouring_retry_after(): void
    {
        $attempts = 0;
        $this->http->fake(function () use (&$attempts) {
            $attempts++;

            return $attempts < 2
                ? $this->http->response(['message' => 'slow down'], 429, ['Retry-After' => '7'])
                : $this->http->response(['droplets' => []], 200);
        });

        $this->client()->get('droplets');

        $this->assertSame(2, $attempts);
        $this->assertContains(7.0, $this->slept, 'Retry-After should be honoured over our own backoff.');
    }

    public function test_sustained_429s_eventually_surface_with_a_retry_delay(): void
    {
        $this->http->fake(['*' => $this->http->response([], 429, ['Retry-After' => '12'])]);

        try {
            $this->client()->get('droplets');
            $this->fail('Expected RateLimited.');
        } catch (RateLimited $e) {
            $this->assertSame(12.0, $e->retryAfter);
        }
    }

    /**
     * The point of the local bucket is to never reach a 429 during a
     * provisioning fan-out.
     */
    public function test_the_local_bucket_throttles_before_digitalocean_does(): void
    {
        $limiter = new RateLimiter(Cache::store('array'), capacity: 3, window: 60);
        $this->http->fake(['*' => $this->http->response([], 200)]);

        $client = $this->client(limiter: $limiter);

        for ($i = 0; $i < 3; $i++) {
            $client->get('droplets');
        }
        $this->assertSame([], $this->slept, 'The first three requests fit in the bucket.');

        // The fourth must be told to wait rather than being allowed through
        // to collect a 429.
        $this->assertGreaterThan(0.0, $limiter->consume('acct-1'));
    }

    /**
     * A bucket that can never allow a request would stall provisioning
     * rather than slow it, and computing a wait from it divides by zero.
     */
    public function test_a_degenerate_bucket_is_refused_at_construction(): void
    {
        foreach ([['capacity' => 0, 'window' => 60], ['capacity' => 10, 'window' => 0]] as $config) {
            try {
                new RateLimiter(Cache::store('array'), $config['capacity'], $config['window']);
                $this->fail(sprintf('Expected capacity %d / window %d to be refused.', $config['capacity'], $config['window']));
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * Budgets are per DigitalOcean account. One noisy organisation must not
     * be able to exhaust another customer's quota.
     */
    public function test_rate_limit_budgets_are_isolated_per_account(): void
    {
        $limiter = new RateLimiter(Cache::store('array'), capacity: 2, window: 60);

        $this->assertSame(0.0, $limiter->consume('acct-1'));
        $this->assertSame(0.0, $limiter->consume('acct-1'));
        $this->assertGreaterThan(0.0, $limiter->consume('acct-1'));

        // A different account is untouched.
        $this->assertSame(0.0, $limiter->consume('acct-2'));
        $this->assertSame(0.0, $limiter->consume('acct-2'));
    }

    public function test_digitalocean_headers_override_our_optimistic_count(): void
    {
        $limiter = new RateLimiter(Cache::store('array'), capacity: 100, window: 60);
        $limiter->consume('acct-1');

        // The account has been used elsewhere; believe the API.
        $limiter->syncFromHeaders('acct-1', 5);

        // Delta rather than exact: available() refills continuously, so a
        // few microseconds of wall clock have already been credited.
        $this->assertEqualsWithDelta(5.0, $limiter->available('acct-1'), 0.01);
    }

    public function test_headers_never_inflate_our_budget(): void
    {
        $limiter = new RateLimiter(Cache::store('array'), capacity: 10, window: 60);
        for ($i = 0; $i < 8; $i++) {
            $limiter->consume('acct-1');
        }

        // A header claiming plenty available must not refill our bucket past
        // what we have actually spent.
        $limiter->syncFromHeaders('acct-1', 9999);

        $this->assertLessThanOrEqual(10.0, $limiter->available('acct-1'));
    }

    public function test_the_bucket_refills_over_time(): void
    {
        $now = 1000.0;
        $limiter = new RateLimiter(
            Cache::store('array'),
            capacity: 60,
            window: 60,
            clock: function () use (&$now): float { return $now; },
        );

        for ($i = 0; $i < 60; $i++) {
            $limiter->consume('acct-1');
        }
        $this->assertGreaterThan(0.0, $limiter->consume('acct-1'));

        // One token per second at this configuration.
        $now += 10.0;
        $this->assertEqualsWithDelta(10.0, $limiter->available('acct-1'), 0.001);
        $this->assertSame(0.0, $limiter->consume('acct-1'));
    }

    public function test_the_bucket_never_exceeds_capacity(): void
    {
        $now = 1000.0;
        $limiter = new RateLimiter(
            Cache::store('array'),
            capacity: 10,
            window: 60,
            clock: function () use (&$now): float { return $now; },
        );

        $limiter->consume('acct-1');
        $now += 86_400.0;

        $this->assertSame(10.0, $limiter->available('acct-1'));
    }

    // ---- pagination ----

    public function test_pagination_follows_the_next_link(): void
    {
        $page = 0;
        $this->http->fake(function () use (&$page) {
            $page++;

            return $page === 1
                ? $this->http->response([
                    'droplets' => [['id' => 1], ['id' => 2]],
                    'links' => ['pages' => ['next' => 'https://api.digitalocean.com/v2/droplets?page=2']],
                ], 200)
                : $this->http->response(['droplets' => [['id' => 3]], 'links' => ['pages' => []]], 200);
        });

        $droplets = $this->client()->paginate('droplets', 'droplets');

        $this->assertCount(3, $droplets);
        $this->assertSame([1, 2, 3], array_column($droplets, 'id'));
    }

    public function test_pagination_stops_on_an_empty_page(): void
    {
        // A pages link that always claims there is more must not spin
        // forever.
        $this->http->fake([
            '*' => $this->http->response([
                'droplets' => [],
                'links' => ['pages' => ['next' => 'https://api.digitalocean.com/v2/droplets?page=2']],
            ], 200),
        ]);

        $this->assertSame([], $this->client()->paginate('droplets', 'droplets'));
        $this->http->assertSentCount(1);
    }
}
