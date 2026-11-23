<?php

declare(strict_types=1);

namespace App\Lib\Providers\Linode;

use App\Lib\Audit\Actor;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\HasCachedCatalog;
use App\Lib\Providers\LiveCatalog;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\TokenApiClient;
use App\Lib\Providers\TokenApiProvider;

/**
 * Linode (Akamai Connected Cloud): servers only, so single-server applications with local services. The
 * credential is one personal access token in the vault with read and write on Linodes.
 *
 * Regions and plans are read from Linode's API and kept for a day (CatalogCache); the lists below are only what is
 * used until the first read, or when Linode cannot be asked. The first-boot script goes in through Linode's
 * metadata service, so only regions that have it are offered.
 */
final class LinodeProvider extends TokenApiProvider implements LiveCatalog
{
    use HasCachedCatalog;

    public const PROVIDER = 'linode';

    public const REGIONS = ['us-east' => 'Newark', 'us-central' => 'Dallas', 'us-west' => 'Fremont', 'us-southeast' => 'Atlanta', 'us-ord' => 'Chicago',
        'eu-west' => 'London', 'eu-central' => 'Frankfurt', 'ap-south' => 'Singapore', 'ap-southeast' => 'Sydney'];

    /** Approximate monthly prices in dollars. */
    public const SIZES = [
        'g6-nanode-1' => ['label' => '1 vCPU · 1 GB', 'vcpu' => 1, 'memory_gb' => 1, 'price' => 5],
        'g6-standard-1' => ['label' => '1 vCPU · 2 GB', 'vcpu' => 1, 'memory_gb' => 2, 'price' => 12],
        'g6-standard-2' => ['label' => '2 vCPU · 4 GB', 'vcpu' => 2, 'memory_gb' => 4, 'price' => 24],
        'g6-standard-4' => ['label' => '4 vCPU · 8 GB', 'vcpu' => 4, 'memory_gb' => 8, 'price' => 48],
    ];

    public function id(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'Linode';
    }

    protected function fallbackCatalog(): Catalog
    {
        return new Catalog(self::REGIONS, self::SIZES, 'us-east', 'linode/ubuntu24.04');
    }

    /** Shared-CPU plans (nanode and standard) and the regions that run, and have the metadata service. */
    public function fetchCatalog(string $organizationId): Catalog
    {
        $client = $this->client($this->token($organizationId, 'Reading Linode regions and plans', null));
        $regions = [];
        foreach ($client->get('/regions', ['page_size' => 500])['data'] ?? [] as $r) {
            if (($r['status'] ?? 'ok') === 'ok' && in_array('Metadata', $r['capabilities'] ?? [], true)) {
                $regions[(string) $r['id']] = (string) ($r['label'] ?? $r['id']);
            }
        }
        $sizes = [];
        foreach ($client->get('/linode/types', ['page_size' => 500])['data'] ?? [] as $type) {
            $gb = round(((int) $type['memory']) / 1024, 1);
            if (in_array($type['class'] ?? '', ['nanode', 'standard'], true) && $gb >= 1 && $gb <= 64) {
                $sizes[(string) $type['id']] = ['label' => $this->sizeLabel((int) $type['vcpus'], $gb), 'vcpu' => (int) $type['vcpus'], 'memory_gb' => $gb, 'price' => (float) ($type['price']['monthly'] ?? 0)];
            }
        }

        return $this->liveCatalog($regions, $sizes);
    }

    public function servers(string $organizationId, ?Actor $actor = null, string $reason = '', string $region = ''): ServerDriver
    {
        return new LinodeServers($this->client($this->token($organizationId, $reason, $actor)));
    }

    protected function client(string $token): TokenApiClient
    {
        return new LinodeClient($this->http, $token);
    }

    protected function check(TokenApiClient $client): void
    {
        $client->get('/profile');
    }
}
