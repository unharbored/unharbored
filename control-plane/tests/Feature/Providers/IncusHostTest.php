<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\RunOperation;
use App\Lib\Providers\Incus\IncusHost;
use App\Lib\Providers\Incus\IncusProvider;
use App\Lib\Providers\Incus\Ingress;
use App\Lib\Providers\Providers;
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
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

/** An Incus host Unharbored sets up and removes itself, kept like a database cluster: servers live in it, nothing runs on it. */
final class IncusHostTest extends TestCase
{
    use RefreshDatabase;

    private const HOST = 'metal-1.org-1';

    private string $hostCertificate = '';

    /** What the setup script prints when run through the agent. */
    private string $scriptOutput = '';

    /** @var list<array{node: string, kind: string, args: array<string, mixed>}> */
    private array $commands = [];

    /** @var list<string> */
    private array $incusCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        foreach ([KeyManager::class, Vault::class, CredentialStore::class, Providers::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1', 'ec' => ['curve_name' => 'prime256v1']]);
        openssl_x509_export(openssl_csr_sign(openssl_csr_new(['commonName' => 'metal-1'], $key), null, $key, 30), $this->hostCertificate);
        $this->app->instance(IncusProvider::class, new IncusProvider($this->app->make(CredentialStore::class), $this->app->make(HttpFactory::class), fn () => $this->hostCertificate));
        $this->scriptOutput = "  \e[32m✓\e[0m Incus 6.0.5\n  \e[32m✓\e[0m Ports 80 and 443 free for the IPv4 ingress\n\nuh-incus:".$this->connectionString()."\n";

        Http::fake(function (Request $r) {
            if (preg_match('#gw\.test/v1/nodes/([^/]+)/commands#', $r->url(), $m)) {
                $this->commands[] = ['node' => $m[1], 'kind' => (string) $r['kind'], 'args' => (array) $r['args']];
                $output = $r['kind'] === 'exec' && ($r['args']['name'] ?? '') === 'bash' ? $this->scriptOutput : '';

                return Http::response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $output]]);
            }
            if (str_contains($r->url(), 'gw.test')) {
                return Http::response(['connected' => true]);
            }
            $path = (string) parse_url($r->url(), PHP_URL_PATH);
            $this->incusCalls[] = $r->method().' '.$path;

            return Http::response(match ($r->method().' '.$path) {
                'GET /1.0' => ['metadata' => ['auth' => 'trusted', 'environment' => ['server_name' => 'metal-1', 'server_version' => '6.0.5', 'driver' => 'lxc']]],
                'GET /1.0/projects/unharbored' => ['metadata' => ['name' => 'unharbored']],
                'GET /1.0/resources' => ['metadata' => ['cpu' => ['total' => 8], 'memory' => ['total' => 32 * 1024 ** 3]]],
                default => ['type' => 'sync', 'metadata' => []],
            });
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->app->make(HttpFactory::class), new AuditLog));
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Test Org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => self::HOST, 'organization_id' => 'org-1', 'certificate_pem' => 'x',
            'certificate_expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function connectionString(): string
    {
        $token = base64_encode((string) json_encode(['client_name' => 'unharbored-unharbored-1', 'fingerprint' => openssl_x509_fingerprint($this->hostCertificate, 'sha256'),
            'addresses' => ['203.0.113.5:8443'], 'secret' => 'one-time-secret', 'expires_at' => '2026-11-22T21:00:00Z']));

        return base64_encode((string) json_encode(['token' => $token, 'ipv6_prefix' => '2001:db8:5::/64', 'parent' => 'eth0', 'project' => 'unharbored']));
    }

    private function host(): IncusHost
    {
        return $this->app->make(IncusHost::class);
    }

    /** @param array<string, mixed> $attributes */
    private function server(string $slug, string $provider = 'incus', array $attributes = []): void
    {
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => $slug, 'provider' => $provider, 'external_id' => $slug,
            'state' => 'active', 'desired_state' => 'active', 'attributes' => json_encode($attributes), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function installed(): void
    {
        $this->host()->install('org-1', self::HOST, static function (): void {}, 'matt@example.com');
        $this->commands = [];
        $this->incusCalls = [];
    }

    public function test_installing_runs_the_setup_script_through_the_agent_and_connects_with_what_it_prints(): void
    {
        $lines = [];
        $this->host()->install('org-1', self::HOST, function (string $action, string $text) use (&$lines) {
            $lines[] = $text;
        }, 'matt@example.com');

        $script = $this->commands[0]['args']['spec']['steps'][0];
        $this->assertSame('/usr/local/sbin/unharbored-incus-host', $script['path']);
        $this->assertStringContainsString('Unharbored · Incus host setup', base64_decode($script['content_b64']), 'The same script people paste.');
        $this->assertSame(['exec', 'bash'], [$this->commands[1]['kind'], $this->commands[1]['args']['name']]);
        $this->assertContains('POST /1.0/certificates', $this->incusCalls, 'The token was spent.');
        $this->assertTrue($this->app->make(CredentialStore::class)->has('org-1', 'incus'));
        $this->assertSame(IncusHost::ROLE, DB::table('nodes')->where('node_id', self::HOST)->value('role'));
        $this->assertContains('Ports 80 and 443 free for the IPv4 ingress', $lines, 'The script\'s checks show in the operation log, without colour codes.');
        Queue::assertPushed(RunOperation::class, 1);
        $this->assertSame('provision', DB::table('operations')->value('kind'), 'The ingress is queued.');
    }

    public function test_a_script_that_prints_no_connection_string_fails_and_connects_nothing(): void
    {
        $this->scriptOutput = "  ✗ No /64 on eth0. Ask your provider for an IPv6 /64 routed to this machine.\n";

        try {
            $this->host()->install('org-1', self::HOST, static function (): void {}, 'matt@example.com');
            $this->fail('It should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('no connection string', $e->getMessage());
        }
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', 'incus'));
        $this->assertNull(DB::table('nodes')->where('node_id', self::HOST)->value('role'));
    }

    public function test_a_second_host_is_refused_while_one_is_connected(): void
    {
        $this->installed();

        $this->expectException(InvalidArgumentException::class);
        $this->host()->assertCanAdd('org-1');
    }

    public function test_the_host_cannot_be_destroyed_while_a_server_lives_on_it_but_the_ingress_does_not_count(): void
    {
        $this->installed();
        $this->server('metal-1', 'vultr');
        $this->server(Ingress::SLUG);
        $this->server('web-2');

        try {
            $this->app->make(OperationRunner::class)->queueServerDestroy(self::HOST, 'console', 'matt@example.com');
            $this->fail('It should have been refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('1 server still lives on this Incus host (web-2). Destroy it first.', $e->getMessage());
        }

        DB::table('resources')->where('slug', 'web-2')->update(['state' => 'deleted']);
        $this->app->make(OperationRunner::class)->queueServerDestroy(self::HOST, 'console', 'matt@example.com');
        $this->assertSame(['host', 'destroy'], array_column(json_decode((string) DB::table('operations')->where('kind', 'server_destroy')->value('stages'), true), 'name'));
    }

    public function test_teardown_destroys_the_ingress_removes_our_key_from_the_host_and_forgets_the_connection(): void
    {
        $this->installed();
        $this->server(Ingress::SLUG, 'incus', ['ipv4' => '203.0.113.5']);
        DB::table('nodes')->insert(['node_id' => Ingress::nodeId('org-1'), 'organization_id' => 'org-1', 'role' => 'ingress', 'certificate_pem' => 'x',
            'certificate_expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);
        $fingerprint = openssl_x509_fingerprint((string) $this->app->make(CredentialStore::class)->secret('org-1', 'incus', 'test')['client_cert'], 'sha256');

        $this->host()->teardown('org-1', self::HOST, static function (): void {}, 'matt@example.com');

        $this->assertContains('DELETE /1.0/instances/'.Ingress::SLUG, $this->incusCalls);
        $this->assertSame('deleted', DB::table('resources')->where('slug', Ingress::SLUG)->value('state'));
        $trust = collect($this->commands)->firstWhere('args.name', 'incus');
        $this->assertSame([self::HOST, ['config', 'trust', 'remove', $fingerprint]], [$trust['node'], $trust['args']['args']]);
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', 'incus'));
    }

    public function test_nothing_of_the_teams_is_put_on_the_host(): void
    {
        $this->installed();
        DB::table('nodes')->where('node_id', self::HOST)->update(['provider' => 'vultr']);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'provider' => 'vultr', 'certificate_pem' => 'x',
            'certificate_expires_at' => now()->addYear(), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(['web-1'], array_column(NewApplications::servers('org-1', 'vultr'), 'name'));
        $site = (new Sites($this->app->make(Vault::class), new AuditLog))->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $this->expectExceptionMessage('nothing runs on it directly');
        $this->app->make(OperationRunner::class)->queueServerAdd((int) $site->id, self::HOST, 'console', 'matt@example.com');
    }

    public function test_a_new_host_is_provisioned_with_incus_in_place_of_the_stack(): void
    {
        $this->app->make(OperationRunner::class)->queueProvision('org-1', 'metal-2', ['provider' => 'vultr', 'region' => 'ewr', 'size' => 'vc2', 'role' => IncusHost::ROLE], 'console', 'matt@example.com');

        $operation = DB::table('operations')->first();
        $this->assertSame('Create Incus host metal-2', $operation->title);
        $this->assertSame(['preflight', 'create', 'enroll', 'incus'], array_column(json_decode((string) $operation->stages, true), 'name'));
    }
}
