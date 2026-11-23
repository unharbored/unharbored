<?php

declare(strict_types=1);

namespace App\Lib\Providers\Incus;

use App\Lib\Audit\Actor;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Provider;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\SelfHosted;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\Uncertain;
use Closure;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;
use SensitiveParameter;

/**
 * Incus on the team's own bare metal: servers are system containers, each with a public IPv6 address. Servers only,
 * so single-server applications with local services.
 *
 * Connecting takes the one-line connection string the host setup script (resources/scripts/incus-host.sh) prints: an
 * Incus trust token plus the host's IPv6 /64, its network card and our project. We check the host's certificate against
 * the fingerprint in the token, make our own client key, and spend the token to have the host trust that key for our
 * project only. The vault keeps the key and the host's certificate; the token is single-use and kept nowhere.
 */
final class IncusProvider implements Provider, SelfHosted
{
    public const PROVIDER = 'incus';

    public const PREFIX = 'uh-incus:';

    /** Our own tiers: Incus has no plans. No price, the host is already paid for. */
    public const SIZES = [
        'small' => ['label' => '1 vCPU · 1 GB · 20 GB disk', 'vcpu' => 1, 'memory_gb' => 1, 'disk_gb' => 20, 'price' => 0],
        'medium' => ['label' => '2 vCPU · 2 GB · 40 GB disk', 'vcpu' => 2, 'memory_gb' => 2, 'disk_gb' => 40, 'price' => 0],
        'large' => ['label' => '2 vCPU · 4 GB · 60 GB disk', 'vcpu' => 2, 'memory_gb' => 4, 'disk_gb' => 60, 'price' => 0],
        'xlarge' => ['label' => '4 vCPU · 8 GB · 80 GB disk', 'vcpu' => 4, 'memory_gb' => 8, 'disk_gb' => 80, 'price' => 0],
    ];

    /** @var Closure(string): string host:port to the PEM certificate it presents */
    private Closure $peerCertificate;

    /** @param  (Closure(string): string)|null  $peerCertificate  a seam for tests */
    public function __construct(private readonly CredentialStore $credentials, private readonly HttpFactory $http, ?Closure $peerCertificate = null)
    {
        $this->peerCertificate = $peerCertificate ?? self::fetchCertificate(...);
    }

    public function id(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'Incus';
    }

    public function method(): string
    {
        return 'api';
    }

    public function supplies(): array
    {
        return ['servers'];
    }

    public function catalog(): Catalog
    {
        return new Catalog(['host' => 'your Incus host'], self::SIZES, 'host', 'ubuntu/24.04/cloud');
    }

