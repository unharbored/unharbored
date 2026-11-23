<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Cloud\CredentialStore;
use App\Lib\Providers\Providers;
use App\Lib\Providers\Vultr\VultrProvider;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Regions and sizes come from the provider's own API, are kept, and fall back to the built-in list when it cannot be asked. */
final class LiveCatalogTest extends TestCase
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

    private function providers(): Providers
    {
        return $this->app->make(Providers::class);
    }

    private function connect(string $id): void
    {
        $this->respond = fn () => Http::response(['account' => [], 'username' => 'a', 'servers' => []]);
        $this->providers()->get($id)->connect('org-1', 'tok_1234567890');
        $this->sent = [];
    }

    private function vultrAnswers(): \Closure
    {
        return fn (Request $r) => str_contains($r->url(), '/plans')
            ? Http::response(['plans' => [
                ['id' => 'vc2-2c-4gb', 'vcpu_count' => 2, 'ram' => 4096, 'monthly_cost' => 24],
                ['id' => 'vc2-1c-1gb', 'vcpu_count' => 1, 'ram' => 1024, 'monthly_cost' => 6],
                ['id' => 'vc2-1c-0gb', 'vcpu_count' => 1, 'ram' => 512, 'monthly_cost' => 3.5],
            ]])
            : Http::response(['regions' => [['id' => 'ewr', 'city' => 'New Jersey'], ['id' => 'mex', 'city' => 'Mexico City']]]);
    }

    public function test_vultr_lists_what_vultr_sells_now_cheapest_first(): void
    {
        $this->connect('vultr');
        $this->respond = $this->vultrAnswers();

        $this->providers()->connected('org-1');
        $catalog = $this->providers()->get('vultr')->catalog();

        $this->assertSame(['vc2-1c-1gb', 'vc2-2c-4gb'], array_keys($catalog->sizes), 'a half-gigabyte plan is left out; the cheapest is first');
        $this->assertSame(['label' => '2 vCPU · 4 GB', 'vcpu' => 2, 'memory_gb' => 4.0, 'price' => 24.0], $catalog->sizes['vc2-2c-4gb']);
        $this->assertSame(['ewr' => 'New Jersey', 'mex' => 'Mexico City'], $catalog->regions);
        $this->assertSame(['ewr', '2284'], [$catalog->defaultRegion, $catalog->defaultImage], 'the default region and image are kept');
    }

    public function test_the_list_is_kept_and_not_asked_for_again(): void
    {
        $this->connect('vultr');
        $this->respond = $this->vultrAnswers();

        $this->providers()->connected('org-1');
        $asked = count($this->sent);
        $this->providers()->connected('org-1');
        $this->providers()->get('vultr')->catalog();

        $this->assertGreaterThan(0, $asked);
        $this->assertCount($asked, $this->sent, 'the second look used the kept list');
    }

    public function test_a_provider_that_cannot_be_asked_keeps_the_built_in_list_and_is_not_pestered(): void
    {
        $this->connect('vultr');
        $this->respond = fn () => Http::response(['error' => 'down'], 503);

        $this->providers()->connected('org-1');
        $asked = count($this->sent);
        $this->providers()->connected('org-1');

        $this->assertSame(array_keys(VultrProvider::SIZES), array_keys($this->providers()->get('vultr')->catalog()->sizes));
        $this->assertCount($asked, $this->sent, 'a failed read is not retried on the next page load');
    }

    public function test_the_last_good_list_stands_in_when_a_refresh_fails(): void
    {
        $this->connect('vultr');
        $this->respond = $this->vultrAnswers();
        $this->providers()->connected('org-1');

        $this->respond = fn () => Http::response([], 500);
        $this->artisan('providers:refresh-catalogs')->expectsOutputToContain('could not be read, keeping the last list')->assertSuccessful();

        $this->assertSame(['vc2-1c-1gb', 'vc2-2c-4gb'], array_keys($this->providers()->get('vultr')->catalog()->sizes));
    }

    public function test_the_daily_command_refreshes_each_connected_provider(): void
    {
        $this->connect('vultr');
        $this->respond = $this->vultrAnswers();

        $this->artisan('providers:refresh-catalogs')->expectsOutputToContain('vultr: refreshed')->assertSuccessful();

        $this->assertArrayHasKey('mex', $this->providers()->get('vultr')->catalog()->regions);
    }

    public function test_linode_offers_only_regions_that_have_the_metadata_service_and_shared_plans(): void
    {
        $this->connect('linode');
        $this->respond = fn (Request $r) => str_contains($r->url(), '/regions')
            ? Http::response(['data' => [
                ['id' => 'us-east', 'label' => 'Newark, NJ', 'status' => 'ok', 'capabilities' => ['Linodes', 'Metadata']],
                ['id' => 'old-1', 'label' => 'Old', 'status' => 'ok', 'capabilities' => ['Linodes']],
                ['id' => 'down-1', 'label' => 'Down', 'status' => 'outage', 'capabilities' => ['Metadata']],
            ]])
            : Http::response(['data' => [
                ['id' => 'g6-standard-2', 'class' => 'standard', 'vcpus' => 2, 'memory' => 4096, 'price' => ['monthly' => 24]],
                ['id' => 'g6-nanode-1', 'class' => 'nanode', 'vcpus' => 1, 'memory' => 1024, 'price' => ['monthly' => 5]],
                ['id' => 'g6-dedicated-4', 'class' => 'dedicated', 'vcpus' => 2, 'memory' => 4096, 'price' => ['monthly' => 36]],
            ]]);

        $this->providers()->connected('org-1');
        $catalog = $this->providers()->get('linode')->catalog();

        $this->assertSame(['us-east' => 'Newark, NJ'], $catalog->regions);
        $this->assertSame(['g6-nanode-1', 'g6-standard-2'], array_keys($catalog->sizes));
    }

    public function test_hetzner_offers_current_shared_x86_types_priced_from_the_list(): void
    {
        $this->respond = fn () => Http::response(['servers' => []]);
        $this->providers()->hetzner()->connect('org-1', 'hz_token_1234567890');
        $this->sent = [];
        $this->respond = fn (Request $r) => str_contains($r->url(), '/locations')
            ? Http::response(['locations' => [['name' => 'nbg1', 'city' => 'Nuremberg'], ['name' => 'hel1', 'city' => 'Helsinki']]])
            : Http::response(['server_types' => [
                ['name' => 'cx33', 'architecture' => 'x86', 'cpu_type' => 'shared', 'cores' => 4, 'memory' => 8, 'deprecated' => false, 'prices' => [['price_monthly' => ['gross' => '7.1900']]]],
                ['name' => 'cx23', 'architecture' => 'x86', 'cpu_type' => 'shared', 'cores' => 2, 'memory' => 4, 'deprecated' => false, 'prices' => [['price_monthly' => ['gross' => '4.0900']]]],
                ['name' => 'cax11', 'architecture' => 'arm', 'cpu_type' => 'shared', 'cores' => 2, 'memory' => 4, 'deprecated' => false, 'prices' => [['price_monthly' => ['gross' => '4.0000']]]],
                ['name' => 'cx22', 'architecture' => 'x86', 'cpu_type' => 'shared', 'cores' => 2, 'memory' => 4, 'deprecated' => true, 'prices' => [['price_monthly' => ['gross' => '3.0000']]]],
                ['name' => 'ccx13', 'architecture' => 'x86', 'cpu_type' => 'dedicated', 'cores' => 2, 'memory' => 8, 'deprecated' => false, 'prices' => [['price_monthly' => ['gross' => '12.0000']]]],
            ]]);

        $this->providers()->connected('org-1');
        $catalog = $this->providers()->hetzner()->catalog();

        $this->assertSame(['cx23', 'cx33'], array_keys($catalog->sizes));
        $this->assertSame(4.09, $catalog->sizes['cx23']['price']);
        $this->assertSame(['nbg1' => 'Nuremberg', 'hel1' => 'Helsinki'], $catalog->regions);
        $this->assertSame('nbg1', $catalog->defaultRegion);
    }

    public function test_a_default_region_the_provider_has_retired_gives_way_to_one_it_has(): void
    {
        $this->connect('linode');
        $this->respond = fn (Request $r) => str_contains($r->url(), '/regions')
            ? Http::response(['data' => [['id' => 'eu-west', 'label' => 'London, UK', 'status' => 'ok', 'capabilities' => ['Metadata']]]])
            : Http::response(['data' => [['id' => 'g6-nanode-1', 'class' => 'nanode', 'vcpus' => 1, 'memory' => 1024, 'price' => ['monthly' => 5]]]]);

        $this->providers()->connected('org-1');

        $this->assertSame('eu-west', $this->providers()->get('linode')->catalog()->defaultRegion);
    }
}
