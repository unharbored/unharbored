<?php

declare(strict_types=1);

namespace App\Lib\Providers\Vultr;

use App\Lib\Providers\ProviderServer;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\TokenApiProvider;
use App\Lib\Providers\Uncertain;

/**
 * Vultr instances. The API has no idempotency key and labels are not unique, so a create first looks for an
 * instance with exactly this label: a retry after a lost answer finds the one it made instead of making a second.
 * User data must be base64.
 */
final class VultrServers implements ServerDriver
{
    public function __construct(private readonly VultrClient $api) {}

    public function create(ServerSpec $spec): string
    {
        foreach ($this->api->get('/instances', ['label' => $spec->name, 'per_page' => 100])['instances'] ?? [] as $instance) {
            if (($instance['label'] ?? null) === $spec->name) {
                return (string) $instance['id'];
            }
        }

        $response = $this->api->post('/instances', [
            'label' => $spec->name,
            'hostname' => $spec->name,
            'region' => $spec->region,
            'plan' => $spec->size,
            'os_id' => (int) $spec->image,
            'user_data' => base64_encode($spec->userData),
            'tags' => array_map(TokenApiProvider::tagFor(...), $spec->tags),
            'backups' => 'disabled',
            'enable_ipv6' => true,
        ]);

        $id = $response['instance']['id'] ?? null;

        return $id !== null ? (string) $id : throw new Uncertain('Vultr accepted the create but returned no instance id.');
    }

    public function find(string $externalId): ?ProviderServer
    {
        $instance = $this->api->get('/instances/'.$externalId)['instance'] ?? null;

        return $instance === null ? null : $this->server($instance);
    }

    public function destroy(string $externalId): void
    {
        try {
            $this->api->delete('/instances/'.$externalId);
        } catch (Rejected $e) {
            throw new Uncertain($e->getMessage(), 0, $e);
        }
    }

    public function listTagged(string $tag): array
    {
        $instances = $this->api->get('/instances', ['tag' => TokenApiProvider::tagFor($tag), 'per_page' => 100])['instances'] ?? [];

        return array_map(fn (array $i) => $this->server($i), $instances);
    }

    /** @param array<string, mixed> $i */
    private function server(array $i): ProviderServer
    {
        $ip = (string) ($i['main_ip'] ?? '');
        $private = (string) ($i['internal_ip'] ?? '');
        $ipv6 = (string) ($i['v6_main_ip'] ?? '');

        return new ProviderServer(
            id: (string) $i['id'],
            state: match (true) {
                ($i['status'] ?? '') === 'pending' => 'pending',
                ($i['status'] ?? '') === 'active' && ($i['power_status'] ?? '') === 'running' => 'running',
                default => 'stopped',
            },
            ipv4: $ip === '' || $ip === '0.0.0.0' ? null : $ip,
            privateIpv4: $private === '' ? null : $private,
            name: (string) ($i['label'] ?? ''),
            ipv6: $ipv6 === '' ? null : $ipv6,
        );
    }
}