    public function servers(string $organizationId, ?Actor $actor = null, string $reason = '', string $region = ''): ServerDriver
    {
        $secret = $this->secret($organizationId, $reason ?: 'Managing servers', $actor);

        $host = (string) parse_url($secret['endpoint'], PHP_URL_HOST);

        return new IncusServers($this->client($secret), $secret['ipv6_prefix'], $secret['parent'], self::SIZES, filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) ? $host : null);
    }

    public function verify(string $organizationId, ?Actor $actor = null): array
    {
        $secret = $this->secret($organizationId, 'Verifying credential', $actor);
        $this->check($this->client($secret), $secret['project']);

        return ['Arn' => $secret['endpoint'].' · project '.$secret['project']];
    }

    /**
     * Spend a connection string from the setup script and keep the key the host now trusts. An earlier connection stays
     * until the new one works.
     *
     * @throws InvalidArgumentException with a message for the person connecting
     */
    public function connect(string $organizationId, #[SensitiveParameter] string $connection, ?Actor $actor = null): void
    {
        $fields = self::parse($connection);
        $address = $fields['address'];

        try {
            $presented = ($this->peerCertificate)($address);
        } catch (\Throwable $e) {
            throw new InvalidArgumentException("Couldn't reach {$address}. Check the machine is on and port 8443 is open to this control plane.");
        }
        if (! hash_equals($fields['fingerprint'], (string) openssl_x509_fingerprint($presented, 'sha256'))) {
            throw new InvalidArgumentException('The host answered with a different certificate than the connection string names. It may have been reinstalled, or something is in the way. Run the setup script again for a new string.');
        }

        [$certificate, $key] = self::newClientKey();
        $secret = ['endpoint' => "https://{$address}", 'project' => $fields['project'], 'client_cert' => $certificate, 'client_key' => $key,
            'server_cert' => $presented, 'ipv6_prefix' => $fields['ipv6_prefix'], 'parent' => $fields['parent']];
        $client = $this->client($secret);

        try {
            $client->post('/1.0/certificates', ['type' => 'client', 'trust_token' => $fields['token']]);
        } catch (Rejected) {
            throw new InvalidArgumentException('The host refused the connection string: it has been used already or expired. Run the setup script again; it prints a new one.');
        } catch (Uncertain $e) {
            throw new InvalidArgumentException("Couldn't reach {$address}: ".$e->getMessage());
        }
        $host = $this->check($client, $fields['project']);

        $this->credentials->connectSecret($organizationId, self::PROVIDER, $secret, $address, 'client_certificate', $actor,
            ['label' => implode(' · ', array_filter([$host['name'], 'project '.$fields['project'], $fields['ipv6_prefix'], $host['facts']]))]);
        Cache::forget($this->capacityKey($organizationId));
        app(Ingress::class)->ensure($organizationId, $actor?->label ?? $actor?->id ?? 'console');
    }

    public function capacity(string $organizationId): ?string
    {
        return Cache::remember($this->capacityKey($organizationId), 60, function () use ($organizationId): ?string {
            try {
                $secret = $this->secret($organizationId, 'Reading host capacity', null);
                $client = $this->client($secret);
                $memory = (int) ($client->get('/1.0/resources')['memory']['total'] ?? 0);
                $space = $client->get('/1.0/storage-pools/default/resources')['space'] ?? [];
                $given = 0;
                foreach ($client->get('/1.0/instances', ['recursion' => 1]) ?? [] as $instance) {
                    $given += self::bytes((string) ($instance['config']['limits.memory'] ?? '0'));
                }
                $freeMemory = max(0, intdiv($memory - $given, 1024 ** 3));
                $freeDisk = max(0, intdiv((int) ($space['total'] ?? 0) - (int) ($space['used'] ?? 0), 1024 ** 3));

                return "The host has {$freeMemory} GB memory and {$freeDisk} GB disk not yet given to servers.";
            } catch (\Throwable) {
                return null;
            }
        });
    }

    public function warning(string $organizationId): ?string
    {
        return Ingress::active($organizationId) ? null
            : "This server gets its own public IPv6 address. Visitors without IPv6, about half in the US, can't reach its sites until the host's IPv4 ingress is up; it is being set up now.";
    }

    /**
     * What a connection string holds, checked.
     *
     * @return array{token: string, address: string, fingerprint: string, project: string, ipv6_prefix: string, parent: string}
     */
    public static function parse(#[SensitiveParameter] string $connection): array
    {
        $connection = trim($connection);
        $notOne = new InvalidArgumentException("That isn't an Incus connection string. It starts with uh-incus: and comes from the setup script.");
        if (! str_starts_with($connection, self::PREFIX)) {
            throw $notOne;
        }
        $fields = json_decode((string) base64_decode(strtr(substr($connection, strlen(self::PREFIX)), '-_', '+/'), true), true);
        $token = is_array($fields) ? json_decode((string) base64_decode((string) ($fields['token'] ?? ''), true), true) : null;
        if (! is_array($fields) || ! is_array($token) || ! isset($token['fingerprint'], $token['addresses'][0], $token['secret'], $fields['ipv6_prefix'], $fields['project'], $fields['parent'])) {
            throw $notOne;
        }
        if (! preg_match('/^[0-9a-f:]+::?\/64$/i', (string) $fields['ipv6_prefix']) || ! preg_match('/^[a-z0-9_-]{1,63}$/i', (string) $fields['project'])
            || ! preg_match('/^[a-zA-Z0-9._-]{1,15}$/', (string) $fields['parent'])) {
            throw $notOne;
        }

        return ['token' => (string) $fields['token'], 'address' => (string) $token['addresses'][0], 'fingerprint' => strtolower((string) $token['fingerprint']),
            'project' => (string) $fields['project'], 'ipv6_prefix' => (string) $fields['ipv6_prefix'], 'parent' => (string) $fields['parent']];
    }

    /**
     * The host's name and a line of facts, and proof the key works in our project.
     *
     * @return array{name: string, facts: string}
     */
    private function check(IncusClient $client, string $project): array
    {
        try {
            $server = $client->get('/1.0') ?? [];
            if (($server['auth'] ?? '') !== 'trusted') {
                throw new InvalidArgumentException('The host no longer accepts our key. Replace the connection with a new string from the setup script.');
            }
            if ($client->get('/1.0/projects/'.rawurlencode($project)) === null) {
                throw new InvalidArgumentException("The host has no project \"{$project}\". Run the setup script again on the host; it creates the project and prints a new string.");
            }
            $resources = $client->get('/1.0/resources') ?? [];
        } catch (Rejected|Uncertain $e) {
            throw new InvalidArgumentException('The host did not accept our key: '.$e->getMessage());
        }
        $environment = $server['environment'] ?? [];
        $vms = str_contains((string) ($environment['driver'] ?? ''), 'qemu');

        return ['name' => (string) ($environment['server_name'] ?? ''), 'facts' => implode(' · ', array_filter([
            isset($environment['server_version']) ? 'Incus '.$environment['server_version'] : null,
            isset($resources['cpu']['total']) ? $resources['cpu']['total'].' cores' : null,
            isset($resources['memory']['total']) ? intdiv((int) $resources['memory']['total'], 1024 ** 3).' GB memory' : null,
            $vms ? 'containers and VMs' : 'containers',
        ]))];
    }

    /** @param array<string, string> $secret */
    private function client(#[SensitiveParameter] array $secret): IncusClient
    {
        return new IncusClient($this->http, $secret['endpoint'], $secret['project'], $secret['client_cert'], $secret['client_key'], $secret['server_cert']);
    }

    /** @return array<string, string> */
    private function secret(string $organizationId, string $reason, ?Actor $actor): array
    {
        return $this->credentials->secret($organizationId, self::PROVIDER, $reason, $actor);
    }

    private function capacityKey(string $organizationId): string
    {
        return "incus-capacity:{$organizationId}";
    }

    /** "2GiB", "512MiB" or bytes, as Incus writes limits.memory. */
    private static function bytes(string $limit): int
    {
        $units = ['KiB' => 1024, 'MiB' => 1024 ** 2, 'GiB' => 1024 ** 3, 'TiB' => 1024 ** 4, 'KB' => 1000, 'MB' => 1000 ** 2, 'GB' => 1000 ** 3, 'TB' => 1000 ** 4];
        foreach ($units as $unit => $factor) {
            if (str_ends_with($limit, $unit)) {
                return (int) ((float) substr($limit, 0, -strlen($unit)) * $factor);
            }
        }

        return (int) $limit;
    }

    /**
     * A fresh EC key and a self-signed certificate for it: the identity the host will trust.
     *
     * @return array{0: string, 1: string} certificate PEM, key PEM
     */
    private static function newClientKey(): array
    {
        // Some OpenSSL 3 builds read the curve only from the nested "ec" option, others only from the flat one.
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'ec' => ['curve_name' => 'secp384r1']])
            ?: throw new \RuntimeException('Could not make a key: '.openssl_error_string());
        $csr = openssl_csr_new(['commonName' => 'unharbored-control-plane'], $key, ['digest_alg' => 'sha384']);
        $x509 = openssl_csr_sign($csr, null, $key, 3650, ['digest_alg' => 'sha384'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($key, $privateKey);

        return [(string) $certificate, (string) $privateKey];
    }

    /** The certificate a TLS server presents, without trusting it: the caller compares its fingerprint. */
    private static function fetchCertificate(string $address): string
    {
        $context = stream_context_create(['ssl' => ['capture_peer_cert' => true, 'verify_peer' => false, 'verify_peer_name' => false]]);
        $socket = @stream_socket_client("ssl://{$address}", $code, $error, 10, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new \RuntimeException($error ?: "Could not connect to {$address}");
        }
        $peer = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);
        if ($peer === null || ! openssl_x509_export($peer, $pem)) {
            throw new \RuntimeException("{$address} presented no certificate.");
        }

        return (string) $pem;
    }
}
