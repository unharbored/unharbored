<?php

declare(strict_types=1);

namespace App\Lib\Dns;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Templates\Definition;
use App\Lib\Vault\Vault;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use RuntimeException;
use SensitiveParameter;

/**
 * DNS records at Spaceship, for names Unharbored makes and removes itself
 * (a pull request's preview on a server of its own). The key should carry
 * only dnsrecords:read and dnsrecords:write; it is kept in the vault, with
 * the domains it may change.
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

    /** The most domains one key is kept for. */
    public const MAX_DOMAINS = 20;

    /**
     * The domains the organisation's key may change, none when it is not connected. They are kept in the vault
     * as one comma separated entry.
     *
     * @return list<string>
     */
    public function domains(string $organizationId): array
    {
        return array_values(array_filter(explode(',', (string) $this->vault->get($organizationId, self::DOMAIN))));
    }

    /**
     * What a person typed (names separated by commas, spaces or new lines) as a clean list: lower case, no repeats.
     *
     * @return list<string>
     *
     * @throws RuntimeException when there are none, too many, or one is not a domain name
     */
    public static function parseDomains(string $typed): array
    {
        $names = array_values(array_unique(array_filter(preg_split('/[\s,]+/', strtolower($typed)) ?: [], fn ($n) => $n !== '')));
        if ($names === [] || count($names) > self::MAX_DOMAINS) {
            throw new RuntimeException('Give one to '.self::MAX_DOMAINS.' domains, such as example.com.');
        }
        foreach ($names as $name) {
            if (! Definition::isHostname($name) || ! str_contains($name, '.')) {
                throw new RuntimeException("{$name} is not a domain name such as example.com.");
            }
        }

        return $names;
    }

    /**
     * Checks a key against each domain's records, then keeps it. Nothing is
     * stored when Spaceship refuses it for any of them.
     *
     * @param  string  $domains  one or several, separated by commas or spaces
     * @return int How many records the domains have between them.
     */
    public function connect(string $organizationId, string $domains, #[SensitiveParameter] string $key, #[SensitiveParameter] string $secret): int
    {
        $names = self::parseDomains($domains);
        $count = $this->check($this->client($key, $secret), $names);
        $this->vault->put($organizationId, self::KEY, $key);
        $this->vault->put($organizationId, self::SECRET, $secret);
        $this->vault->put($organizationId, self::DOMAIN, implode(',', $names));
        $this->audit->record($organizationId, Actor::system('control-plane'), 'dns.connected', resourceType: 'domain', resourceId: implode(',', $names),
            metadata: ['provider' => 'spaceship', 'records' => $count]);

        return $count;
    }

    /**
     * Changes which domains the saved key is used for, checking each with it. The key is not asked for again,
     * and nothing changes when Spaceship refuses it for any domain.
     *
     * @return int How many records the domains have between them.
     */
    public function setDomains(string $organizationId, string $domains): int
    {
        $names = self::parseDomains($domains);
        $count = $this->check($this->clientFor($organizationId), $names);
        $before = $this->domains($organizationId);
        $this->vault->put($organizationId, self::DOMAIN, implode(',', $names));
        $this->audit->record($organizationId, Actor::system('control-plane'), 'dns.domains_changed', resourceType: 'domain', resourceId: implode(',', $names),
            metadata: ['provider' => 'spaceship', 'before' => $before, 'records' => $count]);

        return $count;
    }

    /** @param list<string> $names @return int the records the domains have between them */
    private function check(PendingRequest $client, array $names): int
    {
        return array_sum(array_map(fn ($name) => count($this->list($client, $name)), $names));
    }

    /** Forgets the key, secret and domain. Records already made stay at Spaceship. */
    public function disconnect(string $organizationId): void
    {
        $domains = implode(',', $this->domains($organizationId));
        foreach ([self::KEY, self::SECRET, self::DOMAIN] as $name) {
            $this->vault->forget($organizationId, $name);
        }
        $this->audit->record($organizationId, Actor::system('control-plane'), 'dns.disconnected', resourceType: 'domain', resourceId: $domains, metadata: ['provider' => 'spaceship']);
    }

    /** The records of one connected domain. @return list<array{type: string, name: string, address: ?string, ttl: ?int}> */
    public function records(string $organizationId, string $domain): array
    {
        return $this->list($this->clientFor($organizationId), $this->connected($organizationId, $domain));
    }

    /** Creates the record in $domain, or changes it if one of that type and name exists. */
    public function set(string $organizationId, string $domain, string $type, string $name, string $address, int $ttl = 300): void
    {
        $domain = $this->connected($organizationId, $domain);
        $this->clientFor($organizationId)->put(self::API."/dns/records/{$domain}", [
            'force' => true,
            'items' => [['type' => $type, 'name' => $name, 'address' => $address, 'ttl' => $ttl]],
        ])->throw();
        $this->audit->record($organizationId, Actor::system('control-plane'), 'dns.record_set', resourceType: 'domain', resourceId: "{$name}.{$domain}",
            metadata: ['type' => $type, 'address' => $address, 'ttl' => $ttl]);
    }

    /** Removes the record; one already gone is not an error. */
    public function delete(string $organizationId, string $domain, string $type, string $name, string $address): void
    {
        $domain = $this->connected($organizationId, $domain);
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

    /** The domain, when it is one of the connected ones. */
    private function connected(string $organizationId, string $domain): string
    {
        $domain = strtolower(trim($domain));

        return in_array($domain, $this->domains($organizationId), true) ? $domain : throw new RuntimeException("{$domain} is not one of the Spaceship domains connected.");
    }
}
