<?php

declare(strict_types=1);

namespace App\Lib\Providers\Hetzner;

use App\Lib\Providers\ProviderServer;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\Uncertain;

/**
 * Hetzner Cloud servers. The API has no idempotency key, but server names are
 * unique in a project, so a create first looks for the name: a retry after a
 * lost answer finds the server it made instead of making a second.
 *
 * Tags become labels: "org:acme" is the label org=acme, a bare "cloud-manager"
 * is the key with an empty value, so listTagged is a label selector.
 */
final class HetznerServers implements ServerDriver
{
    public function __construct(private readonly HetznerClient $api) {}

    public function create(ServerSpec $spec): string
    {
        $existing = $this->api->get('/servers', ['name' => $spec->name])['servers'][0]['id'] ?? null;
        if ($existing !== null) {
            return (string) $existing;
        }

        $labels = [];
        foreach ($spec->tags as $tag) {
            [$key, $value] = array_pad(explode(':', $tag, 2), 2, '');
            $labels[$key] = $value;
        }

        $response = $this->api->post('/servers', [
            'name' => $spec->name,
            'server_type' => $spec->size,
            'location' => $spec->region,
            'image' => $spec->image,
            'user_data' => $spec->userData,
            'labels' => $labels,
            'start_after_create' => true,
            'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
        ]);

        $id = $response['server']['id'] ?? null;

        return $id !== null ? (string) $id : throw new Uncertain('Hetzner accepted the create but returned no server id.');
    }

    public function find(string $externalId): ?ProviderServer
    {
        $server = $this->api->get('/servers/'.$externalId)['server'] ?? null;

        return $server === null ? null : $this->server($server);
    }

    public function destroy(string $externalId): void
    {
        try {
            $this->api->delete('/servers/'.$externalId);
        } catch (Rejected $e) {
            throw new Uncertain($e->getMessage(), 0, $e);
        }
    }

    public function listTagged(string $tag): array
    {
        [$key, $value] = array_pad(explode(':', $tag, 2), 2, '');
        $selector = $value === '' ? $key : "{$key}={$value}";
        $servers = $this->api->get('/servers', ['label_selector' => $selector, 'per_page' => 50])['servers'] ?? [];

        return array_map(fn (array $s) => $this->server($s), $servers);
    }

    /** @param array<string, mixed> $s */
    private function server(array $s): ProviderServer
    {
        return new ProviderServer(
            id: (string) $s['id'],
            state: match ((string) ($s['status'] ?? '')) {
                'initializing', 'starting' => 'pending',
                'running' => 'running',
                default => 'stopped',
            },
            ipv4: $s['public_net']['ipv4']['ip'] ?? null,
            privateIpv4: $s['private_net'][0]['ip'] ?? null,
            name: (string) ($s['name'] ?? ''),
            ipv6: $this->ipv6($s['public_net']['ipv6']['ip'] ?? null),
        );
    }

    /** Hetzner reports the server's /64 ("2a01:4f8:c17:1a2b::/64"); the server itself answers on the first address in it. */
    private function ipv6(?string $network): ?string
    {
        return $network === null || $network === '' ? null : strstr($network, '/', true).'1';
    }
}
