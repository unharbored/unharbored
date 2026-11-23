<?php

declare(strict_types=1);

namespace App\Lib\Providers\Aws;

use App\Lib\Audit\Actor;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Provider;
use App\Lib\Providers\ServerDriver;
use Illuminate\Http\Client\Factory as HttpFactory;

/**
 * AWS, or anything that speaks its API at a URL you set (LocalStack and the
 * like). Servers only for now: EC2 machines with local services, the same
 * single-server shape as Custom. The credential is one vault secret:
 * access_key_id, secret_access_key, region, and optionally endpoint and ami.
 */
final class AwsProvider implements Provider
{
    public const PROVIDER = 'aws';

    public const REGIONS = [
        'us-east-1' => 'N. Virginia', 'us-east-2' => 'Ohio', 'us-west-2' => 'Oregon',
        'eu-west-1' => 'Ireland', 'eu-central-1' => 'Frankfurt', 'ap-southeast-2' => 'Sydney',
    ];

    /** t3 sizes with the approximate us-east-1 on-demand monthly price. */
    public const SIZES = [
        't3.micro' => ['label' => '2 vCPU · 1 GB', 'vcpu' => 2, 'memory_gb' => 1, 'price' => 8],
        't3.small' => ['label' => '2 vCPU · 2 GB', 'vcpu' => 2, 'memory_gb' => 2, 'price' => 15],
        't3.medium' => ['label' => '2 vCPU · 4 GB', 'vcpu' => 2, 'memory_gb' => 4, 'price' => 30],
        't3.large' => ['label' => '2 vCPU · 8 GB', 'vcpu' => 2, 'memory_gb' => 8, 'price' => 61],
    ];

    /** Canonical's public SSM parameter for the current Ubuntu 24.04 AMI. */
    public const AMI_PARAMETER = '/aws/service/canonical/ubuntu/server/24.04/stable/current/amd64/hvm/ebs-gp3/ami-id';

    public function __construct(private readonly CredentialStore $credentials, private readonly HttpFactory $http) {}

    public function id(): string
    {
        return self::PROVIDER;
    }

    public function label(): string
    {
        return 'AWS';
    }

    public function method(): string
    {
        return 'api';
    }

    public function supplies(): array
    {
        return ['servers', 'private_network'];
    }

    public function catalog(): Catalog
    {
        return new Catalog(self::REGIONS, self::SIZES, 'us-east-1', 'ubuntu-24.04');
    }

    public function servers(string $organizationId, ?Actor $actor = null, string $reason = '', string $region = ''): ServerDriver
    {
        $secret = $this->credentials->secret($organizationId, self::PROVIDER, $reason ?: 'Managing servers', $actor);

        return new AwsServers($this->client($secret, $region), $secret['ami'] ?? null);
    }

    /**
     * Check a key with AWS, or the service at the endpoint, and keep it only if
     * accepted; an earlier key stays until then.
     *
     * @param  array{access_key_id: string, secret_access_key: string, region?: string, endpoint?: string, ami?: string}  $fields
     * @return array{Account: string, Arn: string, UserId: string}
     *
     * @throws \InvalidArgumentException
     */
    public function connect(string $organizationId, #[\SensitiveParameter] array $fields, ?Actor $actor = null): array
    {
        $secret = array_filter(array_map('trim', $fields), fn ($v) => $v !== '');
        if (($secret['endpoint'] ?? '') !== '' && ! preg_match('#^https?://[^\s/]+#', $secret['endpoint'])) {
            throw new \InvalidArgumentException('The endpoint must be a URL, such as http://localhost:4566.');
        }
        $secret += ['region' => 'us-east-1'];

        try {
            $who = $this->client($secret, '')->whoAmI();
        } catch (\App\Lib\Cloud\Exceptions\CloudException $e) {
            throw new \InvalidArgumentException('The provider did not accept that key: '.$e->getMessage());
        }

        $id = (string) $secret['access_key_id'];
        $this->credentials->connectSecret($organizationId, self::PROVIDER, $secret, substr($id, 0, 4).'••••••••'.substr($id, -4), actor: $actor,
            account: ['id' => $who['Account'], 'label' => $who['Arn'].' · '.$secret['region'].(($secret['endpoint'] ?? '') !== '' ? ' · '.$secret['endpoint'] : '')]);

        return $who;
    }

    /** Whether the stored credential still works; the account it belongs to. */
    public function verify(string $organizationId, ?Actor $actor = null): array
    {
        $secret = $this->credentials->secret($organizationId, self::PROVIDER, 'Verifying credential', $actor);

        return $this->client($secret, '')->whoAmI();
    }

    /**
     * @param  array<string, string>  $secret
     */
    public function client(array $secret, string $region): AwsClient
    {
        return new AwsClient(
            $this->http,
            (string) $secret['access_key_id'],
            (string) $secret['secret_access_key'],
            $region !== '' ? $region : (string) ($secret['region'] ?? 'us-east-1'),
            ($secret['endpoint'] ?? '') !== '' ? (string) $secret['endpoint'] : null,
        );
    }
}
