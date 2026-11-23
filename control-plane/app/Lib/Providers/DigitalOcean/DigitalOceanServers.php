<?php

declare(strict_types=1);

namespace App\Lib\Providers\DigitalOcean;

use App\Lib\DigitalOcean\Client;
use App\Lib\DigitalOcean\Exceptions\DigitalOceanException;
use App\Lib\DigitalOcean\Exceptions\RequestFailed;
use App\Lib\Providers\ProviderServer;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\Uncertain;

final class DigitalOceanServers implements ServerDriver
{
    public function __construct(private readonly Client $client) {}

    public function create(ServerSpec $spec): string
    {
        $payload = [
            'name' => $spec->name,
            'region' => $spec->region,
            'size' => $spec->size,
            'image' => $spec->image,
            'user_data' => $spec->userData,
            'monitoring' => true,
            'tags' => $spec->tags,
        ];
        foreach (['vpc_uuid', 'ssh_keys'] as $key) {
            if (isset($spec->extra[$key])) {
                $payload[$key] = $spec->extra[$key];
            }
        }

        try {
            $response = $this->client->post('droplets', $payload, $spec->idempotencyKey);
        } catch (RequestFailed $e) {
            // No usable answer might still have created something.
            throw ($e->status === null || $e->status >= 500)
                ? new Uncertain('Create did not return a usable answer: '.$e->getMessage(), 0, $e)
                : new Rejected($e->getMessage(), 0, $e);
        } catch (DigitalOceanException $e) {
            throw new Rejected($e->getMessage(), 0, $e);
        }

        $id = $response['droplet']['id'] ?? null;
        if ($id === null) {
            throw new Uncertain('DigitalOcean accepted the create but returned no droplet id.');
        }

        return (string) $id;
    }

    public function find(string $externalId): ?ProviderServer
    {
        try {
            return $this->server($this->client->get('droplets/'.$externalId)['droplet'] ?? []);
        } catch (RequestFailed $e) {
            if ($e->status === 404) {
                return null;
            }
            throw new Uncertain($e->getMessage(), 0, $e);
        } catch (DigitalOceanException $e) {
            throw new Uncertain($e->getMessage(), 0, $e);
        }
    }

    public function destroy(string $externalId): void
    {
        try {
            $this->client->delete('droplets/'.$externalId);
        } catch (RequestFailed $e) {
            if ($e->status !== 404) {
                throw new Uncertain($e->getMessage(), 0, $e);
            }
        } catch (DigitalOceanException $e) {
            throw new Uncertain($e->getMessage(), 0, $e);
        }
    }

    public function listTagged(string $tag): array
    {
        try {
            $droplets = $this->client->get('droplets', ['tag_name' => $tag, 'per_page' => 200])['droplets'] ?? [];
        } catch (DigitalOceanException $e) {
            throw new Uncertain($e->getMessage(), 0, $e);
        }

        return array_map(fn (array $d) => $this->server($d), $droplets);
    }

    /** @param array<string, mixed> $d */
    private function server(array $d): ProviderServer
    {
        $status = (string) ($d['status'] ?? 'unknown');

        return new ProviderServer(
            id: (string) ($d['id'] ?? ''),
            state: match ($status) {
                'active' => 'running',
                'new' => 'pending',
                'errored', 'archive' => 'failed',
                default => 'stopped',
            },
            ipv4: $this->address($d, 'public'),
            privateIpv4: $this->address($d, 'private'),
            name: (string) ($d['name'] ?? ''),
            ipv6: $this->address($d, 'public', 'v6'),
        );
    }

    /** @param array<string, mixed> $d */
    private function address(array $d, string $type, string $family = 'v4'): ?string
    {
        foreach ($d['networks'][$family] ?? [] as $net) {
            if (($net['type'] ?? null) === $type) {
                return $net['ip_address'] ?? null;
            }
        }

        return null;
    }
}
