<?php

declare(strict_types=1);

namespace App\Lib\DigitalOcean;

use App\Lib\DigitalOcean\Exceptions\AuthenticationFailed;
use App\Lib\DigitalOcean\Exceptions\DigitalOceanException;
use App\Lib\DigitalOcean\Exceptions\RateLimited;
use App\Lib\DigitalOcean\Exceptions\RequestFailed;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/**
 * A client for one customer's DigitalOcean account.
 *
 * Constructed per account rather than shared, because the token, the rate
 * limit budget and the tagging namespace are all per tenant.
 *
 * Three behaviours are not optional here:
 *
 *  - **Rate limiting before the call, not after the 429.** Discovering the
 *    limit by being refused means discovering it halfway through creating
 *    infrastructure.
 *  - **Retries only where repeating is safe.** A GET or a DELETE can be
 *    retried freely. A POST that times out may already have created a
 *    droplet, so retrying it blindly is how a customer ends up with two.
 *  - **The token never appears in an exception, a log line or a trace.**
 */
final class Client
{
    public const BASE_URL = 'https://api.digitalocean.com/v2';

    /** Attempts for operations that are safe to repeat. */
    public const MAX_ATTEMPTS = 4;

    /** @var non-empty-string */
    private readonly string $token;

    public function __construct(
        #[SensitiveParameter] string $token,
        private readonly string $accountKey,
        private readonly HttpFactory $http,
        private readonly RateLimiter $rateLimiter,
        private readonly ?\Closure $sleeper = null,
        /** Where the API is, when it is not DigitalOcean's: an emulator in development. */
        private readonly ?string $baseUrl = null,
    ) {
        if ($token === '') {
            throw new AuthenticationFailed('No DigitalOcean token was provided for this account.');
        }

        $this->token = $token;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, query: $query, retryable: true);
    }

    /**
     * Create something.
     *
     * $idempotencyKey must be stable across retries of the same logical
     * operation. It is what makes a timed-out create safe to attempt again,
     * and the ResourceManager generates one before any call is made.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload = [], ?string $idempotencyKey = null): array
    {
        return $this->send(
            'POST',
            $path,
            payload: $payload,
            idempotencyKey: $idempotencyKey,
            // Safe to retry only when the caller supplied a key. Without one,
            // a retry after a timeout risks creating a second resource.
            retryable: $idempotencyKey !== null,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function put(string $path, array $payload = []): array
    {
        return $this->send('PUT', $path, payload: $payload, retryable: true);
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(string $path): array
    {
        // Deleting something already deleted is the desired end state, so
        // this is safe to repeat.
        return $this->send('DELETE', $path, retryable: true);
    }

    /**
     * Walk a paginated collection.
     *
     * @param  array<string, mixed>  $query
     * @return array<int, array<string, mixed>>
     */
    public function paginate(string $path, string $collection, array $query = [], int $perPage = 100): array
    {
        $items = [];
        $page = 1;

        do {
            $response = $this->get($path, array_merge($query, ['page' => $page, 'per_page' => $perPage]));
            $batch = $response[$collection] ?? [];
            $items = array_merge($items, $batch);

            $hasMore = isset($response['links']['pages']['next']);
            $page++;

            // A malformed or hostile pages link must not spin forever.
        } while ($hasMore && $batch !== [] && $page <= 1000);

        return $items;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    private function send(
        string $method,
        string $path,
        array $payload = [],
        array $query = [],
        ?string $idempotencyKey = null,
        bool $retryable = false,
    ): array {
        $attempt = 0;

        while (true) {
            $attempt++;

            $wait = $this->rateLimiter->consume($this->accountKey);
            if ($wait > 0.0) {
                $this->sleep($wait);

                continue;
            }

            try {
                $response = $this->dispatch($method, $path, $payload, $query, $idempotencyKey);
            } catch (ConnectionException $e) {
                // The request may or may not have been received. Only retry
                // when repeating is safe.
                if (! $retryable || $attempt >= self::MAX_ATTEMPTS) {
                    throw new RequestFailed(sprintf(
                        '%s %s could not reach DigitalOcean after %d attempt(s): %s',
                        $method,
                        $path,
                        $attempt,
                        $e->getMessage()
                    ), previous: $e);
                }

                $this->sleep($this->backoff($attempt));

                continue;
            }

            $this->rateLimiter->syncFromHeaders(
                $this->accountKey,
                $this->intHeader($response, 'RateLimit-Remaining')
            );

            if ($response->successful()) {
                return $response->json() ?? [];
            }

            $status = $response->status();

            if ($status === 401 || $status === 403) {
                throw new AuthenticationFailed(sprintf(
                    'DigitalOcean rejected our credentials for this account (%d). '
                    .'The grant may have been revoked by the customer.',
                    $status
                ), $status);
            }

            if ($status === 429) {
                $retryAfter = (float) ($this->intHeader($response, 'Retry-After') ?? 0);

                if ($attempt >= self::MAX_ATTEMPTS) {
                    throw new RateLimited(
                        'DigitalOcean rate limit exceeded and attempts are exhausted.',
                        retryAfter: $retryAfter
                    );
                }

                // Believe the API over our own bucket.
                $this->sleep($retryAfter > 0 ? $retryAfter : $this->backoff($attempt));

                continue;
            }

            if ($status >= 500 && $retryable && $attempt < self::MAX_ATTEMPTS) {
                $this->sleep($this->backoff($attempt));

                continue;
            }

            throw new RequestFailed(sprintf(
                '%s %s failed with status %d: %s',
                $method,
                $path,
                $status,
                $this->errorMessage($response)
            ), status: $status);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $query
     */
    private function dispatch(
        string $method,
        string $path,
        array $payload,
        array $query,
        ?string $idempotencyKey,
    ): Response {
        $headers = ['Accept' => 'application/json'];

        if ($idempotencyKey !== null) {
            $headers['Idempotency-Key'] = $idempotencyKey;
        }

        $request = $this->http
            ->withToken($this->token)
            ->withHeaders($headers)
            ->timeout(30)
            ->connectTimeout(10);

        $url = rtrim($this->baseUrl ?: self::BASE_URL, '/').'/'.ltrim($path, '/');

        return match ($method) {
            'GET' => $request->get($url, $query),
            'POST' => $request->post($url, $payload),
            'PUT' => $request->put($url, $payload),
            'DELETE' => $request->delete($url),
            default => throw new DigitalOceanException(sprintf('Unsupported method %s.', $method)),
        };
    }

    /**
     * Exponential backoff with jitter.
     *
     * Jitter is required, not cosmetic. A provisioning fan-out retries many
     * requests at once, and without jitter they all come back together and
     * recreate the burst that caused the failure.
     */
    private function backoff(int $attempt): float
    {
        $base = min(30.0, 0.5 * (2 ** ($attempt - 1)));

        return $base * (0.5 + (random_int(0, 1000) / 2000));
    }

    private function sleep(float $seconds): void
    {
        if ($this->sleeper !== null) {
            ($this->sleeper)($seconds);

            return;
        }

        usleep((int) ($seconds * 1_000_000));
    }

    private function intHeader(Response $response, string $name): ?int
    {
        $value = $response->header($name);

        return $value === '' ? null : (int) $value;
    }

    /**
     * Pull a usable message out of an error body without echoing the whole
     * response, which can carry request context we would rather not log.
     */
    private function errorMessage(Response $response): string
    {
        $body = $response->json();

        if (is_array($body) && isset($body['message']) && is_string($body['message'])) {
            return $body['message'];
        }

        return 'no message returned';
    }
}
