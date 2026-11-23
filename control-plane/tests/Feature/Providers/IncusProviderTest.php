<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Cloud\CredentialStore;
use App\Lib\Providers\Incus\IncusProvider;
use App\Lib\Providers\Incus\IncusServers;
use App\Lib\Providers\Providers;
use App\Lib\Providers\ServerSpec;
use App\Lib\Servers\ServersPage;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/** Incus on the team's own bare metal: connecting with the setup script's string, and servers with their own IPv6. */
final class IncusProviderTest extends TestCase
{
    use RefreshDatabase;

    /** @var \Closure(Request): mixed */
    private \Closure $respond;

    /** @var list<Request> */
    private array $sent = [];

    private string $hostCertificate;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        foreach ([KeyManager::class, Vault::class, CredentialStore::class, Providers::class] as $c) {
            $this->app->forgetInstance($c);
        }
        Queue::fake();
        $this->hostCertificate = self::certificate();
        $this->respond = fn (Request $r) => $this->host($r);
        Http::fake(function (Request $r) {
            $this->sent[] = $r;

            return ($this->respond)($r);
        });
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Test Org', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** A host that trusts the token and answers the reads connecting makes. */
    private function host(Request $r, array $overrides = []): mixed
    {
        $path = (string) parse_url($r->url(), PHP_URL_PATH);
        $answers = $overrides + [
            'POST /1.0/certificates' => ['type' => 'sync', 'metadata' => []],
            'GET /1.0' => ['metadata' => ['auth' => 'trusted', 'environment' => ['server_name' => 'incus-cp', 'server_version' => '6.0.5', 'driver' => 'lxc | qemu']]],
            'GET /1.0/projects/unharbored' => ['metadata' => ['name' => 'unharbored']],
            'GET /1.0/resources' => ['metadata' => ['cpu' => ['total' => 8], 'memory' => ['total' => 32 * 1024 ** 3]]],
            'GET /1.0/storage-pools/default/resources' => ['metadata' => ['space' => ['total' => 80 * 1024 ** 3, 'used' => 10 * 1024 ** 3]]],
            'GET /1.0/instances' => ['metadata' => [['name' => 'web-1', 'status' => 'Running', 'config' => ['limits.memory' => '2GiB']]]],
        ];
        $answer = $answers[$r->method().' '.$path] ?? null;

        return $answer instanceof PromiseInterface ? $answer : ($answer === null ? Http::response(['type' => 'error', 'error' => 'not found', 'error_code' => 404], 404) : Http::response($answer));
    }

