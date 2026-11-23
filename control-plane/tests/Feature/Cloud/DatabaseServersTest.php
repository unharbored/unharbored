<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Agent\AgentRelease;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CloudInit;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Cloud\DatabaseServers;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Providers;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use App\Lib\Servers\Firewall;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * A database cluster Unharbored runs on a server of its own, for providers with no managed database. The provider
 * and the gateway are faked; what is checked is what is asked of each.
 */
final class DatabaseServersTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    /** @var list<array{node: string, spec: array<string, mixed>}> every recipe sent to a node, in order */
    private array $recipes = [];

    private bool $recipesFail = false;

    private bool $agentConnected = false;

    private string $agentDir;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        Cache::store('array')->clear();

        $this->agentDir = sys_get_temp_dir().'/cm-dbservers-agent-'.getmypid();
        @mkdir($this->agentDir, 0o755, true);
        file_put_contents($this->agentDir.'/cloud-manager-agent-linux-amd64', 'pretend binary');

        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert([
            ['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'provider' => 'hetzner', 'created_at' => now(), 'updated_at' => now()],
            ['node_id' => 'db-main.org-1', 'organization_id' => 'org-1', 'provider' => 'hetzner', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $this->http = new HttpFactory;
        $this->http->fake(function (Request $r) {
            if (str_contains($r->url(), 'gw.test')) {
                if (str_ends_with($r->url(), '/commands')) {
                    $this->recipes[] = ['node' => (string) preg_replace('#.*/v1/nodes/([^/]+)/commands#', '$1', $r->url()), 'spec' => $r->data()['args']['spec'] ?? []];

                    return $this->recipesFail && ! str_contains((string) json_encode($r->data()), '-cleanup')
                        ? $this->http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'apt exited 100'])
                        : $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['run' => ['steps' => [['action' => 'create', 'description' => 'done']]]]]);
                }

                return $this->http->response(['connected' => $this->agentConnected]);
            }
            $server = ['id' => 888, 'name' => 'db-main', 'status' => 'running', 'public_net' => ['ipv4' => ['ip' => '203.0.113.10'], 'ipv6' => ['ip' => '']]];
            if ($r->method() === 'DELETE') {
                return $this->http->response([]);
            }
            if ($r->method() === 'POST') {
                return $this->http->response(['server' => $server]);
            }

            return $this->http->response(str_ends_with($r->url(), '/servers/888') ? ['server' => $server] : ['servers' => []]);
        });
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog));
        foreach ([CredentialStore::class, Providers::class, ResourceManager::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $this->app->make(CredentialStore::class)->connectSecret('org-1', 'hetzner', ['token' => 'htok'], 'htok');
        $this->app->instance(ServerProvisioner::class, new ServerProvisioner(
            providers: $this->app->make(Providers::class),
            resources: $this->app->make(ResourceManager::class),
            enrollment: new EnrollmentService($this->app->make(Vault::class), new AuditLog),
            cloudInit: new CloudInit(release: new AgentRelease($this->agentDir, 'test'), baseUrl: 'https://cp.example.test', serverName: 'cp.example.test'),
            sleeper: static function (int $s): void {},
        ));
        foreach ([DatabaseServers::class, DatabaseClusters::class, OperationRunner::class, Sites::class] as $c) {
            $this->app->forgetInstance($c);
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->agentDir.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->agentDir);

        parent::tearDown();
    }

    private function servers(): DatabaseServers
    {
        return $this->app->make(DatabaseServers::class);
    }

    private function say(): \Closure
    {
        return function (string $a, string $t): void {};
    }

    /** An application on web-1 (203.0.113.50), a database server (203.0.113.10) and an active cluster on it. */
    private function cluster(string $engine = 'pg'): ResourceRecord
    {
        $resources = $this->app->make(ResourceManager::class);
        $web = $resources->declare('org-1', 'server', 'web-1', ['ipv4' => '203.0.113.50'], provider: 'hetzner');
        $web = $resources->recordExternalId($web, '501');
        $resources->transition($resources->transition($web, ResourceState::Provisioning, reason: 'test'), ResourceState::Active, reason: 'test');
        $db = $resources->declare('org-1', 'server', 'db-main', ['ipv4' => '203.0.113.10', 'database_cluster' => 'main'], provider: 'hetzner');
        $db = $resources->recordExternalId($db, '888');
        $resources->transition($resources->transition($db, ResourceState::Provisioning, reason: 'test'), ResourceState::Active, reason: 'test');
        $cluster = $resources->declare('org-1', 'database_cluster', 'main', ['engine' => $engine, 'mode' => 'self_run', 'server' => 'db-main', 'admitted' => [],
            'host' => '203.0.113.10', 'port' => $engine === 'pg' ? 5432 : 3306, 'ca_pem' => 'CERT'], provider: 'hetzner');
        $cluster = $resources->recordExternalId($cluster, '888');
        $cluster = $resources->transition($cluster, ResourceState::Provisioning, reason: 'test');

        return $resources->transition($cluster, ResourceState::Active, reason: 'test');
    }

    private function site(): stdClass
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop-app', 'shop.example.com', 'git@github.com:acme/shop.git');

        DB::table('sites')->where('id', $site->id)->update(['provider' => 'hetzner']);

        return DB::table('sites')->where('id', $site->id)->first();
    }

    /** @return list<array<string, mixed>> */
    private function stepsTo(string $node): array
    {
        return collect($this->recipes)->where('node', $node)->flatMap(fn ($r) => $r['spec']['steps'] ?? [])->values()->all();
    }

    public function test_the_certificate_is_for_the_servers_address_and_matches_its_key(): void
    {
        [$certificate, $key] = DatabaseServers::certificateFor('203.0.113.9');
        $parsed = openssl_x509_parse($certificate);

        $this->assertStringContainsString('IP Address:203.0.113.9', $parsed['extensions']['subjectAltName']);
        $this->assertTrue(openssl_x509_check_private_key($certificate, $key));
        $this->assertGreaterThan(time() + 86400 * 3000, $parsed['validTo_time_t']);
        $this->assertStringNotContainsString('PRIVATE KEY', $certificate);

        [$v6] = DatabaseServers::certificateFor('2001:db8::7');
        $this->assertStringContainsString('IP Address', openssl_x509_parse($v6)['extensions']['subjectAltName']);
    }

    public function test_creating_declares_the_cluster_and_has_the_provider_make_its_server(): void
    {
        $cluster = $this->servers()->create('org-1', 'main', 'pg', 'hetzner', 'nbg1', 'cx23');

        $this->assertSame(ResourceState::Provisioning, $cluster->state);
        $this->assertSame('self_run', $cluster->attributes['mode']);
        $server = $this->app->make(ResourceManager::class)->find('org-1', 'server', 'db-main');
        $this->assertSame('main', $server->attributes['database_cluster']);
        $this->assertSame('203.0.113.10', $server->attributes['ipv4']);
        $this->assertSame('db-main.org-1', $this->servers()->nodeId('org-1', 'main'));

        $this->expectException(InvalidArgumentException::class);
        $this->servers()->create('org-1', 'main', 'pg', 'hetzner', 'nbg1', 'cx23');
    }

    public function test_an_engine_it_cannot_run_and_a_bad_name_are_refused(): void
    {
        foreach ([['x', 'valkey'], ['Bad Name', 'pg']] as [$name, $engine]) {
            try {
                $this->servers()->create('org-1', $name, $engine, 'hetzner', 'nbg1', 'cx23');
                $this->fail('Accepted '.$name.'/'.$engine);
            } catch (InvalidArgumentException) {
                $this->assertTrue(true);
            }
        }
        $this->assertSame(0, DB::table('resources')->where('type', 'database_cluster')->count());
    }

    public function test_preparing_installs_the_engine_turns_tls_on_and_keeps_the_key_off_the_control_plane(): void
    {
        $this->servers()->create('org-1', 'main', 'pg', 'hetzner', 'nbg1', 'cx23');
        $cluster = $this->app->make(ResourceManager::class)->find('org-1', 'database_cluster', 'main');

        $cluster = $this->servers()->prepare($cluster, $this->say());

        $this->assertSame(ResourceState::Active, $cluster->state);
        $this->assertSame(['203.0.113.10', 5432], [$cluster->attributes['host'], $cluster->attributes['port']]);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $cluster->attributes['ca_pem']);
        $this->assertStringNotContainsString('PRIVATE KEY', (string) json_encode($cluster->attributes), 'The key is never stored.');

        $steps = $this->stepsTo('db-main.org-1');
        $key = collect($steps)->firstWhere('id', 'tls-key');
        $this->assertTrue($key['sensitive']);
        $this->assertSame('0600', $key['mode']);
        $this->assertSame($cluster->attributes['ca_pem'], base64_decode(collect($steps)->firstWhere('id', 'tls-cert')['content_b64']));
        $listen = collect($steps)->firstWhere('id', 'listen')['args'][1];
        $this->assertStringContainsString('hostssl all all 0.0.0.0/0 scram-sha-256', $listen);
        $this->assertStringContainsString('ssl = on', $listen);
        $this->assertContains('tls-forget', array_column($steps, 'id'));
        $this->assertContains('pkg-postgresql', array_column($steps, 'id'));

        // The firewall comes up closed: on, and no address admitted to the database port.
        $this->assertContains('ufw-enable', array_column($steps, 'id'));
        $this->assertSame([], array_values(array_filter(array_column($steps, 'id'), fn ($id) => str_starts_with($id, 'ufw-database'))));
        $this->assertTrue(DB::table('server_services')->where('node_id', 'db-main.org-1')->where('service', 'pgsql')->exists());
    }

    public function test_mysql_is_told_to_refuse_anything_but_tls(): void
    {
        $this->servers()->create('org-1', 'sql', 'mysql', 'hetzner', 'nbg1', 'cx23');
        DB::table('nodes')->insert(['node_id' => 'db-sql.org-1', 'organization_id' => 'org-1', 'provider' => 'hetzner', 'created_at' => now(), 'updated_at' => now()]);
        $this->servers()->prepare($this->app->make(ResourceManager::class)->find('org-1', 'database_cluster', 'sql'), $this->say());

        $listen = collect($this->stepsTo('db-sql.org-1'))->firstWhere('id', 'listen')['args'][1];
        $this->assertStringContainsString('require_secure_transport = ON', $listen);
        $this->assertStringContainsString('bind-address = 0.0.0.0', $listen);
    }

    public function test_a_failed_setup_leaves_the_cluster_unready_with_no_certificate_and_no_key_left_behind(): void
    {
        $this->servers()->create('org-1', 'main', 'pg', 'hetzner', 'nbg1', 'cx23');
        $cluster = $this->app->make(ResourceManager::class)->find('org-1', 'database_cluster', 'main');
        $this->recipesFail = true;

        try {
            $this->servers()->prepare($cluster, $this->say());
            $this->fail('Should have failed');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('apt exited 100', $e->getMessage());
        }

        $cluster = $this->app->make(ResourceManager::class)->find('org-1', 'database_cluster', 'main');
        $this->assertSame(ResourceState::Provisioning, $cluster->state);
        $this->assertArrayNotHasKey('ca_pem', $cluster->attributes);
        $last = end($this->recipes);
        $this->assertSame('database-server-cleanup', $last['spec']['name']);
    }

    public function test_attaching_admits_the_sites_server_makes_its_database_and_points_the_env_at_it(): void
    {
        $cluster = $this->cluster('pg');
        $site = $this->site();

        $done = $this->app->make(DatabaseClusters::class)->attach($site, $cluster);

        $cluster = $this->app->make(ResourceManager::class)->findById($cluster->id);
        $this->assertSame(['203.0.113.50'], $cluster->attributes['admitted']);
        $this->assertContains('firewall admits 203.0.113.50', $done);

        $steps = $this->stepsTo('db-main.org-1');
        $rule = collect($steps)->first(fn ($s) => str_starts_with($s['id'], 'ufw-database-'));
        $this->assertSame(['allow', 'from', '203.0.113.50', 'to', 'any', 'port', '5432', 'proto', 'tcp'], $rule['args']);
        $grant = base64_decode(collect($steps)->firstWhere('id', 'script')['content_b64']);
        $this->assertStringContainsString('CREATE ROLE "shop_app"', $grant);
        $this->assertSame([], $this->stepsTo('web-1.org-1'), 'Nothing is done on the application server.');

        $injected = $this->app->make(Sites::class)->injected($site);
        $this->assertSame(['pgsql', '203.0.113.10', '5432', 'shop_app', 'shop_app', 'require'],
            array_map(fn ($k) => $injected[$k]['value'], ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_SSLMODE']));
        $this->assertSame(32, strlen($injected['DB_PASSWORD']['value']));
        $row = DB::table('sites')->where('id', $site->id)->first();
        $this->assertSame([$cluster->id, 'cluster'], [(int) $row->database_cluster_id, $row->database_mode]);
        $this->assertDatabaseHas('audit_records', ['action' => 'site.database_attached']);
    }

    public function test_a_mysql_user_may_connect_from_the_network_and_needs_the_ca(): void
    {
        $cluster = $this->cluster('mysql');
        $site = $this->site();

        $this->app->make(DatabaseClusters::class)->attach($site, $cluster);

        $grant = base64_decode(collect($this->stepsTo('db-main.org-1'))->firstWhere('id', 'script')['content_b64']);
        $this->assertStringContainsString("@\\'%\\'", $grant, 'May connect from any address; the firewall decides which.');
        $injected = $this->app->make(Sites::class)->injected($site);
        $this->assertSame('mysql', $injected['DB_CONNECTION']['value']);
        $this->assertStringEndsWith('/shared/database-ca.crt', $injected['MYSQL_ATTR_SSL_CA']['value']);
    }

    public function test_admitting_twice_changes_nothing_and_releasing_removes_the_rule(): void
    {
        $cluster = $this->cluster();
        $site = $this->site();
        $clusters = $this->app->make(DatabaseClusters::class);
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster->id]);
        $site = DB::table('sites')->where('id', $site->id)->first();

        $clusters->admitServer($site, 'web-1.org-1');
        $sent = count($this->recipes);
        $this->assertSame(['firewall already admits 203.0.113.50'], $clusters->admitServer($site, 'web-1.org-1'));
        $this->assertCount($sent, $this->recipes, 'Already admitted: nothing sent.');

        $this->assertSame(['main no longer admits 203.0.113.50'], $clusters->releaseServer($site, 'web-1.org-1'));
        $this->assertSame([], $this->app->make(ResourceManager::class)->findById($cluster->id)->attributes['admitted']);
        $last = end($this->recipes);
        $this->assertSame([], array_values(array_filter(array_column($last['spec']['steps'], 'id'), fn ($id) => str_starts_with($id, 'ufw-database'))));
    }

    public function test_the_database_servers_firewall_is_always_on_and_keeps_its_rules_through_any_rebuild(): void
    {
        $cluster = $this->cluster();
        $this->app->make(ResourceManager::class)->mergeAttributes($cluster, ['admitted' => ['203.0.113.50', '203.0.113.51']]);

        $settings = Firewall::settings('db-main.org-1');
        $this->assertTrue($settings['enabled'], 'Off in the node settings, on because it runs a database.');
        $this->assertSame(['port' => 5432, 'sources' => ['203.0.113.50', '203.0.113.51']], $settings['database']);
        $ids = array_column(Firewall::recipe($settings)['steps'], 'id');
        $this->assertContains('ufw-database-0', $ids);
        $this->assertContains('ufw-database-1', $ids);

        $other = Firewall::settings('web-1.org-1');
        $this->assertFalse($other['enabled']);
        $this->assertNull($other['database']);
    }

    public function test_dropping_a_sites_database_runs_on_the_database_server(): void
    {
        $cluster = $this->cluster();
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster->id]);
        $site = DB::table('sites')->where('id', $site->id)->first();

        $done = $this->app->make(DatabaseClusters::class)->dropDatabase($site, siteOwned: true);

        $this->assertSame(['user shop_app dropped', 'database shop_app dropped'], $done);
        $drop = base64_decode(collect($this->stepsTo('db-main.org-1'))->firstWhere('id', 'script')['content_b64']);
        $this->assertStringContainsString('DROP DATABASE IF EXISTS "shop_app"', $drop);
    }

    public function test_destroying_refuses_while_a_site_is_attached_then_removes_the_server_and_the_cluster(): void
    {
        $cluster = $this->cluster();
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster->id]);
        $clusters = $this->app->make(DatabaseClusters::class);

        try {
            $clusters->destroy($cluster);
            $this->fail('Destroyed a cluster a site uses.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('shop-app', $e->getMessage());
        }

        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => null]);
        $clusters->destroy($cluster);

        $resources = $this->app->make(ResourceManager::class);
        $this->assertSame(ResourceState::Deleted, $resources->find('org-1', 'database_cluster', 'main')->state);
        $this->assertSame(ResourceState::Deleted, $resources->find('org-1', 'server', 'db-main')->state);
    }

    public function test_a_self_run_cluster_has_no_provider_backups_to_restore_from(): void
    {
        $cluster = $this->cluster();

        $this->assertSame([], $this->app->make(DatabaseClusters::class)->backups($cluster));
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(DatabaseClusters::class)->fork($cluster, '2026-11-20T00:00:00Z', 'copy');
    }

    public function test_the_operation_creates_the_server_waits_for_its_agent_then_sets_it_up(): void
    {
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueDatabaseCreate('org-1', 'main', 'pg', 'nbg1', 'cx23', 'console', 'matt', 'hetzner');
        $stages = array_column(json_decode((string) DB::table('operations')->where('id', $id)->value('stages'), true), 'name');
        $this->assertSame(['create', 'enroll', 'setup'], [...$stages]);

        // Made, but its agent has not connected: the operation waits, and nothing is installed yet.
        $this->assertSame('waiting', $runner->run($id));
        $this->assertSame(ResourceState::Provisioning, $this->app->make(ResourceManager::class)->find('org-1', 'database_cluster', 'main')->state);
        $this->assertSame([], $this->recipes);

        $this->agentConnected = true;
        $this->assertSame('succeeded', $runner->run($id));
        $this->assertSame(ResourceState::Active, $this->app->make(ResourceManager::class)->find('org-1', 'database_cluster', 'main')->state);
    }

    public function test_a_server_that_never_enrolls_is_destroyed_so_it_does_not_keep_billing(): void
    {
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueDatabaseCreate('org-1', 'main', 'pg', 'nbg1', 'cx23', 'console', 'matt', 'hetzner');
        $this->assertSame('waiting', $runner->run($id));

        Carbon::setTestNow(now()->addMinutes(16));
        $this->assertSame('failed', $runner->run($id));

        $resources = $this->app->make(ResourceManager::class);
        $this->assertSame(ResourceState::Deleted, $resources->find('org-1', 'server', 'db-main')->state);
        $this->assertStringContainsString('never connected', (string) DB::table('operations')->where('id', $id)->value('error'));
    }
}
