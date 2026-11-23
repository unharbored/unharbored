<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use App\Lib\Cloud\Exceptions\CredentialNotFound;
use App\Lib\Cloud\Exceptions\CredentialRevoked;
use App\Lib\DigitalOcean\Client;
use App\Lib\DigitalOcean\Exceptions\AuthenticationFailed;
use App\Lib\DigitalOcean\RateLimiter;
use App\Lib\Vault\Vault;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Holds the credentials that let us act on a customer's DigitalOcean account.
 *
 * This is the most dangerous object in the system. A token here can create,
 * destroy and read a customer's entire infrastructure, and a compromise is
 * not something we can undo on their behalf.
 *
 * Three rules follow from that:
 *
 *  1. The secret lives in the vault and nowhere else. This table holds a
 *     lookup key and non-secret context, so an operator can tell two
 *     credentials apart without decrypting either.
 *  2. Every retrieval is audited, with an actor and a reason. PLAN.md
 *     requires every DigitalOcean API call to be attributable; that starts
 *     here, because this is the only way to get a usable token.
 *  3. A revoked credential fails closed. Customers revoke access, and the
 *     platform has to survive it without losing track of what it already
 *     provisioned.
 */
final class CredentialStore
{
    public const PROVIDER_DIGITALOCEAN = 'digitalocean';

    /**
     * A personal access token is account-wide and cannot be scoped.
     * Acceptable while the only connected account is our own; see ADR 0001
     * and PLAN.md on why external tenants need OAuth instead.
     */
    public const KIND_PAT = 'personal_access_token';

    public const KIND_OAUTH = 'oauth';

