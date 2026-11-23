<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\DigitalOcean\Client;
use App\Lib\DigitalOcean\Exceptions\AuthenticationFailed;
use App\Lib\DigitalOcean\RateLimiter;
use App\Lib\Dns\Spaceship;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use SensitiveParameter;
use Throwable;

/**
 * The provider keys on the Account page, in two lists that follow different
 * rules: cloud providers (they create servers, databases and storage) and DNS
 * providers (they manage a domain's records and nothing else). For each: the
 * key's first characters and last four, never the key; the expiry its owner
 * entered, since neither provider reports one; and what depends on it.
 *
 * A new key is checked with the provider before it is kept, and the old one
 * stays until then. Every key is checked daily; Alerts reports one that is
 * rejected, expired or close to expiring.
 */
final class ProviderKeys
{
    /** Days before the expiry that a key is called close to it. */
    public const SOON_DAYS = 14;

    public function __construct(
        private readonly CredentialStore $cloud,
        private readonly Spaceship $dns,
        private readonly AuditLog $audit,
        private readonly HttpFactory $http,
        private readonly Cache $cache,
    ) {}

    /** The first characters and last four of a key: enough to tell two apart. */
    public static function hint(#[SensitiveParameter] string $key): string
    {
        $key = trim($key);

        return strlen($key) < 16 ? '••••••••'.substr($key, -2) : substr($key, 0, 7).'••••••••'.substr($key, -4);
    }

    /**
     * Where a key stands: ok, soon (inside the fortnight), expired, rejected, or none (no expiry set).
     *
     * @return array{state: string, days: ?int}
     */
    public static function standing(?string $expiresAt, ?string $error): array
    {
        $days = $expiresAt === null ? null : (int) Carbon::today()->diffInDays(Carbon::parse($expiresAt)->startOfDay(), false);

        return ['days' => $days, 'state' => match (true) {
            $error !== null => 'rejected',
            $days !== null && $days < 0 => 'expired',
            $days !== null && $days <= self::SOON_DAYS => 'soon',
            $days === null => 'none',
            default => 'ok',
        }];
    }

    /**
     * The badge of a listed key: its class and its words.
     *
     * @param  array<string, mixed>  $k
     * @return array{string, string}
     */
    public static function badge(array $k): array
    {
        $ago = abs((int) $k['days']);
        $label = match ($k['state']) {
            'rejected' => 'Rejected',
            'expired' => "Expired {$ago} day".($ago === 1 ? '' : 's').' ago',
            'soon' => 'Expires in '.max(0, (int) $k['days']).' day'.($k['days'] === 1 ? '' : 's'),
            'ok' => 'Expires '.Carbon::parse($k['expires_at'])->format('M j, Y').' · in '.$k['days'].' days',
            default => 'No expiry set',
        };

        return [['rejected' => 'failed', 'expired' => 'failed', 'soon' => 'running', 'ok' => 'live', 'none' => 'plain'][$k['state']], $label];
    }

    /**
     * The connected cloud providers, with what depends on each.
     *
     * @return list<array<string, mixed>>
     */
    public function cloud(string $organizationId): array
    {
        $row = DB::table('cloud_credentials')->where('organization_id', $organizationId)->where('provider', CredentialStore::PROVIDER_DIGITALOCEAN)->first();
        if ($row === null) {
            return [];
        }
        $error = $row->revoked_at !== null ? ($row->last_error ?? 'The provider rejected the key.') : null;

        return [[
            'provider' => 'digitalocean', 'name' => 'DigitalOcean', 'kind' => 'API token', 'hint' => $row->key_hint, 'account' => $row->account_email,
            'verified_at' => $row->verified_at, 'expires_at' => $row->expires_at, 'error' => $error,
            'uses' => DB::table('resources')->where('organization_id', $organizationId)->count(),
        ] + self::standing($row->expires_at, $error)];
    }

    /** @return list<array<string, mixed>> */
    public function dnsList(string $organizationId): array
    {
        $domain = $this->dns->domain($organizationId);
        if ($domain === null) {
            return [];
        }
        $row = DB::table('dns_credentials')->where('organization_id', $organizationId)->where('provider', 'spaceship')->first();

        return [[
            'provider' => 'spaceship', 'name' => 'Spaceship', 'kind' => 'DNS key and secret', 'hint' => $row->key_hint ?? null, 'domains' => [$domain],
            'verified_at' => $row->verified_at ?? null, 'expires_at' => $row->expires_at ?? null, 'error' => $row->last_error ?? null,
            'uses' => DB::table('sites')->where('organization_id', $organizationId)->where('domain', 'like', '%.'.$domain)->count(),
        ] + self::standing($row->expires_at ?? null, $row->last_error ?? null)];
    }

    /** Checks a DigitalOcean token with DigitalOcean, then keeps it in place of any earlier one. Nothing changes if it is refused. */
    public function connectCloud(string $organizationId, #[SensitiveParameter] string $token, ?string $expiresAt, Actor $actor): void
    {
        $token = trim($token);
        $expiry = self::date($expiresAt);
        try {
            $account = (new Client(token: $token, accountKey: 'org:'.$organizationId, http: $this->http, rateLimiter: new RateLimiter($this->cache), baseUrl: config('services.digitalocean.api_url')))->get('account')['account'] ?? [];
        } catch (AuthenticationFailed) {
            throw new InvalidArgumentException('DigitalOcean refused that token. Nothing was changed.');
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Could not check the token with DigitalOcean: '.mb_substr($e->getMessage(), 0, 160).' Nothing was changed.');
        }
        $this->cloud->connect($organizationId, $token, account: $account, actor: $actor);
        DB::table('cloud_credentials')->where('organization_id', $organizationId)->where('provider', CredentialStore::PROVIDER_DIGITALOCEAN)
            ->update(['key_hint' => self::hint($token), 'expires_at' => $expiry, 'verified_at' => now()]);
    }

    /** Checks a Spaceship key and secret against the domain, then keeps them in place of any earlier ones. */
    public function connectDns(string $organizationId, string $domain, #[SensitiveParameter] string $key, #[SensitiveParameter] string $secret, ?string $expiresAt, Actor $actor): void
    {
        $expiry = self::date($expiresAt);
        try {
            $this->dns->connect($organizationId, $domain, trim($key), trim($secret));
        } catch (Throwable $e) {
            throw new InvalidArgumentException(mb_substr($e->getMessage(), 0, 240).' Nothing was changed.');
        }
        DB::table('dns_credentials')->updateOrInsert(['organization_id' => $organizationId, 'provider' => 'spaceship'],
            ['key_hint' => self::hint($key), 'expires_at' => $expiry, 'verified_at' => now(), 'last_error' => null, 'updated_at' => now(), 'created_at' => now()]);
    }

    public function setExpiry(string $organizationId, string $kind, ?string $expiresAt, Actor $actor): void
    {
        $expiry = self::date($expiresAt);
        if ($kind === 'cloud') {
            DB::table('cloud_credentials')->where('organization_id', $organizationId)->update(['expires_at' => $expiry]);
        } else {
            DB::table('dns_credentials')->updateOrInsert(['organization_id' => $organizationId, 'provider' => 'spaceship'], ['expires_at' => $expiry, 'updated_at' => now(), 'created_at' => now()]);
        }
        $this->audit->record($organizationId, $actor, 'credential.expiry_changed', resourceType: $kind === 'cloud' ? 'cloud_credential' : 'dns_credential', metadata: ['expires_at' => $expiry]);
    }

    /** Asks the provider whether the key still works, and records the answer. */
    public function test(string $organizationId, string $kind, ?Actor $actor = null): bool
    {
        if ($kind === 'cloud') {
            return $this->cloud->has($organizationId) && $this->cloud->verify($organizationId, $actor);
        }
        try {
            $this->dns->records($organizationId);
            $error = null;
        } catch (Throwable $e) {
            $error = mb_substr($e->getMessage(), 0, 300);
        }
        DB::table('dns_credentials')->updateOrInsert(['organization_id' => $organizationId, 'provider' => 'spaceship'],
            ['verified_at' => $error === null ? now() : DB::raw('verified_at'), 'last_error' => $error, 'updated_at' => now(), 'created_at' => now()]);

        return $error === null;
    }

    /** Why a key cannot be disconnected yet, or null when it can. */
    public function blocked(string $organizationId, string $kind): ?string
    {
        if ($kind !== 'cloud') {
            return null;
        }
        $uses = $this->cloud($organizationId)[0]['uses'] ?? 0;

        return $uses > 0 ? "{$uses} server".($uses === 1 ? '' : 's, databases or buckets')." need this key to be managed. Remove them first." : null;
    }

    public function disconnect(string $organizationId, string $kind, Actor $actor): void
    {
        if (($reason = $this->blocked($organizationId, $kind)) !== null) {
            throw new InvalidArgumentException($reason);
        }
        if ($kind === 'cloud') {
            $this->cloud->disconnect($organizationId, $actor);
        } else {
            $this->dns->disconnect($organizationId);
            DB::table('dns_credentials')->where('organization_id', $organizationId)->delete();
        }
    }

    /** Checks every connected key of every organisation. @return int how many were rejected */
    public function verifyAll(): int
    {
        $rejected = 0;
        foreach (DB::table('organizations')->pluck('id') as $org) {
            foreach (['cloud' => $this->cloud((string) $org), 'dns' => $this->dnsList((string) $org)] as $kind => $keys) {
                if ($keys !== [] && ! $this->test((string) $org, $kind)) {
                    $rejected++;
                }
            }
        }

        return $rejected;
    }

    /**
     * Alerts for keys that are rejected, expired or close to it.
     *
     * @return array<string, array{severity: string, title: string, detail: string, link: string}>
     */
    public function alerts(string $organizationId): array
    {
        $out = [];
        foreach ([...$this->cloud($organizationId), ...$this->dnsList($organizationId)] as $key) {
            $title = match ($key['state']) {
                'rejected' => "{$key['name']} rejected the key",
                'expired' => "The {$key['name']} key has expired",
                'soon' => "The {$key['name']} key expires in ".max(0, (int) $key['days']).' day'.($key['days'] === 1 ? '' : 's'),
                default => null,
            };
            if ($title !== null) {
                $out["provider-key:{$key['provider']}"] = ['severity' => $key['state'] === 'soon' ? 'warning' : 'critical', 'title' => $title,
                    'detail' => $key['error'] ?? 'Replace it on the Account page before it stops working.', 'link' => '/account?tab=providers#provider-keys'];
            }
        }

        return $out;
    }

    private static function date(?string $date): ?string
    {
        $date = trim((string) $date);
        if ($date === '') {
            return null;
        }
        try {
            return Carbon::createFromFormat('Y-m-d', $date)->toDateString();
        } catch (Throwable) {
            throw new InvalidArgumentException('Give the expiry as a date.');
        }
    }
}
