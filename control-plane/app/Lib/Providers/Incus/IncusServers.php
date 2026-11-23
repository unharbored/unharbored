<?php

declare(strict_types=1);

namespace App\Lib\Providers\Incus;

use App\Lib\Providers\ProviderServer;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\ServerSpec;

/**
 * Servers on an Incus host, as system containers. Each has two network cards: eth0 on the host's NAT bridge (from the
 * default profile) for IPv4 out, and eth1, a routed card with its own public IPv6 address from the host's /64.
 *
 * Names are unique in a project, so the name is the id and a create first looks it up: a retry after a lost answer
 * finds the server it made. The IPv6 address is worked out from the name, so a retry asks for the same one and two
 * servers never race for a free address. Tags are kept in the instance's config, where Incus keeps them for us.
 */
final class IncusServers implements ServerDriver
{
    public const IMAGE_SERVER = 'https://images.linuxcontainers.org';

    public const TAGS_KEY = 'user.unharbored.tags';

    /**
     * @param  array<string, array{label: string, vcpu: int, memory_gb: int|float, disk_gb: int, price: int|float}>  $sizes
     */
    public function __construct(
        private readonly IncusClient $api,
        private readonly string $ipv6Prefix,
        private readonly string $parent,
        private readonly array $sizes,
        private readonly ?string $hostIpv4 = null,
    ) {}

    public function create(ServerSpec $spec): string
    {
        if ($this->api->get('/1.0/instances/'.rawurlencode($spec->name)) !== null) {
            return $spec->name;
        }
        $size = $this->sizes[$spec->size] ?? throw new Rejected("Unknown size {$spec->size}.");

        $response = $this->api->post('/1.0/instances', [
            'name' => $spec->name,
            'type' => 'container',
            'start' => true,
            'source' => ['type' => 'image', 'alias' => $spec->image, 'server' => self::IMAGE_SERVER, 'protocol' => 'simplestreams'],
            'config' => [
                'cloud-init.user-data' => $spec->userData,
                'limits.cpu' => (string) $size['vcpu'],
                'limits.memory' => $size['memory_gb'].'GiB',
                self::TAGS_KEY => implode(',', $spec->tags),
            ],
            'devices' => [
                'root' => ['type' => 'disk', 'path' => '/', 'pool' => 'default', 'size' => $size['disk_gb'].'GiB'],
                'eth1' => ['type' => 'nic', 'nictype' => 'routed', 'parent' => $this->parent, 'ipv6.address' => self::addressFor($this->ipv6Prefix, $spec->name)],
            ] + (($spec->extra['role'] ?? null) === 'ingress' ? $this->ingressDevices() : []),
        ]);
        if (isset($response['operation'])) {
            $this->api->wait((string) $response['operation'], 300);
        }

        return $spec->name;
    }

    public function find(string $externalId): ?ProviderServer
    {
        $instance = $this->api->get('/1.0/instances/'.rawurlencode($externalId));

        return $instance === null ? null : $this->server($instance);
    }

    public function destroy(string $externalId): void
    {
        $path = '/1.0/instances/'.rawurlencode($externalId);
        try {
            $stop = $this->api->put($path.'/state', ['action' => 'stop', 'force' => true, 'timeout' => 30]);
            if (isset($stop['operation'])) {
                $this->api->wait((string) $stop['operation'], 60);
            }
        } catch (Rejected) {
            // Already stopped: Incus refuses to stop it again, and the delete below is what matters.
        }
        $delete = $this->api->delete($path);
        if (isset($delete['operation'])) {
            $this->api->wait((string) $delete['operation'], 120);
        }
    }

    public function listTagged(string $tag): array
    {
        $out = [];
        foreach ($this->api->get('/1.0/instances', ['recursion' => 1]) ?? [] as $instance) {
            if (in_array($tag, explode(',', (string) ($instance['config'][self::TAGS_KEY] ?? '')), true)) {
                $out[] = $this->server($instance);
            }
        }

        return $out;
    }

    /**
     * A server's public IPv6 address: the host's /64, then 1, then 48 bits from the name. The leading 1 keeps servers
     * clear of the host's own addresses (::1) and anything set by hand; 48 bits make two names meeting vanishingly rare.
     */
    public static function addressFor(string $prefix, string $name): string
    {
        $network = inet_pton(strstr($prefix.'/', '/', true)) ?: throw new \InvalidArgumentException("Not an IPv6 prefix: {$prefix}");

        return (string) inet_ntop(substr($network, 0, 8).pack('n', 1).substr(hash('sha256', $name, true), 0, 6));
    }

    /**
     * The ingress owns the host's IPv4 ports 80 and 443: proxy devices in NAT mode forward them to a fixed address on
     * the bridge, so the visitor's address reaches nginx unchanged. NAT mode needs that address to be static.
     *
     * @return array<string, array<string, string>>
     */
    private function ingressDevices(): array
    {
        if ($this->hostIpv4 === null) {
            throw new Rejected('The Incus host has no public IPv4 address, so it cannot run the IPv4 ingress.');
        }
        // A key restricted to a project may not read the bridge's config, but it may read its live addresses.
        $bridge = collect($this->api->get('/1.0/networks/incusbr0/state')['addresses'] ?? [])->firstWhere('family', 'inet');
        if (! is_array($bridge) || ! filter_var($bridge['address'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || (int) ($bridge['netmask'] ?? 32) > 24) {
            throw new Rejected('The Incus bridge incusbr0 has no IPv4 subnet the ingress can take an address in. Run the setup script again on the host.');
        }
        $address = long2ip((ip2long($bridge['address']) & ~0xFF) | 250);

        return [
            'eth0' => ['type' => 'nic', 'name' => 'eth0', 'network' => 'incusbr0', 'ipv4.address' => $address],
            'http' => ['type' => 'proxy', 'listen' => "tcp:{$this->hostIpv4}:80", 'connect' => "tcp:{$address}:80", 'nat' => 'true'],
            'https' => ['type' => 'proxy', 'listen' => "tcp:{$this->hostIpv4}:443", 'connect' => "tcp:{$address}:443", 'nat' => 'true'],
        ];
    }

    /** @param array<string, mixed> $i */
    private function server(array $i): ProviderServer
    {
        return new ProviderServer(
            id: (string) $i['name'],
            state: match ((string) ($i['status'] ?? '')) {
                'Running' => 'running',
                'Error' => 'failed',
                default => 'stopped',
            },
            // The ingress answers the public on the host's IPv4, through its proxy device.
            ipv4: preg_match('/^tcp:(\d+\.\d+\.\d+\.\d+):80$/', (string) ($i['devices']['http']['listen'] ?? ''), $m) ? $m[1] : null,
            name: (string) $i['name'],
            ipv6: ($i['devices']['eth1']['ipv6.address'] ?? '') ?: null,
        );
    }
}
