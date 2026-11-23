<?php

declare(strict_types=1);

namespace App\Lib\Providers\Hetzner;

use App\Lib\Audit\Actor;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\HasCachedCatalog;
use App\Lib\Providers\LiveCatalog;
use App\Lib\Providers\Provider;
use App\Lib\Providers\ServerDriver;
use Illuminate\Http\Client\Factory as HttpFactory;
use InvalidArgumentException;

/**
 * Hetzner Cloud: servers and a private network, nothing else we drive yet. So
 * single-server applications only, with local services, until a Hetzner
 * balancer and object storage have drivers. The credential is one API token
 * (read and write) in the vault.
 *
 * Server types are renamed from time to time. The sizes below are the ones
 * offered; a type Hetzner has retired is refused by the API with its reason.
 */
final class HetznerProvider implements LiveCatalog, Provider
{
    use HasCachedCatalog;

    public const PROVIDER = 'hetzner';

    public const REGIONS = ['nbg1' => 'Nuremberg', 'fsn1' => 'Falkenstein', 'hel1' => 'Helsinki', 'ash' => 'Ashburn', 'hil' => 'Hillsboro', 'sin' => 'Singapore'];

    /** Approximate monthly prices in dollars. */
    public const SIZES = [
        'cx23' => ['label' => '2 vCPU · 4 GB', 'vcpu' => 2, 'memory_gb' => 4, 'price' => 4],
        'cx33' => ['label' => '4 vCPU · 8 GB', 'vcpu' => 4, 'memory_gb' => 8, 'price' => 7],
        'cx43' => ['label' => '8 vCPU · 16 GB', 'vcpu' => 8, 'memory_gb' => 16, 'price' => 12],
        'cx53' => ['label' => '16 vCPU · 32 GB', 'vcpu' => 16, 'memory_gb' => 32, 'price' => 22],
    ];

    public function __construct(private readonly CredentialStore $credentials, private readonly HttpFactory $http) {}

    public function id(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'Hetzner';
    }

    public function method(): string
    {
        return 'api';
    }

    public function supplies(): array
    {
        return ['servers', 'private_network'];
    }

    protected function fallbackCatalog(): Catalog
    {
        return new Catalog(self::REGIONS, self::SIZES, 'nbg1', 'ubuntu-24.04');
    }

    /** Current shared-CPU x86 server types with their list price, and the locations. */
    public function fetchCatalog(string $organizationId): Catalog
    {
        $secret = $this->credentials->secret($organizationId, self::PROVIDER, 'Reading Hetzner locations and server types', null);
        $client = new HetznerClient($this->http, (string) $secret['token']);
        $regions = [];
        foreach ($client->get('/locations')['locations'] ?? [] as $l) {
            $regions[(string) $l['name']] = (string) ($l['city'] ?? $l['name']);
        }
        $sizes = [];
        foreach ($client->get('/server_types', ['per_page' => 50])['server_types'] ?? [] as $t) {
            if (($t['architecture'] ?? '') === 'x86' && ($t['cpu_type'] ?? '') === 'shared' && empty($t['deprecated']) && ! empty($t['prices'])) {
                $sizes[(string) $t['name']] = ['label' => $this->sizeLabel((int) $t['cores'], (float) $t['memory']), 'vcpu' => (int) $t['cores'], 'memory_gb' => (float) $t['memory'],
                    'price' => round((float) ($t['prices'][0]['price_monthly']['gross'] ?? 0), 2)];
            }
        }

        return $this->liveCatalog($regions, $sizes);
    }

    public function servers(string $organizationId, ?Actor $actor = null, string $reason = '', string $region = ''): ServerDriver
    {
        $secret = $this->credentials->secret($organizationId, self::PROVIDER, $reason ?: 'Managing servers', $actor);

        return new HetznerServers(new HetznerClient($this->http, (string) $secret['token']));
    }

    public function verify(string $organizationId, ?Actor $actor = null): array
    {
        $secret = $this->credentials->secret($organizationId, self::PROVIDER, 'Verifying credential', $actor);
        (new HetznerClient($this->http, (string) $secret['token']))->get('/servers', ['per_page' => 1]);

        return ['Arn' => 'Hetzner project, token '.substr((string) $secret['token'], 0, 4).'••••'];
    }

    /**
     * Check a token with Hetzner and keep it only if accepted; an earlier token stays until then.
     *
     * @throws InvalidArgumentException
     */
    public function connect(string $organizationId, #[\SensitiveParameter] string $token, ?Actor $actor = null): void
    {
        $token = trim($token);
        try {
            (new HetznerClient($this->http, $token))->get('/servers', ['per_page' => 1]);
        } catch (\App\Lib\Cloud\Exceptions\CloudException $e) {
            throw new InvalidArgumentException('Hetzner did not accept that token: '.$e->getMessage());
        }
        $this->credentials->connectSecret($organizationId, self::PROVIDER, ['token' => $token], substr($token, 0, 4).'••••••••'.substr($token, -4), 'api_token', $actor,
            ['label' => 'Hetzner Cloud project']);
    }
}
