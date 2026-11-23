<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Incus\IncusClient;
use App\Lib\Providers\Incus\IncusProvider;
use App\Lib\Providers\Incus\IncusServers;
use App\Lib\Providers\Incus\Ingress;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerSpec;
use App\Lib\Servers\Firewall;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** The IPv4 ingress on an Incus host: one container that shares the host's IPv4 among every server on it, by name. */
final class IncusIngressTest extends TestCase
{
    use RefreshDatabase;

    private const INGRESS_V6 = '2001:db8:5:0:1::a';

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        foreach ([KeyManager::class, Vault::class, CredentialStore::class] as $c) {
            $this->app->forgetInstance($c);
        }
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Test Org', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** @param array<string, mixed> $attributes */
    private function server(string $slug, string $provider, array $attributes): void
    {
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => $slug, 'provider' => $provider, 'external_id' => $slug,
            'state' => 'active', 'desired_state' => 'active', 'attributes' => json_encode($attributes), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function ingressUp(): void
    {
        $this->server(Ingress::SLUG, 'incus', ['ipv4' => '203.0.113.5', 'ipv6' => self::INGRESS_V6]);
        DB::table('nodes')->insert(['node_id' => Ingress::nodeId('org-1'), 'organization_id' => 'org-1', 'role' => 'ingress', 'certificate_pem' => 'x',
            'certificate_expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog);
    }

    public function test_routes_list_every_domain_on_the_host_by_its_servers_ipv6_and_nothing_else(): void
    {
        $this->ingressUp();
        $this->server('web-2', 'incus', ['ipv6' => '2001:db8:5:0:1::2']);
        $this->server('do-1', 'digitalocean', ['ipv4' => '198.51.100.1']);
        $this->sites()->create('org-1', 'web-2.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $this->sites()->create('org-1', 'do-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');

        $this->assertSame(['shop.example.com' => '2001:db8:5:0:1::2'], Ingress::routes('org-1'));
    }

    public function test_the_recipe_installs_the_stream_module_before_the_config_that_needs_it_and_checks_it(): void
    {
        $recipe = Ingress::recipe(['shop.example.com' => '2001:db8:5:0:1::2']);
        $ids = array_column($recipe['steps'], 'id');

        $this->assertLessThan(array_search('ingress-stream', $ids, true), array_search('ingress-stream-module', $ids, true));
        $this->assertLessThan(array_search('ingress-reload', $ids, true), array_search('ingress-check', $ids, true));
        $files = array_column(array_filter($recipe['steps'], fn ($s) => $s['type'] === 'file'), 'content_b64', 'path');
        $stream = base64_decode($files['/etc/nginx/modules-enabled/90-ingress-stream.conf']);
        $http = base64_decode($files['/etc/nginx/conf.d/90-ingress-http.conf']);
        $this->assertStringContainsString('shop.example.com [2001:db8:5:0:1::2]:10443;', $stream);
        $this->assertStringContainsString('proxy_protocol on;', $stream);
        $this->assertStringContainsString('ssl_preread on;', $stream);
        $this->assertStringContainsString('shop.example.com "[2001:db8:5:0:1::2]";', $http);
        $this->assertStringContainsString('return 444;', $http, 'A name the host does not serve is dropped.');
    }

    public function test_a_server_behind_the_ingress_takes_https_on_10443_and_believes_only_the_ingress(): void
    {
        $this->ingressUp();
        $this->server('web-2', 'incus', ['ipv6' => '2001:db8:5:0:1::2']);
        $site = $this->sites()->markTls($this->sites()->create('org-1', 'web-2.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git'));

        $vhost = $this->sites()->generatedVhost($site);
        $this->assertStringContainsString('listen [::]:10443 ssl http2 proxy_protocol;', $vhost);
        $this->assertStringContainsString('set_real_ip_from '.self::INGRESS_V6.";\n    real_ip_header proxy_protocol;", $vhost);
        $this->assertStringContainsString('set_real_ip_from '.self::INGRESS_V6.";\n    real_ip_header X-Forwarded-For;", $vhost);
        $this->assertSame(1, substr_count($vhost, 'real_ip_header proxy_protocol'));
        $this->assertStringContainsString('listen 443 ssl http2;', $vhost, 'Visitors with IPv6 still come straight in.');
    }

    public function test_a_server_elsewhere_is_unchanged(): void
    {
        $this->ingressUp();
        $this->server('do-1', 'digitalocean', ['ipv4' => '198.51.100.1']);
        $site = $this->sites()->markTls($this->sites()->create('org-1', 'do-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git'));

        $vhost = $this->sites()->generatedVhost($site);
        $this->assertStringNotContainsString('10443', $vhost);
        $this->assertStringNotContainsString('set_real_ip_from', $vhost);
    }

    public function test_names_point_at_the_hosts_ipv4_once_the_ingress_is_up_and_at_the_servers_ipv6_before(): void
    {
        $this->server('web-2', 'incus', ['ipv6' => '2001:db8:5:0:1::2']);
        $this->assertSame('2001:db8:5:0:1::2', NewApplications::publicIp('org-1', 'web-2.org-1'));

        $this->ingressUp();
        $this->assertSame('203.0.113.5', NewApplications::publicIp('org-1', 'web-2.org-1'));
        $this->assertSame('shop.203-0-113-5.sslip.io', NewApplications::sslipDomain('shop', (string) NewApplications::publicIp('org-1', 'web-2.org-1')));
    }

    public function test_the_firewall_lets_the_ingress_in_on_80_and_10443_only_from_its_address(): void
    {
        $recipe = Firewall::recipe(['enabled' => true, 'ssh' => [], 'web' => ['192.0.2.0/24'], 'ingress' => self::INGRESS_V6]);
        $rules = array_column(array_filter($recipe['steps'], fn ($s) => str_starts_with($s['id'], 'ufw-ingress-')), 'args');

        $this->assertSame([['allow', 'from', self::INGRESS_V6, 'to', 'any', 'port', '80', 'proto', 'tcp'], ['allow', 'from', self::INGRESS_V6, 'to', 'any', 'port', '10443', 'proto', 'tcp']], $rules);
    }

    public function test_the_ingress_container_owns_the_hosts_ports_through_nat_proxy_devices(): void
    {
        $sent = [];
        $driver = fn (?string $hostIpv4) => new IncusServers(new IncusClient($this->app->make(HttpFactory::class), 'https://203.0.113.5:8443', 'unharbored', 'cert', 'key', self::certificate()),
            '2001:db8:5::/64', 'eth0', IncusProvider::SIZES, $hostIpv4);
        $spec = new ServerSpec(name: 'incus-ingress', region: 'host', size: 'small', image: 'ubuntu/24.04/cloud', userData: '', idempotencyKey: 'k', extra: ['role' => 'ingress']);

        Http::fake(function (Request $r) use (&$sent) {
            $sent[] = $r;
            $path = (string) parse_url($r->url(), PHP_URL_PATH);

            return match ($r->method().' '.$path) {
                'GET /1.0/networks/incusbr0/state' => Http::response(['metadata' => ['addresses' => [['family' => 'inet6', 'address' => 'fe80::1', 'netmask' => '64'], ['family' => 'inet', 'address' => '10.160.133.1', 'netmask' => '24']]]]),
                'POST /1.0/instances' => Http::response(['type' => 'sync', 'metadata' => []]),
                default => Http::response(['error' => 'not found'], 404),
            };
        });
        $driver('203.0.113.5')->create($spec);
        $devices = collect($sent)->first(fn (Request $r) => $r->method() === 'POST')->data()['devices'];
        $this->assertSame(['type' => 'nic', 'name' => 'eth0', 'network' => 'incusbr0', 'ipv4.address' => '10.160.133.250'], $devices['eth0']);
        $this->assertSame(['type' => 'proxy', 'listen' => 'tcp:203.0.113.5:443', 'connect' => 'tcp:10.160.133.250:443', 'nat' => 'true'], $devices['https']);
        $this->assertSame('tcp:203.0.113.5:80', $devices['http']['listen']);

        $this->expectException(Rejected::class);
        $driver(null)->create($spec);
    }

    public function test_connecting_incus_sets_the_ingress_up_once(): void
    {
        $runner = $this->app->make(OperationRunner::class);
        $ingress = new Ingress($runner);

        $this->assertNotNull($ingress->ensure('org-1', 'matt@example.com'));
        $this->assertNull($ingress->ensure('org-1', 'matt@example.com'), 'One is already on its way.');
        $op = DB::table('operations')->where('node_id', Ingress::nodeId('org-1'))->sole();
        $this->assertSame(['provision', 'incus', 'ingress'], [$op->kind, json_decode($op->meta, true)['spec']['provider'], json_decode($op->meta, true)['spec']['role']]);
        $this->assertNull($ingress->queueSync('org-1', 'test', 'system'), 'Nothing to push to until the ingress is up.');
    }

    public function test_a_destroyed_ingress_can_be_set_up_again_and_its_record_is_kept(): void
    {
        $this->server(Ingress::SLUG, 'incus', ['ipv6' => self::INGRESS_V6]);
        DB::table('resources')->where('slug', Ingress::SLUG)->update(['state' => 'deleted']);

        $this->assertNotNull((new Ingress($this->app->make(\App\Lib\Operations\OperationRunner::class)))->ensure('org-1', 'matt@example.com'));
        $this->assertSame(1, DB::table('resources')->where('slug', 'like', Ingress::SLUG.'-deleted-%')->where('state', 'deleted')->count());
    }

    public function test_a_new_name_routes_through_the_ingress_before_its_certificate_is_asked_for(): void
    {
        $calls = [];
        $http = new HttpFactory;
        $http->fake(function (Request $r) use ($http, &$calls) {
            preg_match('#/v1/nodes/([^/]+)/commands#', $r->url(), $m);
            $calls[] = ['node' => $m[1] ?? null, 'spec' => $r['args']['spec'] ?? null];

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(\App\Lib\Gateway\GatewayClient::class, new \App\Lib\Gateway\GatewayClient('http://gw.test', 't', $http, new AuditLog));
        $deployer = $this->app->make(\App\Lib\Sites\Deployer::class);
        $this->server('web-2', 'incus', ['ipv6' => '2001:db8:5:0:1::2']);
        $site = $this->sites()->create('org-1', 'web-2.org-1', 'shop', 'shop.85-155-187-30.sslip.io', 'git@github.com:acme/shop.git');

        $this->assertNull($deployer->syncIngress($site), 'No ingress up yet: nothing to push to.');
        $this->ingressUp();
        $this->assertSame(['shop.85-155-187-30.sslip.io' => '2001:db8:5:0:1::2'], $deployer->syncIngress($site));
        $this->assertSame(Ingress::nodeId('org-1'), $calls[0]['node']);
        $this->assertSame('ingress', $calls[0]['spec']['name']);
    }

    public function test_the_ingress_is_never_offered_as_a_server_for_an_application(): void
    {
        $this->ingressUp();
        DB::table('nodes')->where('node_id', Ingress::nodeId('org-1'))->update(['provider' => 'incus']);
        DB::table('nodes')->insert(['node_id' => 'web-2.org-1', 'organization_id' => 'org-1', 'provider' => 'incus', 'certificate_pem' => 'x',
            'certificate_expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['web-2'], array_column(NewApplications::servers('org-1', 'incus'), 'name'));
    }

    private static function certificate(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'ec' => ['curve_name' => 'prime256v1']]);
        openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'incus-cp'], $key), null, $key, 30), $pem);

        return $pem;
    }
}
