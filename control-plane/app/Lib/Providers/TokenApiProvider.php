<?php

declare(strict_types=1);

namespace App\Lib\Providers;

use App\Lib\Audit\Actor;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\Exceptions\CloudException;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

/**
 * A server provider whose only credential is one API token in the vault: it supplies servers and nothing
 * else we drive yet, so single-server applications with local services. A subclass names itself, gives its
 * catalog and its server driver, and says how to check a token.
 */
abstract class TokenApiProvider implements Provider
{
    public function __construct(protected readonly CredentialStore $credentials, protected readonly HttpFactory $http) {}

    abstract protected function client(string $token): TokenApiClient;

    /** The cheapest authenticated read, which throws when the token is not accepted. */
    abstract protected function check(TokenApiClient $client): void;

    public function method(): string
    {
        return 'api';
    }

    public function supplies(): array
    {
        return ['servers'];
    }

    public function verify(string $organizationId, ?Actor $actor = null): array
    {
        $token = (string) $this->credentials->secret($organizationId, $this->id(), 'Verifying credential', $actor)['token'];
        $this->check($this->client($token));

        return ['Arn' => $this->label().' account, token '.substr($token, 0, 4).'••••'];
    }

    /**
     * Check a token with the provider and keep it only if accepted; an earlier token stays until then.
     *
     * @throws InvalidArgumentException
     */
    public function connect(string $organizationId, #[\SensitiveParameter] string $token, ?Actor $actor = null): void
    {
        $token = trim($token);
        try {
            $this->check($this->client($token));
        } catch (CloudException $e) {
            throw new InvalidArgumentException($this->label().' did not accept that token: '.$e->getMessage());
        }
        $this->credentials->connectSecret($organizationId, $this->id(), ['token' => $token], substr($token, 0, 4).'••••••••'.substr($token, -4), 'api_token', $actor,
            ['label' => $this->label().' account']);
    }

    protected function token(string $organizationId, string $reason, ?Actor $actor): string
    {
        return (string) $this->credentials->secret($organizationId, $this->id(), $reason ?: 'Managing servers', $actor)['token'];
    }

    /** Tags are provider-neutral strings such as "org:acme"; these providers take plain words, so a colon becomes a dash. */
    public static function tagFor(string $tag): string
    {
        return str_replace(':', '-', $tag);
    }
}
