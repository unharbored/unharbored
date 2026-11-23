<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Agent\AgentRelease;
use App\Lib\Cloud\CloudInit;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\Reconciler;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Providers\Hetzner\HetznerProvider;
use App\Lib\Providers\Providers;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\Shapes;
use App\Lib\Providers\Uncertain;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

/** The second VM provider: what it took to add is the test of the abstraction. */
final class HetznerProviderTest extends TestCase
{
    use RefreshDatabase;

    /** @var \Closure(Request): mixed */
    private \Closure $respond;

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        foreach ([KeyManager::class, Vault::class, CredentialStore::class, Providers::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $this->respond = fn () => Http::response([], 500);
        Http::fake(function (Request $r) {
            $this->sent[] = $r;

            return ($this->respond)($r);
        });
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Test Org', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function hetzner(): HetznerProvider
    {
        return $this->app->make(Providers::class)->hetzner();
    }

    private function connect(): void
    {
        $this->respond = fn () => Http::response(['servers' => []]);
        $this->hetzner()->connect('org-1', 'hz_token_1234567890');
    }

    private function spec(): ServerSpec
    {
        return new ServerSpec('web-1', 'nbg1', 'cx23', 'ubuntu-24.04', '#cloud-config', 'idem', ['cloud-manager', 'org:org-1', 'resource:web-1']);
    }

    public function test_it_is_a_single_server_provider_with_the_reasons_for_the_locked_shapes(): void
    {
        $shapes = Shapes::for($this->hetzner());

        $this->assertTrue($shapes['single']['available']);
        $this->assertSame('Autoscaling needs a load balancer. Hetzner does not offer one yet.', $shapes['autoscale']['reason']);
        $this->assertSame('hetzner', $this->hetzner()->id());
    }

    public function test_connecting_checks_the_token_and_keeps_only_a_hint_in_the_table(): void
    {
        $this->connect();

        $this->assertSame('hz_t••••••••7890', DB::table('cloud_credentials')->where('provider', 'hetzner')->value('key_hint'));
        $this->assertStringNotContainsString('hz_token_1234567890', json_encode(DB::table('cloud_credentials')->get()));
        $check = collect($this->sent)->first();
        $this->assertSame('Bearer hz_token_1234567890', $check->header('Authorization')[0]);
        $this->assertSame(['hetzner', 'custom'], array_keys($this->app->make(Providers::class)->connected('org-1')));
    }

    public function test_a_refused_token_is_not_kept(): void
    {
        $this->respond = fn () => Http::response(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401);

        try {
            $this->hetzner()->connect('org-1', 'bad');
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('unauthorized', $e->getMessage());
        }
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', 'hetzner'));
    }

    public function test_create_sends_labels_and_user_data_and_returns_the_id(): void
    {
        $this->connect();
        $this->respond = fn (Request $r) => $r->method() === 'POST' ? Http::response(['server' => ['id' => 4242]], 201) : Http::response(['servers' => []]);

        $id = $this->hetzner()->servers('org-1')->create($this->spec());

        $this->assertSame('4242', $id);
        $post = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST');
        $this->assertSame(['web-1', 'cx23', 'nbg1', 'ubuntu-24.04', '#cloud-config'], [$post['name'], $post['server_type'], $post['location'], $post['image'], $post['user_data']]);
        $this->assertSame(['cloud-manager' => '', 'org' => 'org-1', 'resource' => 'web-1'], $post['labels']);
    }

    public function test_a_retried_create_finds_the_server_by_name_instead_of_making_a_second(): void
    {
        $this->connect();
        $this->respond = fn () => Http::response(['servers' => [['id' => 77, 'name' => 'web-1']]]);

        $this->assertSame('77', $this->hetzner()->servers('org-1')->create($this->spec()));
        $this->assertNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST'));
    }

    public function test_find_maps_each_state_and_a_missing_server_is_null(): void
    {
        $this->connect();
        $driver = $this->hetzner()->servers('org-1');

        foreach (['initializing' => 'pending', 'starting' => 'pending', 'running' => 'running', 'off' => 'stopped', 'deleting' => 'stopped'] as $status => $state) {
            $this->respond = fn () => Http::response(['server' => ['id' => 1, 'status' => $status, 'name' => 'web-1',
                'public_net' => ['ipv4' => ['ip' => '203.0.113.9'], 'ipv6' => ['ip' => '2001:db8:3::/64']], 'private_net' => [['ip' => '10.0.0.2']]]]);
            $server = $driver->find('1');
            $this->assertSame([$state, '203.0.113.9', '10.0.0.2', '2001:db8:3::1'], [$server->state, $server->ipv4, $server->privateIpv4, $server->ipv6], $status);
        }

        $this->respond = fn () => Http::response(['error' => ['code' => 'not_found', 'message' => 'x']], 404);
        $this->assertNull($driver->find('1'));
    }

    public function test_destroy_is_idempotent_and_list_tagged_uses_a_label_selector(): void
    {
        $this->connect();
        $driver = $this->hetzner()->servers('org-1');

        $this->respond = fn () => Http::response(['error' => ['code' => 'not_found', 'message' => 'x']], 404);
        $driver->destroy('1');

        $this->respond = fn () => Http::response(['servers' => [['id' => 5, 'status' => 'running', 'name' => 'a']]]);
        $this->assertSame(['5'], array_map(fn ($s) => $s->id, $driver->listTagged('org:org-1')));
        $this->assertSame('org=org-1', collect($this->sent)->last()->data()['label_selector']);
        $driver->listTagged('cloud-manager');
        $this->assertSame('cloud-manager', collect($this->sent)->last()->data()['label_selector']);
    }

    public function test_a_refusal_is_rejected_and_a_server_error_or_rate_limit_is_uncertain(): void
    {
        $this->connect();
        $driver = $this->hetzner()->servers('org-1');

        $this->respond = fn (Request $r) => $r->method() === 'POST' ? Http::response(['error' => ['code' => 'resource_limit_exceeded', 'message' => 'server limit reached']], 403) : Http::response(['servers' => []]);
        try {
            $driver->create($this->spec());
            $this->fail();
        } catch (Rejected $e) {
            $this->assertStringContainsString('server limit reached', $e->getMessage());
        }

        foreach ([503, 429] as $status) {
            $this->respond = fn (Request $r) => $r->method() === 'POST' ? Http::response([], $status) : Http::response(['servers' => []]);
            try {
                $driver->create($this->spec());
                $this->fail();
            } catch (Uncertain) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_provisioner_builds_and_destroys_a_hetzner_server_with_no_provider_specific_code(): void
    {
        $this->connect();
        $agent = sys_get_temp_dir().'/cm-hz-agent-'.getmypid();
        @mkdir($agent, 0o755, true);
        file_put_contents($agent.'/cloud-manager-agent-linux-amd64', 'pretend');
        config(['control_plane.agent_directory' => $agent, 'control_plane.public_url' => 'https://cp.example.test']);
        foreach ([AgentRelease::class, CloudInit::class, ServerProvisioner::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $this->respond = function (Request $r) {
            return match (true) {
                $r->method() === 'POST' => Http::response(['server' => ['id' => 4242]], 201),
                $r->method() === 'GET' && str_ends_with($r->url(), '/servers/4242') => Http::response(['server' => ['id' => 4242, 'status' => 'running', 'name' => 'web-1', 'public_net' => ['ipv4' => ['ip' => '203.0.113.9']]]]),
                $r->method() === 'DELETE' => Http::response(['action' => []]),
                default => Http::response(['servers' => []]),
            };
        };

        $record = $this->app->make(ServerProvisioner::class)->provision('org-1', 'web-1', ['provider' => 'hetzner', 'region' => 'nbg1', 'size' => 'cx23']);

        $this->assertSame([ResourceState::Active, 'hetzner', '4242', '203.0.113.9'], [$record->state, $record->provider, $record->externalId, $record->attributes['ipv4']]);
        $this->app->make(ServerProvisioner::class)->destroy($record);
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'web-1')->value('state'));
        $this->assertNotNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'DELETE'));
        array_map('unlink', glob($agent.'/*'));
        @rmdir($agent);
    }

    public function test_the_account_page_connects_and_disconnects_it(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $this->respond = fn () => Http::response(['servers' => []]);

        $this->post('/account/hetzner', ['token' => 'hz_token_1234567890'])->assertRedirect();
        $page = $this->get('/account?tab=providers')->assertOk();
        $this->assertSame('hz_t••••••••7890', $page->inertiaProps('providers.hetzner.hint'));
        $this->assertStringNotContainsString('hz_token_1234567890', $page->getContent());

        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'provider' => 'hetzner', 'slug' => 'web-1', 'external_id' => '1', 'state' => 'active', 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->delete('/account/hetzner')->assertSessionHasErrors('provider_hetzner');
        DB::table('resources')->delete();
        $this->delete('/account/hetzner')->assertSessionHasNoErrors();
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', 'hetzner'));
    }

    public function test_the_reconciler_checks_a_second_provider_through_its_driver(): void
    {
        $this->connect();
        $resources = $this->app->make(ResourceManager::class);
        foreach (['kept' => '1', 'vanished' => '2'] as $slug => $id) {
            $r = $resources->declare('org-1', 'server', $slug, ['region' => 'nbg1'], provider: 'hetzner');
            $r = $resources->transition($r, ResourceState::Provisioning, reason: 't');
            $r = $resources->recordExternalId($r, $id);
            $resources->transition($r, ResourceState::Active, reason: 't');
        }
        $this->respond = fn () => Http::response(['servers' => [
            ['id' => 1, 'status' => 'running', 'name' => 'kept'],
            ['id' => 3, 'status' => 'running', 'name' => 'leaked'],
        ]]);

        $report = $this->app->make(Reconciler::class)->reconcile('org-1');

        $this->assertTrue($report->checkedProvider);
        $this->assertSame(['3'], array_column($report->untracked, 'external_id'));
        $this->assertSame('hetzner', $report->untracked[0]['provider']);
        $this->assertSame(['vanished'], array_map(fn ($r) => $r->slug, $report->missing));
        $this->assertDatabaseHas('audit_records', ['action' => 'reconcile.untracked_resource', 'resource_id' => '3']);
    }
}
