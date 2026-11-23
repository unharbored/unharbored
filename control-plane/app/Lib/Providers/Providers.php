<?php

declare(strict_types=1);

namespace App\Lib\Providers;

use App\Lib\Cloud\CredentialStore;
use App\Lib\Providers\Aws\AwsProvider;
use App\Lib\Providers\Custom\CustomProvider;
use App\Lib\Providers\DigitalOcean\DigitalOceanProvider;
use App\Lib\Providers\Hetzner\HetznerProvider;
use App\Lib\Providers\Incus\IncusProvider;
use App\Lib\Providers\Linode\LinodeProvider;
use App\Lib\Providers\Vultr\VultrProvider;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

/** The providers the control plane knows, by id. */
final class Providers
{
    /** @var array<string, Provider> */
    private array $providers = [];

    public function __construct(private readonly CredentialStore $credentials, private readonly HttpFactory $http)
    {
        foreach ([new DigitalOceanProvider($credentials), new AwsProvider($credentials, $http), new HetznerProvider($credentials, $http), new VultrProvider($credentials, $http), new LinodeProvider($credentials, $http), new IncusProvider($credentials, $http), new CustomProvider] as $provider) {
            $this->providers[$provider->id()] = $provider;
        }
    }

    public function get(string $id): Provider
    {
        return $this->providers[$id] ?? throw new InvalidArgumentException("Unknown provider '{$id}'.");
    }

    public function aws(): AwsProvider
    {
        return $this->providers['aws'];
    }

    public function hetzner(): HetznerProvider
    {
        return $this->providers['hetzner'];
    }

    public function has(string $id): bool
    {
        return isset($this->providers[$id]);
    }

    /** @return array<string, Provider> */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * The providers an organisation can use now: Custom always, an api provider
     * once its key is connected. Asking also refreshes a provider's regions and
     * sizes when the kept copy has run out (CatalogCache).
     *
     * @return array<string, Provider>
     */
    public function connected(string $organizationId): array
    {
        $connected = array_filter($this->providers, fn (Provider $p) => $p->method() === 'ssh' || $this->credentials->has($organizationId, $p->id()));
        // The first look after a day (or ever) reads the provider's current regions and sizes; every other one is the kept copy.
        foreach ($connected as $provider) {
            app(CatalogCache::class)->ensure($provider, $organizationId);
        }

        return $connected;
    }
}
