<?php

declare(strict_types=1);

namespace App\Lib\Providers\Vultr;

use App\Lib\Audit\Actor;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\HasCachedCatalog;
use App\Lib\Providers\LiveCatalog;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\TokenApiClient;
use App\Lib\Providers\TokenApiProvider;

/**
 * Vultr Cloud Compute: servers only, so single-server applications with local services. The credential is one
 * API key in the vault. Vultr can limit a key to listed addresses (Account, API, Access Control): the control
 * plane's address must be on that list.
 *
 * Regions and plans are read from Vultr's API and kept for a day (CatalogCache); the lists below are only what is
 * used until the first read, or when Vultr cannot be asked. The image is Ubuntu 24.04 LTS x64, which Vultr numbers 2284.
 */
final class VultrProvider extends TokenApiProvider implements LiveCatalog
{
    use HasCachedCatalog;

    public const PROVIDER = 'vultr';

    public const REGIONS = ['ewr' => 'New Jersey', 'ord' => 'Chicago', 'dfw' => 'Dallas', 'sea' => 'Seattle', 'lax' => 'Los Angeles', 'atl' => 'Atlanta', 'mia' => 'Miami',
        'ams' => 'Amsterdam', 'lhr' => 'London', 'fra' => 'Frankfurt', 'cdg' => 'Paris', 'sgp' => 'Singapore', 'nrt' => 'Tokyo', 'syd' => 'Sydney'];

    /** Approximate monthly prices in dollars. */
    public const SIZES = [
        'vc2-1c-1gb' => ['label' => '1 vCPU · 1 GB', 'vcpu' => 1, 'memory_gb' => 1, 'price' => 5],
        'vc2-1c-2gb' => ['label' => '1 vCPU · 2 GB', 'vcpu' => 1, 'memory_gb' => 2, 'price' => 10],
        'vc2-2c-4gb' => ['label' => '2 vCPU · 4 GB', 'vcpu' => 2, 'memory_gb' => 4, 'price' => 20],
        'vc2-4c-8gb' => ['label' => '4 vCPU · 8 GB', 'vcpu' => 4, 'memory_gb' => 8, 'price' => 40],
    ];

    public function id(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'Vultr';
    }

    protected function fallbackCatalog(): Catalog
    {
        return new Catalog(self::REGIONS, self::SIZES, 'ewr', '2284');
    }

    /** Cloud Compute plans (vc2) and regions, both public lists. */
    public function fetchCatalog(string $organizationId): Catalog
    {
        $client = $this->client($this->token($organizationId, 'Reading Vultr regions and plans', null));
        $regions = [];
        foreach ($client->get('/regions', ['per_page' => 500])['regions'] ?? [] as $r) {
            $regions[(string) $r['id']] = (string) ($r['city'] ?? $r['id']);
        }
        $sizes = [];
        foreach ($client->get('/plans', ['type' => 'vc2', 'per_page' => 500])['plans'] ?? [] as $plan) {
            $gb = round(((int) $plan['ram']) / 1024, 1);
            if ($gb >= 1 && $gb <= 64) {
                $sizes[(string) $plan['id']] = ['label' => $this->sizeLabel((int) $plan['vcpu_count'], $gb), 'vcpu' => (int) $plan['vcpu_count'], 'memory_gb' => $gb, 'price' => (float) $plan['monthly_cost']];
            }
        }

        return $this->liveCatalog($regions, $sizes);
    }

    public function servers(string $organizationId, ?Actor $actor = null, string $reason = '', string $region = ''): ServerDriver
    {
        return new VultrServers($this->client($this->token($organizationId, $reason, $actor)));
    }

    protected function client(string $token): TokenApiClient
    {
        return new VultrClient($this->http, $token);
    }

    protected function check(TokenApiClient $client): void
    {
        $client->get('/account');
    }
}