    private static function certificate(): string
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'ec' => ['curve_name' => 'prime256v1']]);
        openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'incus-cp'], $key), null, $key, 30), $pem);

        return $pem;
    }

    /** What the setup script prints, for a host presenting $certificate. */
    private function connection(?string $certificate = null): string
    {
        $token = base64_encode((string) json_encode(['client_name' => 'unharbored-unharbored-1', 'fingerprint' => openssl_x509_fingerprint($certificate ?? $this->hostCertificate, 'sha256'),
            'addresses' => ['203.0.113.5:8443', '[2001:db8:5::1]:8443'], 'secret' => 'one-time-secret', 'expires_at' => '2026-11-21T21:00:00Z']));

        return 'uh-incus:'.base64_encode((string) json_encode(['token' => $token, 'ipv6_prefix' => '2001:db8:5::/64', 'parent' => 'eth0', 'project' => 'unharbored']));
    }

    /** The provider, with the host's certificate handed over instead of fetched. */
    private function incus(): IncusProvider
    {
        return new IncusProvider($this->app->make(CredentialStore::class), $this->app->make(HttpFactory::class), fn (string $address) => $this->hostCertificate);
    }

    private function connected(): IncusProvider
    {
        $incus = $this->incus();
        $incus->connect('org-1', $this->connection());
        $this->sent = [];

        return $incus;
    }

    private function spec(string $name = 'web-2'): ServerSpec
    {
        return new ServerSpec(name: $name, region: 'host', size: 'medium', image: 'ubuntu/24.04/cloud', userData: "#cloud-config\n", idempotencyKey: 'k1',
            tags: ['cloud-manager', 'org:org-1', 'resource:'.$name]);
    }

    public function test_a_string_that_is_not_from_the_setup_script_is_refused_before_anything_is_sent(): void
    {
        foreach (['', 'dop_v1_abc', 'uh-incus:not-base64!', 'uh-incus:'.base64_encode('{"token":"x"}')] as $bad) {
            try {
                $this->incus()->connect('org-1', $bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString("isn't an Incus connection string", $e->getMessage());
            }
        }
        $this->assertSame([], $this->sent);
    }

    public function test_connecting_spends_the_token_and_keeps_only_our_own_key_and_the_hosts_certificate(): void
    {
        $this->incus()->connect('org-1', $this->connection());

        $post = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST');
        $this->assertSame('https://203.0.113.5:8443/1.0/certificates?project=unharbored', $post->url());
        $this->assertSame('client', $post->data()['type']);
        $row = DB::table('cloud_credentials')->where('provider', 'incus')->sole();
        $this->assertSame(['203.0.113.5:8443', 'client_certificate'], [$row->key_hint, $row->kind]);
        $this->assertSame('incus-cp · project unharbored · 2001:db8:5::/64 · Incus 6.0.5 · 8 cores · 32 GB memory · containers and VMs', $row->account_email);

        $secret = $this->app->make(CredentialStore::class)->secret('org-1', 'incus', 'test');
        $this->assertSame(['https://203.0.113.5:8443', 'unharbored', '2001:db8:5::/64', 'eth0', $this->hostCertificate], [$secret['endpoint'], $secret['project'], $secret['ipv6_prefix'], $secret['parent'], $secret['server_cert']]);
        $this->assertStringContainsString('PRIVATE KEY', $secret['client_key']);
        $this->assertArrayNotHasKey('token', $secret, 'The single-use token is spent, not kept.');
    }

    public function test_a_host_presenting_another_certificate_is_not_trusted(): void
    {
        try {
            $this->incus()->connect('org-1', $this->connection(self::certificate()));
            $this->fail('Trusted a host with the wrong certificate.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('different certificate', $e->getMessage());
        }
        $this->assertSame([], $this->sent, 'Nothing, the token least of all, goes to a host whose certificate does not match.');
        $this->assertFalse(DB::table('cloud_credentials')->where('provider', 'incus')->exists());
    }

    public function test_a_spent_or_expired_token_is_explained_and_nothing_is_kept(): void
    {
        $this->respond = fn (Request $r) => $this->host($r, ['POST /1.0/certificates' => Http::response(['type' => 'error', 'error' => 'No matching certificate add operation found', 'error_code' => 403], 403)]);

        try {
            $this->incus()->connect('org-1', $this->connection());
            $this->fail('Kept a refused connection.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('used already or expired', $e->getMessage());
        }
        $this->assertFalse(DB::table('cloud_credentials')->where('provider', 'incus')->exists());
    }

    public function test_create_asks_for_a_container_with_a_routed_ipv6_card_whose_address_comes_from_its_name(): void
    {
        $driver = $this->connected()->servers('org-1');
        $this->respond = fn (Request $r) => $this->host($r, [
            'POST /1.0/instances' => Http::response(['type' => 'async', 'operation' => '/1.0/operations/op-1'], 202),
            'GET /1.0/operations/op-1/wait' => ['metadata' => ['status' => 'Success']],
        ]);

        $this->assertSame('web-2', $driver->create($this->spec()));

        $body = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST')->data();
        $address = IncusServers::addressFor('2001:db8:5::/64', 'web-2');
        $this->assertStringStartsWith('2001:db8:5:0:1:', $address);
        $this->assertSame(['type' => 'nic', 'nictype' => 'routed', 'parent' => 'eth0', 'ipv6.address' => $address], $body['devices']['eth1']);
        $this->assertSame('40GiB', $body['devices']['root']['size']);
        $this->assertSame(['2', '2GiB', 'cloud-manager,org:org-1,resource:web-2'], [$body['config']['limits.cpu'], $body['config']['limits.memory'], $body['config']['user.unharbored.tags']]);
        $this->assertSame(['container', 'ubuntu/24.04/cloud'], [$body['type'], $body['source']['alias']]);
        $this->assertNotNull(collect($this->sent)->first(fn (Request $r) => str_contains($r->url(), '/1.0/operations/op-1/wait')), 'Create waits for Incus to finish.');
    }

    public function test_a_retried_create_finds_the_server_by_name_instead_of_making_a_second(): void
    {
        $driver = $this->connected()->servers('org-1');
        $this->respond = fn (Request $r) => $this->host($r, ['GET /1.0/instances/web-2' => ['metadata' => ['name' => 'web-2', 'status' => 'Running']]]);

        $this->assertSame('web-2', $driver->create($this->spec()));
        $this->assertNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST'));
    }

    public function test_find_reports_state_and_ipv6_and_destroy_and_listing_work(): void
    {
        $driver = $this->connected()->servers('org-1');
        $instance = ['name' => 'web-2', 'status' => 'Running', 'config' => ['user.unharbored.tags' => 'cloud-manager,org:org-1'], 'devices' => ['eth1' => ['ipv6.address' => '2001:db8:5:0:1::9']]];
        $this->respond = fn (Request $r) => $this->host($r, [
            'GET /1.0/instances/web-2' => ['metadata' => $instance],
            'GET /1.0/instances' => ['metadata' => [$instance, ['name' => 'by-hand', 'status' => 'Stopped', 'config' => []]]],
            'PUT /1.0/instances/web-2/state' => Http::response(['type' => 'error', 'error' => 'The instance is already stopped', 'error_code' => 400], 400),
            'DELETE /1.0/instances/web-2' => Http::response(['type' => 'async', 'operation' => '/1.0/operations/op-2'], 202),
            'GET /1.0/operations/op-2/wait' => ['metadata' => ['status' => 'Success']],
        ]);

        $server = $driver->find('web-2');
        $this->assertSame(['running', null, '2001:db8:5:0:1::9'], [$server->state, $server->ipv4, $server->ipv6]);
        $this->assertNull($driver->find('gone'));
        $this->assertSame(['web-2'], array_map(fn ($s) => $s->id, $driver->listTagged('org:org-1')), 'Only servers carrying the tag, not ones made by hand.');

        $driver->destroy('web-2');
        $this->assertNotNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'DELETE'), 'An already stopped server is still deleted.');
    }

    public function test_the_account_page_gives_the_setup_command_and_explains_a_bad_string(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));

        $incus = collect($this->get('/account?tab=providers')->assertOk()->inertiaProps('providers.tokenProviders'))->firstWhere('id', 'incus');
        $this->assertSame('curl -fsSL '.rtrim((string) config('app.url'), '/').'/incus-host.sh | sudo bash', $incus['setup']['command']);
        $this->assertSame(['Client certificate', 'connection'], [$incus['chip'], $incus['noun']]);

        $this->post('/account/token/incus', ['token' => 'dop_v1_not_this'])->assertSessionHasErrors(['provider_incus' => "That isn't an Incus connection string. It starts with uh-incus: and comes from the setup script."]);
    }

    public function test_the_new_server_form_shows_no_price_and_what_is_left_on_the_host(): void
    {
        $this->connected();
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));

        $catalog = $this->app->make(ServersPage::class)->catalogs()['incus'];
        $this->assertFalse($catalog['billed']);
        $this->assertSame('2 vCPU · 2 GB · 40 GB disk', $catalog['sizes']['medium']);
        $this->assertSame('The host has 30 GB memory and 70 GB disk not yet given to servers.', $catalog['capacity']);
        $this->assertStringContainsString('IPv6', $catalog['warning'], 'Until the ingress is up, servers there are reached over IPv6 only.');
    }

    public function test_the_setup_script_is_served_to_anyone(): void
    {
        $response = $this->get('/incus-host.sh')->assertOk();
        $this->assertStringStartsWith('#!/usr/bin/env bash', $response->getContent());
        $this->assertStringContainsString('incus config trust add', $response->getContent());
    }
}
