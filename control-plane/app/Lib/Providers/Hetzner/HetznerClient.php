<?php

declare(strict_types=1);

namespace App\Lib\Providers\Hetzner;

use App\Lib\Providers\Rejected;
use App\Lib\Providers\Uncertain;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use SensitiveParameter;

/**
 * Hetzner Cloud's API. A 4xx is Rejected (nothing happened); no answer or a
 * 5xx is Uncertain (it may have). A 404 is returned as null so callers can
 * treat "gone" as a fact rather than an error.
 */
final class HetznerClient
{
    public const BASE = 'https://api.hetzner.cloud/v1';

    public function __construct(private readonly HttpFactory $http, #[SensitiveParameter] private readonly string $token, private readonly string $base = self::BASE) {}

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null null for a 404
     */
    public function get(string $path, array $query = []): ?array
    {
        return $this->send('GET', $path, ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload): array
    {
        return $this->send('POST', $path, ['json' => $payload]) ?? throw new Rejected('Hetzner answered 404 to '.$path);
    }

    public function delete(string $path): void
    {
        $this->send('DELETE', $path, []);
    }

    /**
     * @param  array{query?: array<string, mixed>, json?: array<string, mixed>}  $options
     * @return array<string, mixed>|null
     */
    private function send(string $method, string $path, array $options): ?array
    {
        try {
            $request = $this->http->withToken($this->token)->acceptJson()->timeout(30);
            $response = match ($method) {
                'GET' => $request->get($this->base.$path, $options['query'] ?? []),
                'POST' => $request->post($this->base.$path, $options['json'] ?? []),
                'DELETE' => $request->delete($this->base.$path),
            };
        } catch (ConnectionException $e) {
            throw new Uncertain('Could not reach Hetzner: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 404) {
            return null;
        }
        if ($response->successful()) {
            return $response->json() ?? [];
        }
        $message = ($response->json('error.code') ?? 'error').': '.($response->json('error.message') ?? 'HTTP '.$response->status());

        throw $response->status() >= 500 || $response->status() === 429
            ? new Uncertain("Hetzner: {$message}")
            : new Rejected($message);
    }
}
