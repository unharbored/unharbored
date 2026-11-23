<?php

declare(strict_types=1);

namespace App\Lib\Providers\DigitalOcean;

use App\Lib\Audit\Actor;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Provider;
use App\Lib\Providers\ServerDriver;
use App\Lib\Providers\HasCachedCatalog;
use App\Lib\Providers\LiveCatalog;
use Illuminate\Support\Facades\Cache;

final class DigitalOceanProvider implements LiveCatalog, Provider
{
    use HasCachedCatalog;

    public const REGIONS = ['nyc3' => 'New York 3', 'sfo3' => 'San Francisco 3', 'tor1' => 'Toronto 1', 'lon1' => 'London 1', 'ams3' => 'Amsterdam 3'];

    /** Droplet sizes offered, with DigitalOcean's monthly list price. */
    public const SIZES = [
        's-1vcpu-1gb' => ['label' => '1 vCPU · 1 GB', 'vcpu' => 1, 'memory_gb' => 1, 'price' => 6],
        's-1vcpu-2gb' => ['label' => '1 vCPU · 2 GB', 'vcpu' => 1, 'memory_gb' => 2, 'price' => 12],
        's-2vcpu-4gb' => ['label' => '2 vCPU · 4 GB', 'vcpu' => 2, 'memory_gb' => 4, 'price' => 24],
        's-4vcpu-8gb' => ['label' => '4 vCPU · 8 GB', 'vcpu' => 4, 'memory_gb' => 8, 'price' => 48],
    ];

    public function __construct(private readonly CredentialStore $credentials) {}

    public function id(): string
    {
        return 'digitalocean';
    }

    public function label(): string
    {
        return 'DigitalOcean';
    }

    public function method(): string
    {
        return 'api';
    }

    public function supplies(): array
    {
        return ['servers', 'edge', 'object_storage', 'private_network', 'database:pg', 'database:mysql', 'database:valkey'];
    }

    protected function fallbackCatalog(): Catalog
    {
        return new Catalog(self::REGIONS, self::SIZES, 'nyc3', 'ubuntu-24-04-x64');
    }

    /** Basic (shared CPU) droplet sizes on sale now with their monthly price, and the regions open for new droplets. */
    public function fetchCatalog(string $organizationId): Catalog
    {
        $client = $this->credentials->clientFor($organizationId, 'Reading DigitalOcean regions and sizes', null);
        $regions = [];
        foreach ($client->get('regions', ['per_page' => 200])['regions'] ?? [] as $r) {
            if ($r['available'] ?? false) {
                $regions[(string) $r['slug']] = (string) $r['name'];
            }
        }
        $sizes = [];
        foreach ($client->get('sizes', ['per_page' => 200])['sizes'] ?? [] as $s) {
            $gb = round(((int) $s['memory']) / 1024, 1);
            if (($s['available'] ?? false) && str_starts_with((string) $s['slug'], 's-') && $gb >= 1 && $gb <= 64) {
                $sizes[(string) $s['slug']] = ['label' => $this->sizeLabel((int) $s['vcpus'], $gb), 'vcpu' => (int) $s['vcpus'], 'memory_gb' => $gb, 'price' => (float) $s['price_monthly']];
            }
        }

        return $this->liveCatalog($regions, $sizes);
    }

    /**
     * The sizes this region sells right now, which the fixed list above cannot know: DigitalOcean refuses a droplet
     * whose size is not offered where it is asked for. Null when DigitalOcean cannot be asked, so nothing is blocked on a guess.
     *
     * @return list<string>|null
     */
    public function availableSizes(string $organizationId, string $region): ?array
    {
        try {
            $sizes = Cache::remember("do-sizes:{$organizationId}", 3600, fn () => $this->credentials->clientFor($organizationId, 'Checking which sizes a region offers', null)->get('sizes', ['per_page' => 200])['sizes'] ?? []);
        } catch (\Throwable) {
            return null;
        }
        $slugs = [];
        foreach ($sizes as $size) {
            if (($size['available'] ?? false) && in_array($region, $size['regions'] ?? [], true)) {
                $slugs[] = (string) $size['slug'];
            }
        }

        return $slugs === [] ? null : $slugs;
    }

    public function servers(string $organizationId, ?Actor $actor = null, string $reason = '', string $region = ''): ServerDriver
    {
        return new DigitalOceanServers($this->credentials->clientFor($organizationId, $reason ?: 'Managing servers', $actor));
    }

    public function verify(string $organizationId, ?Actor $actor = null): array
    {
        if (! $this->credentials->verify($organizationId, $actor)) {
            throw new \RuntimeException('DigitalOcean rejected the token. It is now marked revoked.');
        }

        return ['Arn' => (string) (\Illuminate\Support\Facades\DB::table('cloud_credentials')->where('organization_id', $organizationId)->where('provider', 'digitalocean')->value('account_email') ?? 'verified')];
    }
}
