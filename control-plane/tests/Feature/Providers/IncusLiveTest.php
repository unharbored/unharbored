<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Cloud\CredentialStore;
use App\Lib\Providers\Incus\IncusProvider;
use App\Lib\Providers\ServerSpec;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Against a real Incus host, skipped unless given a fresh connection string from the setup script:
 *
 *   INCUS_CONNECTION='uh-incus:…' php artisan test --filter=IncusLiveTest
 *
 * It proves what the faked tests cannot: the client certificate, the pinned host key, and a container that is really
 * created with its own IPv6 address, found, listed and destroyed. Everything it makes is removed again; the test
 * database is the in-memory one, so nothing is kept here either. The key it enrolled stays trusted on the host until
 * removed there (incus config trust list).
 */
final class IncusLiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_create_find_list_and_destroy_on_a_real_host(): void
    {
        $connection = (string) getenv('INCUS_CONNECTION');
        if ($connection === '') {
            $this->markTestSkipped('Set INCUS_CONNECTION to a connection string from the setup script.');
        }
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        foreach ([KeyManager::class, Vault::class, CredentialStore::class] as $c) {
            $this->app->forgetInstance($c);
        }
        DB::table('organizations')->insert(['id' => 'org-live', 'name' => 'Live', 'created_at' => now(), 'updated_at' => now()]);
        $incus = new IncusProvider($this->app->make(CredentialStore::class), $this->app->make(HttpFactory::class));

        $incus->connect('org-live', $connection);
        $this->assertStringContainsString('Incus', (string) DB::table('cloud_credentials')->where('provider', 'incus')->value('account_email'));
        $this->assertArrayHasKey('Arn', $incus->verify('org-live'));
        $this->assertNotNull($incus->capacity('org-live'));

        $driver = $incus->servers('org-live');
        $name = 'live-'.substr(bin2hex(random_bytes(3)), 0, 6);
        try {
            $driver->create(new ServerSpec(name: $name, region: 'host', size: 'small', image: 'ubuntu/24.04/cloud',
                userData: "#cloud-config\nruncmd:\n  - echo live-ok > /root/live-proof\n", idempotencyKey: $name, tags: ['cloud-manager', 'org:org-live']));
            $server = $driver->find($name);
            $this->assertSame('running', $server?->state);
            $this->assertStringStartsWith(str_replace('::/64', ':1:', IncusProvider::parse($connection)['ipv6_prefix']), (string) $server->ipv6);
            $this->assertContains($name, array_map(fn ($s) => $s->id, $driver->listTagged('org:org-live')));
            fwrite(STDERR, "\n  created {$name} at {$server->ipv6}\n");
        } finally {
            $driver->destroy($name);
        }
        $this->assertNull($driver->find($name));

        // The ingress: Incus must accept NAT proxy devices on the host's ports from our restricted key.
        try {
            $driver->create(new ServerSpec(name: 'incus-ingress', region: 'host', size: 'small', image: 'ubuntu/24.04/cloud',
                userData: "#cloud-config\n", idempotencyKey: 'ingress', tags: ['cloud-manager', 'org:org-live'], extra: ['role' => 'ingress']));
            $ingress = $driver->find('incus-ingress');
            $this->assertSame('running', $ingress?->state);
            $this->assertSame(parse_url((string) $incus->verify('org-live')['Arn'], PHP_URL_HOST) ?: $ingress->ipv4, $ingress->ipv4, 'The ingress answers on the host\'s IPv4.');
            fwrite(STDERR, "  ingress at {$ingress->ipv4} (IPv4, ports 80 and 443) and {$ingress->ipv6}\n");
        } finally {
            $driver->destroy('incus-ingress');
        }
    }
}
