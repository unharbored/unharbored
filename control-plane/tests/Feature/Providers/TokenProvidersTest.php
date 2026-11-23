<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Agent\AgentRelease;
use App\Lib\Cloud\CloudInit;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Providers\Providers;
use App\Lib\Providers\Rejected;
use App\Lib\Providers\ServerSpec;
use App\Lib\Providers\Shapes;
use App\Lib\Providers\TokenApiProvider;
use App\Lib\Providers\Uncertain;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Vultr and Linode: servers only, one API token each, added with a base class and a driver apiece. */
final class TokenProvidersTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'tok_1234567890';

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

    private function provider(string $id): TokenApiProvider
    {
        return $this->app->make(Providers::class)->get($id);
    }

    private function connect(string $id): void
    {
        $this->respond = fn () => Http::response(['account' => [], 'username' => 'a']);
        $this->provider($id)->connect('org-1', self::TOKEN);
        $this->sent = [];
    }

    private function spec(string $region, string $size, string $image): ServerSpec
    {
        return new ServerSpec('web-1', $region, $size, $image, '#cloud-config', 'idem', ['cloud-manager', 'org:org-1', 'resource:web-1']);
    }

    /** @return array<string, array{string}> */
    public static function providers(): array
    {
        return ['vultr' => ['vultr'], 'linode' => ['linode']];
    }

    #[DataProvider('providers')]
    public function test_each_is_a_single_server_provider_with_a_catalog_and_the_reasons_for_the_locked_shapes(string $id): void
    {
        $provider = $this->provider($id);
        $shapes = Shapes::for($provider);

        $this->assertTrue($shapes['single']['available']);
        $this->assertSame("Autoscaling needs a load balancer. {$provider->label()} does not offer one yet.", $shapes['autoscale']['reason']);
        $catalog = $provider->catalog();
        $this->assertTrue($catalog->hasRegion($catalog->defaultRegion));
        $this->assertCount(4, $catalog->sizes);
    }

    #[DataProvider('providers')]
    public function test_connecting_checks_the_token_and_keeps_only_a_hint_in_the_table(string $id): void
    {
        $this->connect($id);

        $this->assertSame('tok_••••••••7890', DB::table('cloud_credentials')->where('provider', $id)->value('key_hint'));
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('cloud_credentials')->get()));
        $this->provider($id)->verify('org-1');
        $this->assertSame('Bearer '.self::TOKEN, collect($this->sent)->first()->header('Authorization')[0]);
        $this->assertSame([$id, 'custom'], array_keys($this->app->make(Providers::class)->connected('org-1')));
    }

    public function test_a_refused_token_is_not_kept_and_the_reason_is_shown(): void
    {
        $this->respond = fn () => Http::response(['error' => 'Invalid API key', 'status' => 401], 401);
        try {
            $this->provider('vultr')->connect('org-1', 'bad');
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid API key', $e->getMessage());
        }

        $this->respond = fn () => Http::response(['errors' => [['reason' => 'Invalid Token']]], 401);
        try {
            $this->provider('linode')->connect('org-1', 'bad');
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Invalid Token', $e->getMessage());
        }
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', 'vultr'));
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', 'linode'));
    }

    public function test_vultr_create_sends_the_plan_image_base64_user_data_and_tags(): void
    {
        $this->connect('vultr');
        $this->respond = fn (Request $r) => $r->method() === 'POST' ? Http::response(['instance' => ['id' => 'abc-123']], 202) : Http::response(['instances' => []]);

        $id = $this->provider('vultr')->servers('org-1')->create($this->spec('ewr', 'vc2-1c-2gb', '2284'));

        $this->assertSame('abc-123', $id);
        $post = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST');
        $this->assertSame(['web-1', 'ewr', 'vc2-1c-2gb', 2284, base64_encode('#cloud-config')], [$post['label'], $post['region'], $post['plan'], $post['os_id'], $post['user_data']]);
        $this->assertSame(['cloud-manager', 'org-org-1', 'resource-web-1'], $post['tags']);
    }

    public function test_vultr_finds_an_instance_by_its_exact_label_instead_of_making_a_second(): void
    {
        $this->connect('vultr');
        $this->respond = fn () => Http::response(['instances' => [['id' => 'other', 'label' => 'web-10'], ['id' => 'mine', 'label' => 'web-1']]]);

        $this->assertSame('mine', $this->provider('vultr')->servers('org-1')->create($this->spec('ewr', 'vc2-1c-2gb', '2284')));
        $this->assertNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST'));
    }

    public function test_vultr_states_addresses_listing_and_destroy(): void
    {
        $this->connect('vultr');
        $driver = $this->provider('vultr')->servers('org-1');

        $this->respond = fn () => Http::response(['instance' => ['id' => 'a', 'label' => 'web-1', 'status' => 'pending', 'power_status' => 'running', 'main_ip' => '0.0.0.0', 'internal_ip' => '']]);
        $pending = $driver->find('a');
        $this->assertSame(['pending', null, null], [$pending->state, $pending->ipv4, $pending->privateIpv4]);

        $this->respond = fn () => Http::response(['instance' => ['id' => 'a', 'label' => 'web-1', 'status' => 'active', 'power_status' => 'running', 'main_ip' => '203.0.113.9', 'internal_ip' => '10.1.0.4', 'v6_main_ip' => '2001:db8:1::5']]);
        $running = $driver->find('a');
        $this->assertSame(['running', '203.0.113.9', '10.1.0.4', '2001:db8:1::5'], [$running->state, $running->ipv4, $running->privateIpv4, $running->ipv6]);

        $this->respond = fn () => Http::response(['error' => 'Invalid instance ID'], 404);
        $this->assertNull($driver->find('a'));
        $driver->destroy('a');

        $this->respond = fn () => Http::response(['instances' => [['id' => 'x', 'status' => 'active', 'power_status' => 'stopped', 'label' => 'a']]]);
        $this->assertSame(['stopped'], array_map(fn ($s) => $s->state, $driver->listTagged('org:org-1')));
        $this->assertSame('org-org-1', collect($this->sent)->last()->data()['tag']);
    }

    public function test_linode_create_sends_the_type_image_base64_metadata_a_throwaway_password_and_tags(): void
    {
        $this->connect('linode');
        $this->respond = fn (Request $r) => $r->method() === 'POST' ? Http::response(['id' => 9001], 200) : Http::response(['data' => []]);

        $id = $this->provider('linode')->servers('org-1')->create($this->spec('us-east', 'g6-standard-1', 'linode/ubuntu24.04'));

        $this->assertSame('9001', $id);
        $post = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST');
        $this->assertSame(['web-1', 'us-east', 'g6-standard-1', 'linode/ubuntu24.04'], [$post['label'], $post['region'], $post['type'], $post['image']]);
        $this->assertSame(['user_data' => base64_encode('#cloud-config')], $post['metadata']);
        $this->assertSame(['cloud-manager', 'org-org-1', 'resource-web-1'], $post['tags']);
        $this->assertGreaterThanOrEqual(32, strlen($post['root_pass']));
    }

    public function test_linode_finds_the_instance_by_label_with_the_filter_header_instead_of_making_a_second(): void
    {
        $this->connect('linode');
        $this->respond = fn () => Http::response(['data' => [['id' => 77, 'label' => 'web-1']]]);

        $this->assertSame('77', $this->provider('linode')->servers('org-1')->create($this->spec('us-east', 'g6-standard-1', 'linode/ubuntu24.04')));
        $this->assertSame('{"label":"web-1"}', collect($this->sent)->last()->header('X-Filter')[0]);
        $this->assertNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST'));
    }

    public function test_linode_states_listing_and_destroy(): void
    {
        $this->connect('linode');
        $driver = $this->provider('linode')->servers('org-1');

        foreach (['provisioning' => 'pending', 'booting' => 'pending', 'running' => 'running', 'offline' => 'stopped', 'shutting_down' => 'stopped'] as $status => $state) {
            $this->respond = fn () => Http::response(['id' => 1, 'label' => 'web-1', 'status' => $status, 'ipv4' => ['203.0.113.9'], 'ipv6' => '2001:db8:2::7/128']);
            $server = $driver->find('1');
            $this->assertSame([$state, '203.0.113.9', '2001:db8:2::7'], [$server->state, $server->ipv4, $server->ipv6], $status);
        }

        $this->respond = fn () => Http::response(['errors' => [['reason' => 'Not found']]], 404);
        $this->assertNull($driver->find('1'));
        $driver->destroy('1');

        $this->respond = fn () => Http::response(['data' => [['id' => 5, 'status' => 'running', 'label' => 'a', 'ipv4' => ['198.51.100.2']]]]);
        $this->assertSame(['5'], array_map(fn ($s) => $s->id, $driver->listTagged('org:org-1')));
        $this->assertSame('{"tags":"org-org-1"}', collect($this->sent)->last()->header('X-Filter')[0]);
    }

    public function test_a_refusal_is_rejected_and_a_server_error_or_rate_limit_is_uncertain(): void
    {
        $this->connect('vultr');
        $driver = $this->provider('vultr')->servers('org-1');
        $post = fn (int $status, array $body) => fn (Request $r) => $r->method() === 'POST' ? Http::response($body, $status) : Http::response(['instances' => []]);

        $this->respond = $post(400, ['error' => 'Plan is not available in this region']);
        try {
            $driver->create($this->spec('ewr', 'vc2-1c-2gb', '2284'));
            $this->fail();
        } catch (Rejected $e) {
            $this->assertStringContainsString('not available in this region', $e->getMessage());
        }

        foreach ([503, 429] as $status) {
            $this->respond = $post($status, []);
            try {
                $driver->create($this->spec('ewr', 'vc2-1c-2gb', '2284'));
                $this->fail();
            } catch (Uncertain) {
                $this->addToAssertionCount(1);
            }
        }
    }

    #[DataProvider('providers')]
    public function test_the_provisioner_builds_and_destroys_a_server_with_no_provider_specific_code(string $id): void
    {
        $this->connect($id);
        $agent = sys_get_temp_dir().'/cm-tp-agent-'.getmypid();
        @mkdir($agent, 0o755, true);
        file_put_contents($agent.'/cloud-manager-agent-linux-amd64', 'pretend');
        config(['control_plane.agent_directory' => $agent, 'control_plane.public_url' => 'https://cp.example.test']);
        foreach ([AgentRelease::class, CloudInit::class, ServerProvisioner::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $catalog = $this->provider($id)->catalog();
        $vultr = $id === 'vultr';
        $this->respond = fn (Request $r) => match (true) {
            $r->method() === 'POST' => Http::response($vultr ? ['instance' => ['id' => 'inst-1']] : ['id' => 4242], 201),
            $r->method() === 'GET' && str_ends_with($r->url(), $vultr ? '/instances/inst-1' : '/linode/instances/4242') => Http::response($vultr
                ? ['instance' => ['id' => 'inst-1', 'label' => 'web-1', 'status' => 'active', 'power_status' => 'running', 'main_ip' => '203.0.113.9']]
                : ['id' => 4242, 'label' => 'web-1', 'status' => 'running', 'ipv4' => ['203.0.113.9']]),
            $r->method() === 'DELETE' => Http::response('', 204),
            default => Http::response($vultr ? ['instances' => []] : ['data' => []]),
        };

        $record = $this->app->make(ServerProvisioner::class)->provision('org-1', 'web-1', ['provider' => $id, 'region' => $catalog->defaultRegion, 'size' => array_key_first($catalog->sizes)]);

        $this->assertSame([ResourceState::Active, $id, $vultr ? 'inst-1' : '4242', '203.0.113.9'], [$record->state, $record->provider, $record->externalId, $record->attributes['ipv4']]);
        $this->app->make(ServerProvisioner::class)->destroy($record);
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'web-1')->value('state'));
        $this->assertNotNull(collect($this->sent)->first(fn (Request $r) => $r->method() === 'DELETE'));
        array_map('unlink', glob($agent.'/*'));
        @rmdir($agent);
    }

    #[DataProvider('providers')]
    public function test_the_account_page_connects_tests_and_disconnects_it(string $id): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $this->respond = fn () => Http::response(['account' => []]);

        $this->post("/account/token/{$id}", ['token' => self::TOKEN])->assertRedirect();
        $this->post("/account/token/{$id}/test")->assertSessionHasNoErrors();
        $page = $this->get('/account?tab=providers')->assertOk();
        $row = collect($page->inertiaProps('providers.tokenProviders'))->firstWhere('id', $id);
        $this->assertSame('tok_••••••••7890', $row['credential']['hint']);
        $this->assertStringNotContainsString(self::TOKEN, $page->getContent());

        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'provider' => $id, 'slug' => 'web-1', 'external_id' => '1', 'state' => 'active', 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        $this->delete("/account/token/{$id}")->assertSessionHasErrors("provider_{$id}");
        DB::table('resources')->delete();
        $this->delete("/account/token/{$id}")->assertSessionHasNoErrors();
        $this->assertFalse($this->app->make(CredentialStore::class)->has('org-1', $id));
    }

    public function test_an_unknown_provider_has_no_token_actions(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));

        $this->post('/account/token/digitalocean', ['token' => 'x'])->assertNotFound();
    }
}
