<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Saved variables can be put on the running application without a deploy: .env, config cache, FPM, workers. */
final class ApplyVariablesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('nodes')->insert([
            ['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()],
            ['node_id' => 'web-2.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()],
        ]);
        $http = new HttpFactory;
        $http->fake(function (ClientRequest $r) use ($http) {
            preg_match('#/v1/nodes/([^/]+)/commands#', $r->url(), $m);
            $this->gateway[] = ['kind' => $r['kind'] ?? null, 'node' => $m[1] ?? null, 'spec' => $r['args']['spec'] ?? null];

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog));
        $this->actingAs(User::factory()->create());
    }

    private function live(): object
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['current_release' => '20261118T010101Z-main']);

        return DB::table('sites')->find($site->id);
    }

    public function test_only_the_variables_reach_every_server_and_the_config_cache_is_rebuilt_after_the_write(): void
    {
        $site = $this->live();
        DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);

        $id = $this->app->make(OperationRunner::class)->queueEnvApply((int) $site->id, 'console', 'me');
        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $applied = array_values(array_filter($this->gateway, fn ($c) => $c['kind'] === 'apply_recipe'));
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], array_column($applied, 'node'));
        foreach ($applied as $call) {
            $this->assertSame('variables-shop', $call['spec']['name']);
            $this->assertSame(['env', 'queue-restart-before', 'config-cache', 'reload-php-fpm', 'queue-restart'], array_column($call['spec']['steps'], 'id'), 'No clone, composer or migration; the cache is rebuilt after the write.');
            $this->assertSame('/home/deploy/shop/shared/.env', $call['spec']['steps'][0]['path']);
            $this->assertContains('config:cache', $call['spec']['steps'][2]['args']);
            $this->assertStringEndsWith('|| true', $call['spec']['steps'][1]['args'][1], 'A cache that cannot take the signal does not fail the rest.');
        }
    }

    public function test_nothing_live_or_not_php_is_refused(): void
    {
        $site = $this->live();
        DB::table('sites')->where('id', $site->id)->update(['current_release' => null]);
        $this->get("/site/settings?site={$site->id}&tab=env")->assertInertia(fn (Assert $p) => $p->where('data.canApply', false));
        $this->post("/site/settings/{$site->id}/env/apply")->assertSessionHasErrors('envText');

        DB::table('sites')->where('id', $site->id)->update(['current_release' => '20261118T010101Z-main', 'runtime' => 'node']);
        $this->post("/site/settings/{$site->id}/env/apply")->assertSessionHasErrors('envText');
        $this->assertSame(0, DB::table('operations')->where('kind', 'env_apply')->count());
    }

    public function test_the_console_queues_it_for_a_live_php_application(): void
    {
        $site = $this->live();

        $this->get("/site/settings?site={$site->id}&tab=env")->assertInertia(fn (Assert $p) => $p->where('data.canApply', true));
        $response = $this->post("/site/settings/{$site->id}/env/apply");

        $op = DB::table('operations')->where('kind', 'env_apply')->first();
        $this->assertSame('Apply the variables of shop', $op->title);
        $response->assertRedirect("/operation?operation={$op->id}");
    }

    public function test_the_default_build_commands_are_the_composer_install_and_the_own_ones_replace_it(): void
    {
        $site = $this->live();
        $deployer = $this->app->make(Deployer::class);
        $this->assertSame(['composer', 'migrate', 'optimize'], array_column($deployer->buildRecipe('/r')['steps'], 'id'), 'Until it writes its own, it is the fixed Composer step.');
        $this->get("/site/settings?site={$site->id}&tab=build")->assertInertia(fn (Assert $p) => $p->where('data.buildScript.script', Sites::DEFAULT_BUILD_SCRIPT)->where('data.buildScript.custom', false));

        $own = Sites::DEFAULT_BUILD_SCRIPT."\nnpm ci\r\nnpm run build\n";
        $this->post("/site/settings/{$site->id}/build-script", ['script' => $own, 'node' => ''])->assertSessionHasNoErrors();
        $row = DB::table('sites')->find($site->id);
        $this->assertSame(Sites::DEFAULT_BUILD_SCRIPT."\nnpm ci\nnpm run build", $row->build_script);
        $steps = $deployer->buildRecipe('/r', composerHome: '/home/deploy/shop/composer', scriptOf: $row)['steps'];

        $this->assertSame(['mise', 'toolchain', 'build-script', 'migrate', 'optimize'], array_column($steps, 'id'), 'The fixed Composer step is replaced; Node is installed because npm is used.');
        $this->assertStringContainsString('node@22', implode(' ', $steps[1]['args']));
        $run = end($steps[2]['args']);
        $this->assertStringContainsString("cd '/r' && ", $run);
        $this->assertStringContainsString('/usr/bin/php8.4 /usr/local/bin/composer', $run, '$COMPOSER is Composer run with this application\'s PHP.');
        $this->assertStringContainsString('COMPOSER_HOME=', $run);
        $this->assertStringContainsString('/home/deploy/shop/composer', $run);
        $this->assertStringContainsString("set -e\n\$COMPOSER install --no-dev", $run);
        $this->assertStringContainsString("\nnpm ci\nnpm run build", $run, 'Every line runs, stopping at the first failure.');
        $this->assertStringNotContainsString('.env', $run, 'A Laravel .env is not sourced as shell.');
    }

    public function test_node_is_installed_only_when_the_commands_use_it_or_a_version_is_given(): void
    {
        $site = $this->live();
        $deployer = $this->app->make(Deployer::class);
        $ids = fn (string $script, string $node) => array_column($deployer->buildRecipe('/r', scriptOf: (object) array_replace((array) $site, ['build_script' => $script, 'node_version' => $node === '' ? null : $node]))['steps'], 'id');

        $this->assertSame(['build-script', 'migrate', 'optimize'], $ids('$COMPOSER install'.PHP_EOL.'php artisan storage:link', ''));
        $this->assertSame(['mise', 'toolchain', 'build-script', 'migrate', 'optimize'], $ids('$COMPOSER install'.PHP_EOL.'pnpm build', ''));
        $this->assertSame(['mise', 'toolchain', 'build-script', 'migrate', 'optimize'], $ids('$COMPOSER install', '20'));
    }

    public function test_the_build_commands_and_version_are_checked_and_saving_the_default_goes_back_to_it(): void
    {
        $site = $this->live();

        foreach ([["npm run build\x07", ''], ['npm run build', 'twenty'], [str_repeat('a', 5001), '']] as [$script, $node]) {
            $this->post("/site/settings/{$site->id}/build-script", ['script' => $script, 'node' => $node])->assertSessionHasErrors('buildScript');
        }
        $this->post("/site/settings/{$site->id}/build-script", ['script' => 'npm run build', 'node' => '20'])->assertSessionHasNoErrors();
        $this->assertSame('20', DB::table('sites')->find($site->id)->node_version);
        $this->post("/site/settings/{$site->id}/build-script", ['script' => Sites::DEFAULT_BUILD_SCRIPT, 'node' => '20'])->assertSessionHasNoErrors();
        $row = DB::table('sites')->find($site->id);
        $this->assertNull($row->build_script);
        $this->assertNull($row->node_version);
    }
}
