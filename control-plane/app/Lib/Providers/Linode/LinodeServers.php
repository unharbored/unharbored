<?php

declare(strict_types=1);

namespace App\Lib\Providers\Linode;

use App\Lib\Providers\ProviderServer;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\TokenApiProvider;
use App\Lib\Providers\Uncertain;
use Illuminate\Support\Str;

/**
 * Linode instances. Labels are unique in an account but the API has no idempotency key, so a create first looks
 * for the label: a retry after a lost answer finds the instance it made instead of making a second. The root
 * password the API requires is random and thrown away; people get in with the keys the first-boot script installs.
 * Lists are filtered with the X-Filter header. The IPv6 address comes with its prefix length ("…/128"), which is cut.
 */
final class LinodeServers implements ServerDriver
{
    public function __construct(private readonly LinodeClient $api) {}

    public function create(ServerSpec $spec): string
    {
        $existing = $this->api->get('/linode/instances', [], ['X-Filter' => json_encode(['label' => $spec->name])])['data'][0]['id'] ?? null;
        if ($existing !== null) {
            return (string) $existing;
        }

        $response = $this->api->post('/linode/instances', [
            'label' => $spec->name,
            'region' => $spec->region,
            'type' => $spec->size,
            'image' => $spec->image,
            'root_pass' => Str::password(40),
            'metadata' => ['user_data' => base64_encode($spec->userData)],
            'tags' => array_map(TokenApiProvider::tagFor(...), $spec->tags),
            'booted' => true,
        ]);

        $id = $response['id'] ?? null;

        return $id !== null ? (string) $id : throw new Uncertain('Linode accepted the create but returned no instance id.');
    }

    public function find(string $externalId): ?ProviderServer
    {
        $linode = $this->api->get('/linode/instances/'.$externalId);

        return $linode === null ? null : $this->server($linode);
    }

    public function destroy(string $externalId): void
    {
        try {
            $this->api->delete('/linode/instances/'.$externalId);
        } catch (Rejected $e) {
            throw new Uncertain($e->getMessage(), 0, $e);
        }
    }

    public function listTagged(string $tag): array
    {
        $linodes = $this->api->get('/linode/instances', ['page_size' => 100], ['X-Filter' => json_encode(['tags' => TokenApiProvider::tagFor($tag)])])['data'] ?? [];

        return array_map(fn (array $l) => $this->server($l), $linodes);
    }

    /** @param array<string, mixed> $l */
    private function server(array $l): ProviderServer
    {
        return new ProviderServer(
            id: (string) $l['id'],
            state: match ((string) ($l['status'] ?? '')) {
                'provisioning', 'booting', 'rebooting' => 'pending',
                'running' => 'running',
                default => 'stopped',
            },
            ipv4: $l['ipv4'][0] ?? null,
            privateIpv4: null,
            name: (string) ($l['label'] ?? ''),
            ipv6: ($l['ipv6'] ?? '') !== '' ? strstr($l['ipv6'].'/', '/', true) : null,
        );
    }
}
