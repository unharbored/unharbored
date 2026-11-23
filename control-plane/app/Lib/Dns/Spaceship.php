<?php

declare(strict_types=1);

namespace App\Lib\Dns;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Vault\Vault;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use SensitiveParameter;

/**
 * DNS records at Spaceship, for names Unharbored makes and removes itself
 * (a pull request's preview on a server of its own). The key should carry
 * only dnsrecords:read and dnsrecords:write; it is kept in the vault, with
 * the domain it may change.
 *
 * API: https://docs.spaceship.dev/ (DNS records). 300 requests per domain
 * per 5 minutes; records match by type and name.
 */
final class Spaceship
{
    private const API = 'https://spaceship.dev/api/v1';

    public const KEY = 'dns.spaceship.key';

    public const SECRET = 'dns.spaceship.secret';

    public const DOMAIN = 'dns.spaceship.domain';

    public function __construct(
        private readonly Vault $vault,
        private readonly HttpFactory $http,
        private readonly AuditLog $audit,
    ) {}

    /** The domain the organisation's key may change, or null when none is connected. */
    public function domain(string $organizationId): ?string
    {
        return $this->vault->get($organizationId, self::DOMAIN);
    }

    /**
     * Checks a key against the domain's records, then keeps it. Nothing is
     * stored when Spaceship refuses it.
     *
     * @return int How many records the domain has.
     */
    public function connect(string $organizationId, string $domain, #[SensitiveParameter] string $key, #[SensitiveParameter] string $secret): int
    {
        $domain = strtolower(trim($domain));
        $count = count($this->list($this->client($key, $secret), $domain));
        $this->vault->put($organizationId, self::KEY, $key);
        $this->vault->put($organizationId, self::SECRET, $secret);
        $this->vault->put($organizationId, self::DOMAIN, $domain);
        $this->audit->record($organizationId, Actor::system('control-plane'), 'dns.connected', resourceType: 'domain', resourceId: $domain,
            metadata: ['provider' => 'spaceship', 'records' => $count]);

        return $count;
    }

    /** Forgets the key, secret and domain. Records already made stay at Spaceship. */
    public function disconnect(string $organizationId): void
    {
        $domain = $this->domain($organizationId);
        foreach ([self::KEY, self::SECRET, self::DOMAIN] as $name) {
            $this->vault->forget($organizationId, $name);
        }
        $this->audit->record($organizationId, Actor::system('control-plane'), 'dns.disconnected', resourceType: 'domain', resourceId: (string) $domain, metadata: ['provider' => 'spaceship']);
    }

    /** @return list<array{type: string, name: string, address: ?string, ttl: ?int}> */
    public function records(string $organizationId): array
    {
        return $this->list($this->clientFor($organizationId), $this->domainOrFail($organizationId));
    }

    /** Creates the record, or changes it if one of that type and name exists. */
    public function set(string $organizationId, string $type, string $name, string $address, int $ttl = 300): void
    {
        $domain = $this->domainOrFail($organizationId);
        $this->clientFor($organizationId)->put(self::API."/dns/records/{$domain}", [
            'force' => true,
            'items' => [['type' => $type, 'name' => $name, 'address' => $address, 'ttl' => $ttl]],
        ])->throw();
        $this->audit->record($organizationId, Actor::system('control-plane'), 'dns.record_set', resourceType: 'domain', resourceId: "{$name}.{$domain}",
            metadata: ['type' => $type, 'address' => $address, 'ttl' => $ttl]);
    }

    /** Removes the record; one already gone is not an error. */
    public function delete(string $organizationId, string $type, string $name, string $address): void
    {
        $domain = $this->domainOrFail($organizationId);
        $response = $this->clientFor($organizationId)->send('DELETE', self::API."/dns/records/{$domain}", [
            'json' => [['type' => $type, 'name' => $name, 'address' => $address]],
        ]);
        if (! $response->successful() && $response->status() !== 404) {
            $response->throw();
        }
        $this->audit->record($organizationId, Actor::system('control-plane'), 'dns.record_deleted', resourceType: 'domain', resourceId: "{$name}.{$domain}",
            metadata: ['type' => $type]);
    }

    /** @return list<array{type: string, name: string, address: ?string, ttl: ?int}> */
    private function list(PendingRequest $client, string $domain): array
    {
        $out = [];
        for ($skip = 0; $skip < 5000; $skip += 500) {
            $response = $client->get(self::API."/dns/records/{$domain}", ['take' => 500, 'skip' => $skip]);
            if (! $response->successful()) {
                throw new RuntimeException(match ($response->status()) {
                    401, 403 => "Spaceship refused the key for {$domain}: it needs dnsrecords:read and dnsrecords:write.",
                    404 => "Spaceship has no domain {$domain} on this account.",
                    default => "Spaceship answered {$response->status()} for {$domain}'s records.",
                });
            }
            $items = $response->json('items') ?? [];
            foreach ($items as $item) {
                $out[] = ['type' => (string) $item['type'], 'name' => (string) $item['name'],
                    'address' => isset($item['address']) ? (string) $item['address'] : null, 'ttl' => isset($item['ttl']) ? (int) $item['ttl'] : null];
            }
            if (count($items) < 500) {
                break;
            }
        }

        return $out;
    }

    private function clientFor(string $organizationId): PendingRequest
    {
        $key = $this->vault->get($organizationId, self::KEY) ?? throw new RuntimeException('No Spaceship key is connected. Run dns:connect.');
        $secret = $this->vault->get($organizationId, self::SECRET) ?? throw new RuntimeException('The Spaceship secret is missing from the vault.');

        return $this->client($key, $secret);
    }

    private function client(#[SensitiveParameter] string $key, #[SensitiveParameter] string $secret): PendingRequest
    {
        return $this->http->acceptJson()->asJson()->timeout(20)->withHeaders(['X-API-Key' => $key, 'X-API-Secret' => $secret]);
    }

    private function domainOrFail(string $organizationId): string
    {
        return $this->domain($organizationId) ?? throw new RuntimeException('No Spaceship domain is connected. Run dns:connect.');
    }
}
