<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Cloud\DatabaseServers;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Servers\ServerInstall;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * A database on a server the person already has, set up from an install command. The machine is theirs: nothing here may
 * create or destroy it, it must have nothing else on it, and the cluster must say so when it cannot be set up.
 */
final class DatabaseOwnServerTest extends TestCase
{
    use RefreshDatabase;

    private const NODE = 'db-own.org-1';

    private HttpFactory $http;

    /** @var list<array{node: string, kind: string, spec: array<string, mixed>}> */
    private array $sent = [];

    private string $found = '';

    private bool $connected = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);

        $this->http = new HttpFactory;
        $this->http->fake(function (Request $r) {
            if (! str_ends_with($r->url(), '/commands')) {
                return $this->http->response(['connected' => $this->connected]);
            }
            $kind = (string) ($r->data()['kind'] ?? '');
            $this->sent[] = ['node' => (string) preg_replace('#.*/v1/nodes/([^/]+)/commands#', '$1', $r->url()), 'kind' => $kind, 'spec' => $r->data()['args']['spec'] ?? []];

            return $this->http->response(['status' => 'succeeded', 'outcome_known' => true,
                'detail' => $kind === 'exec' ? ['output' => $this->found] : ['run' => ['steps' => [['action' => 'create', 'description' => 'done']]]]]);
        });
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog));
        foreach ([ResourceManager::class, DatabaseServers::class, DatabaseClusters::class, OperationRunner::class, ServerInstall::class] as $c) {
            $this->app->forgetInstance($c);
        }
    }

    private function resources(): ResourceManager
    {
        return $this->app->make(ResourceManager::class);
    }

    /** The command is run on the server and it enrolls from $ip. @return array{command: string, operation: int, expires_at: string} */
    private function issued(?string $address = null, string $engine = 'pg', string $enrolledFrom = '203.0.113.9'): array
    {
        $issued = $this->app->make(DatabaseServers::class)->createOnOwnServer('org-1', 'own', $engine, $address, 'https://cp.example.com', 'matt@example.com');
        DB::table('server_installs')->update(['fetched_at' => now(), 'fetched_by' => $enrolledFrom]);
        DB::table('nodes')->insert(['node_id' => self::NODE, 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('enrollment_grants')->insert(['token_hash' => hash('sha256', 'x'), 'node_id' => self::NODE, 'organization_id' => 'org-1', 'expires_at' => now()->addHour(),
            'consumed_at' => now(), 'consumed_by_ip' => $enrolledFrom, 'created_at' => now(), 'updated_at' => now()]);

        return $issued;
    }

    public function test_asking_declares_the_cluster_and_gives_an_install_command_that_sets_up_a_database_not_the_web_stack(): void
    {
        $issued = $this->app->make(DatabaseServers::class)->createOnOwnServer('org-1', 'own', 'pg', null, 'https://cp.example.com', 'matt@example.com');

        $this->assertMatchesRegularExpression('#^curl -fsSL https://cp\.example\.com/install/[0-9a-f]{48} \| sudo bash$#', $issued['command']);
        $cluster = $this->resources()->find('org-1', 'database_cluster', 'own');
        $this->assertSame(ResourceState::Provisioning, $cluster->state);
        $this->assertTrue(DatabaseServers::isOwnServer($cluster));
        $this->assertSame('custom', $cluster->provider);
        $op = DB::table('operations')->find($issued['operation']);
        $this->assertSame([self::NODE, 'adopt'], [$op->node_id, $op->kind]);
        $this->assertSame(['install', 'enroll', 'setup'], array_column(json_decode($op->stages, true), 'name'));
        $this->assertNull($this->resources()->find('org-1', 'server', 'db-own'), 'There is no server record: nothing was created.');
    }

    public function test_a_bad_address_a_taken_name_and_an_unknown_engine_are_refused_and_leave_nothing_behind(): void
    {
        $servers = $this->app->make(DatabaseServers::class);
        foreach ([['own', 'pg', 'not-an-ip'], ['own', 'oracle', null], ['Bad Name', 'pg', null]] as [$name, $engine, $address]) {
            try {
                $servers->createOnOwnServer('org-1', $name, $engine, $address, 'https://cp.example.com', 'm@e.com');
                $this->fail("{$name} {$engine} {$address} was accepted.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertNull($this->resources()->find('org-1', 'database_cluster', 'own'));

        $servers->createOnOwnServer('org-1', 'own', 'pg', null, 'https://cp.example.com', 'm@e.com');
        $this->expectException(InvalidArgumentException::class);
        $servers->createOnOwnServer('org-1', 'own', 'pg', null, 'https://cp.example.com', 'm@e.com');
    }

    public function test_once_its_agent_connects_the_engine_is_installed_on_the_address_it_connected_from_and_the_machine_is_not_otherwise_touched(): void
    {
        $issued = $this->issued();

        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($issued['operation']));

        $cluster = $this->resources()->find('org-1', 'database_cluster', 'own');
        $this->assertSame(ResourceState::Active, $cluster->state);
        $this->assertSame(['203.0.113.9', 5432], [$cluster->attributes['host'], $cluster->attributes['port']]);
        $this->assertStringContainsString('IP Address:203.0.113.9', (string) openssl_x509_parse($cluster->attributes['ca_pem'])['extensions']['subjectAltName']);
        $recipes = array_filter($this->sent, fn ($s) => $s['kind'] === 'apply_recipe');
        $names = array_column(array_column($recipes, 'spec'), 'name');
        $this->assertContains('database-server', $names);
        $this->assertNotContains('server-stack', $names, 'No web stack is installed.');
        $this->assertNull($this->resources()->find('org-1', 'server', 'db-own'));
        $this->assertSame(['port' => 5432, 'sources' => []], DatabaseServers::firewallFor(self::NODE), 'The firewall knows this server holds a database.');
    }

    public function test_an_address_given_by_the_person_wins_over_the_one_it_connected_from(): void
    {
        $issued = $this->issued('198.51.100.7');

        $this->app->make(OperationRunner::class)->run($issued['operation']);

        $this->assertSame('198.51.100.7', $this->resources()->find('org-1', 'database_cluster', 'own')->attributes['host']);
    }

    public function test_a_private_address_is_set_up_but_the_log_says_only_its_own_network_can_reach_it(): void
    {
        $issued = $this->issued(null, 'pg', '192.168.1.20');

        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($issued['operation']));

        $this->assertStringContainsString('private address', (string) DB::table('operations')->find($issued['operation'])->log);
    }

    public function test_a_server_that_already_runs_something_is_refused_before_anything_is_installed_and_the_cluster_says_why(): void
    {
        $this->found = "nginx\nmysql-server\nport 80\nport 80";
        $issued = $this->issued();

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($issued['operation']));

        $cluster = $this->resources()->find('org-1', 'database_cluster', 'own');
        $this->assertSame(ResourceState::Failed, $cluster->state);
        $this->assertStringContainsString('nginx, mysql-server, port 80', (string) $cluster->lastError);
        $this->assertStringContainsString('freshly installed', (string) DB::table('operations')->find($issued['operation'])->error);
        $this->assertSame([], array_filter($this->sent, fn ($s) => $s['kind'] === 'apply_recipe'), 'Nothing was installed.');
    }

    public function test_a_link_that_is_never_used_fails_the_cluster_instead_of_leaving_it_creating(): void
    {
        $issued = $this->app->make(DatabaseServers::class)->createOnOwnServer('org-1', 'own', 'pg', null, 'https://cp.example.com', 'm@e.com');
        $runner = $this->app->make(OperationRunner::class);
        $this->assertSame('waiting', $runner->run($issued['operation']));
        $this->assertSame(ResourceState::Provisioning, $this->resources()->find('org-1', 'database_cluster', 'own')->state);

        DB::table('server_installs')->update(['expires_at' => now()->subMinute()]);

        $this->assertSame('failed', $runner->run($issued['operation']));
        $this->assertSame(ResourceState::Failed, $this->resources()->find('org-1', 'database_cluster', 'own')->state);
    }

    public function test_removing_the_cluster_takes_the_agent_off_the_server_and_never_asks_a_provider_to_destroy_anything(): void
    {
        $issued = $this->issued();
        $this->app->make(OperationRunner::class)->run($issued['operation']);
        $cluster = $this->resources()->find('org-1', 'database_cluster', 'own');

        $this->app->make(DatabaseClusters::class)->destroy($cluster);

        $this->assertSame(ResourceState::Deleted, $this->resources()->find('org-1', 'database_cluster', 'own')->state);
        $this->assertFalse(DB::table('nodes')->where('node_id', self::NODE)->exists(), 'Its certificate is revoked and the node is gone.');
        $this->assertTrue(collect($this->sent)->contains(fn ($s) => $s['node'] === self::NODE && ($s['spec']['name'] ?? '') !== 'database-server' && $s['kind'] === 'apply_recipe'), 'The agent is uninstalled.');
    }

    public function test_the_console_offers_your_own_server_and_hands_back_the_command_once(): void
    {
        DB::table('memberships')->insert(['user_id' => ($user = User::factory()->create())->id, 'organization_id' => 'org-1', 'role' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        $this->get('/databases')->assertOk()->assertInertia(fn ($page) => $page->where('choices.custom.label', 'Your own server')->where('choices.custom.custom', true));

        $this->post('/databases', ['name' => 'own', 'provider' => 'custom', 'engine' => 'mysql', 'address' => '203.0.113.9'])
            ->assertSessionHasNoErrors()->assertSessionHas('byo');
        $this->assertSame('203.0.113.9', $this->resources()->find('org-1', 'database_cluster', 'own')->attributes['address']);

        $this->post('/databases', ['name' => 'two', 'provider' => 'custom', 'engine' => 'mysql', 'address' => 'nope'])->assertSessionHasErrors('address');
        $this->post('/databases', ['name' => 'own', 'provider' => 'custom', 'engine' => 'mysql'])->assertSessionHasErrors('name');
    }

    public function test_a_new_certificate_is_made_for_the_address_it_has_now_and_every_attached_site_is_given_it(): void
    {
        $issued = $this->issued();
        $this->app->make(OperationRunner::class)->run($issued['operation']);
        $old = $this->resources()->find('org-1', 'database_cluster', 'own')->attributes['ca_pem'];
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $site = $this->app->make(\App\Lib\Sites\Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $this->resources()->find('org-1', 'database_cluster', 'own')->id]);
        $this->sent = [];

        $op = $this->app->make(OperationRunner::class)->queueDatabaseReissue($this->resources()->find('org-1', 'database_cluster', 'own')->id, '198.51.100.8', 'console', 'm@e.com');
        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($op));

        $cluster = $this->resources()->find('org-1', 'database_cluster', 'own');
        $this->assertSame('198.51.100.8', $cluster->attributes['host']);
        $this->assertNotSame($old, $cluster->attributes['ca_pem']);
        $this->assertStringContainsString('IP Address:198.51.100.8', (string) openssl_x509_parse($cluster->attributes['ca_pem'])['extensions']['subjectAltName']);
        $names = array_column(array_column($this->sent, 'spec'), 'name');
        $this->assertContains('database-server-certificate', $names);
        $this->assertContains('database-ca', $names, 'The site is given the certificate to pin.');
    }
}