    public function __construct(
        private readonly Vault $vault,
        private readonly HttpFactory $http,
        private readonly Cache $cache,
        private readonly AuditLog $audit,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Store a DigitalOcean credential for an organisation.
     *
     * @param  array<string, mixed>  $account  Non-secret context: email, uuid.
     */
    public function connect(
        string $organizationId,
        #[SensitiveParameter] string $token,
        string $kind = self::KIND_PAT,
        array $account = [],
        ?Actor $actor = null,
    ): void {
        $vaultKey = $this->vaultKey(self::PROVIDER_DIGITALOCEAN);

        // Vault first. A row pointing at a secret that does not exist is
        // worse than no row, because it looks connected and is not.
        $this->vault->put($organizationId, $vaultKey, $token, [
            'provider' => self::PROVIDER_DIGITALOCEAN,
            'kind' => $kind,
        ]);

        $attributes = [
            'organization_id' => $organizationId,
            'provider' => self::PROVIDER_DIGITALOCEAN,
            'kind' => $kind,
            'vault_key' => $vaultKey,
            'account_email' => $account['email'] ?? null,
            'account_uuid' => $account['uuid'] ?? null,
            // Budgets are per DigitalOcean account. Two organisations could
            // in principle connect the same account, and they would share a
            // real rate limit whether or not we modelled it.
            'rate_limit_key' => $account['uuid'] ?? ('org:'.$organizationId),
            'revoked_at' => null,
            'last_error' => null,
            'updated_at' => now(),
        ];

        $existing = $this->row($organizationId);

        if ($existing !== null) {
            $this->db()->table('cloud_credentials')->where('id', $existing->id)->update($attributes);
        } else {
            $this->db()->table('cloud_credentials')->insert($attributes + ['created_at' => now()]);
        }

        $this->audit->record(
            organizationId: $organizationId,
            actor: $actor ?? Actor::system('credential-store'),
            action: 'credential.connected',
            resourceType: 'cloud_credential',
            resourceId: self::PROVIDER_DIGITALOCEAN,
            // The token itself would be redacted by the audit log anyway, but
            // not passing it is better than relying on that.
            metadata: [
                'kind' => $kind,
                'account_email' => $account['email'] ?? null,
            ],
        );
    }

    /**
     * Build an API client for an organisation.
     *
     * reason is recorded in the audit log. Pass something an operator reading
     * it later would find useful, such as "provisioning droplet web-1".
     */
    public function clientFor(string $organizationId, string $reason, ?Actor $actor = null): Client
    {
        $row = $this->row($organizationId);

        if ($row === null) {
            throw new CredentialNotFound(sprintf(
                'Organisation %s has not connected a DigitalOcean account.',
                $organizationId
            ));
        }

        if ($row->revoked_at !== null) {
            $this->audit->record(
                organizationId: $organizationId,
                actor: $actor ?? Actor::system('credential-store'),
                action: 'credential.access_denied',
                outcome: Outcome::Denied,
                resourceType: 'cloud_credential',
                resourceId: self::PROVIDER_DIGITALOCEAN,
                reason: 'Credential was revoked',
            );

            throw new CredentialRevoked(sprintf(
                'The DigitalOcean credential for organisation %s was revoked on %s.',
                $organizationId,
                $row->revoked_at
            ));
        }

        $token = $this->vault->get($organizationId, $row->vault_key);

        if ($token === null) {
            throw new CredentialNotFound(sprintf(
                'Organisation %s has a credential record but no secret in the vault.',
                $organizationId
            ));
        }

        $this->audit->record(
            organizationId: $organizationId,
            actor: $actor ?? Actor::system('credential-store'),
            action: 'credential.accessed',
            resourceType: 'cloud_credential',
            resourceId: self::PROVIDER_DIGITALOCEAN,
            reason: $reason,
        );

        return new Client(
            token: $token,
            accountKey: (string) ($row->rate_limit_key ?? $organizationId),
            http: $this->http,
            rateLimiter: new RateLimiter($this->cache),
            baseUrl: config('services.digitalocean.api_url'),
        );
    }

    /**
     * Store a provider's credential that is more than one string (AWS: key id,
     * secret, region, endpoint). The whole set is one vault secret; the row
     * keeps only a hint, never the secret.
     *
     * @param  array<string, string>  $secret
     */
    public function connectSecret(string $organizationId, string $provider, #[SensitiveParameter] array $secret, string $hint, string $kind = 'access_key', ?Actor $actor = null, array $account = []): void
    {
        $vaultKey = $this->vaultKey($provider);
        $this->vault->put($organizationId, $vaultKey, json_encode($secret, JSON_THROW_ON_ERROR), ['provider' => $provider, 'kind' => $kind]);

        $attributes = ['organization_id' => $organizationId, 'provider' => $provider, 'kind' => $kind, 'vault_key' => $vaultKey,
            'key_hint' => $hint, 'account_email' => $account['label'] ?? null, 'account_uuid' => $account['id'] ?? null, 'verified_at' => now(), 'rate_limit_key' => 'org:'.$organizationId.':'.$provider, 'revoked_at' => null, 'last_error' => null, 'updated_at' => now()];
        $existing = $this->row($organizationId, $provider);
        if ($existing !== null) {
            $this->db()->table('cloud_credentials')->where('id', $existing->id)->update($attributes);
        } else {
            $this->db()->table('cloud_credentials')->insert($attributes + ['created_at' => now()]);
        }

        $this->audit->record(organizationId: $organizationId, actor: $actor ?? Actor::system('credential-store'), action: 'credential.connected',
            resourceType: 'cloud_credential', resourceId: $provider, metadata: ['kind' => $kind, 'hint' => $hint]);
    }

    /**
     * A provider's multi-part secret, audited like clientFor.
     *
     * @return array<string, string>
     */
    public function secret(string $organizationId, string $provider, string $reason, ?Actor $actor = null): array
    {
        $row = $this->row($organizationId, $provider);
        if ($row === null) {
            throw new CredentialNotFound("Organisation {$organizationId} has not connected {$provider}.");
        }
        if ($row->revoked_at !== null) {
            throw new CredentialRevoked("The {$provider} credential for organisation {$organizationId} was revoked on {$row->revoked_at}.");
        }
        $raw = $this->vault->get($organizationId, $row->vault_key);
        if ($raw === null) {
            throw new CredentialNotFound("Organisation {$organizationId} has a {$provider} record but no secret in the vault.");
        }
        $this->audit->record(organizationId: $organizationId, actor: $actor ?? Actor::system('credential-store'), action: 'credential.accessed',
            resourceType: 'cloud_credential', resourceId: $provider, reason: $reason);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** Forgets another provider's credential, secret included. The audit record survives. */
    public function forgetProvider(string $organizationId, string $provider, ?Actor $actor = null): void
    {
        $row = $this->row($organizationId, $provider);
        if ($row !== null) {
            $this->vault->forget($organizationId, $row->vault_key);
            $this->db()->table('cloud_credentials')->where('id', $row->id)->delete();
        }
        $this->audit->record(organizationId: $organizationId, actor: $actor ?? Actor::system('credential-store'), action: 'credential.disconnected', resourceType: 'cloud_credential', resourceId: $provider);
    }

    public function has(string $organizationId, string $provider = self::PROVIDER_DIGITALOCEAN): bool
    {
        $row = $this->row($organizationId, $provider);

        return $row !== null && $row->revoked_at === null;
    }

    /**
     * Confirm the credential still works, and record what it belongs to.
     *
     * Customers revoke access. Finding out during a provisioning run is worse
     * than finding out on a schedule.
     */
    public function verify(string $organizationId, ?Actor $actor = null): bool
    {
        try {
            $client = $this->clientFor($organizationId, 'Verifying credential', $actor);
            $account = $client->get('account')['account'] ?? [];
        } catch (AuthenticationFailed $e) {
            $this->markRevoked($organizationId, $e->getMessage(), $actor);

            return false;
        }

        $this->db()->table('cloud_credentials')
            ->where('organization_id', $organizationId)
            ->where('provider', self::PROVIDER_DIGITALOCEAN)
            ->update([
                'account_email' => $account['email'] ?? null,
                'account_uuid' => $account['uuid'] ?? null,
                'verified_at' => now(),
                'last_error' => null,
                'updated_at' => now(),
            ]);

        return true;
    }

    /**
     * Mark a credential as no longer usable.
     *
     * Deliberately does not delete the row or the secret. Resources
     * provisioned under it still exist and still cost money, and an
     * organisation with infrastructure it can no longer reach is a situation
     * someone has to resolve rather than one to erase the evidence of.
     */
    public function markRevoked(string $organizationId, string $reason, ?Actor $actor = null): void
    {
        $this->db()->table('cloud_credentials')
            ->where('organization_id', $organizationId)
            ->where('provider', self::PROVIDER_DIGITALOCEAN)
            ->update([
                'revoked_at' => now(),
                'last_error' => $reason,
                'updated_at' => now(),
            ]);

        $this->audit->record(
            organizationId: $organizationId,
            actor: $actor ?? Actor::system('credential-store'),
            action: 'credential.revoked',
            outcome: Outcome::Failed,
            resourceType: 'cloud_credential',
            resourceId: self::PROVIDER_DIGITALOCEAN,
            reason: $reason,
        );
    }

    /**
     * Forget a credential entirely, including the secret.
     *
     * For a customer disconnecting deliberately. The audit record survives.
     */
    public function disconnect(string $organizationId, ?Actor $actor = null): void
    {
        $row = $this->row($organizationId);

        if ($row !== null) {
            $this->vault->forget($organizationId, $row->vault_key);
            $this->db()->table('cloud_credentials')->where('id', $row->id)->delete();
        }

        $this->audit->record(
            organizationId: $organizationId,
            actor: $actor ?? Actor::system('credential-store'),
            action: 'credential.disconnected',
            resourceType: 'cloud_credential',
            resourceId: self::PROVIDER_DIGITALOCEAN,
        );
    }

    private function row(string $organizationId, string $provider = self::PROVIDER_DIGITALOCEAN): ?object
    {
        return $this->db()->table('cloud_credentials')
            ->where('organization_id', $organizationId)
            ->where('provider', $provider)
            ->first();
    }

    private function vaultKey(string $provider): string
    {
        return sprintf('cloud.%s.token', $provider);
    }
}
