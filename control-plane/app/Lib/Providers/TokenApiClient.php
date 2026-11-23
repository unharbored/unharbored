<?php

declare(strict_types=1);

namespace App\Lib\Providers;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Response;
use SensitiveParameter;

/**
 * A provider's JSON API with a bearer token. A 4xx is Rejected (nothing happened); no answer, a 5xx or a
 * rate limit is Uncertain (it may have). A 404 is returned as null so callers can treat "gone" as a fact.
 */
abstract class TokenApiClient
{
    public function __construct(protected readonly HttpFactory $http, #[SensitiveParameter] private readonly string $token, protected readonly string $base) {}

    /** The provider's name, for messages. */
    abstract protected function name(): string;

    /** What the provider said was wrong, from its error body. */
    abstract protected function message(Response $response): string;

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null null for a 404
     */
    public function get(string $path, array $query = [], array $headers = []): ?array
    {
        return $this->send('GET', $path, $query, [], $headers);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        return $this->send('POST', $path, [], $payload, []) ?? throw new Rejected($this->name().' answered 404 to '.$path);
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path, [], [], []);
    }

    /**
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     * @return array<string, mixed>|null
     */
    private function send(string $method, string $path, array $query, array $payload, array $headers): ?array
    {
        try {
            $request = $this->http->withToken($this->token)->withHeaders($headers)->acceptJson()->timeout(30);
            $response = match ($method) {
                'GET' => $request->get($this->base.$path, $query),
                'POST' => $request->post($this->base.$path, $payload),
                'DELETE' => $request->delete($this->base.$path),
            };
        } catch (ConnectionException $e) {
            throw new Uncertain('Could not reach '.$this->name().': '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 404) {
            return null;
        }
        if ($response->successful()) {
            return $response->json() ?? [];
        }
        $message = $this->message($response);

        throw $response->status() >= 500 || $response->status() === 429
            ? new Uncertain($this->name().": {$message}")
            : new Rejected($message);
    }
}
