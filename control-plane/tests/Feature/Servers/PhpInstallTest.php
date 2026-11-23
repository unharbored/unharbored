<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Installing and patching PHP branches: a server can carry several, and a
 * patch update names the applications that share it.
 */
final class PhpInstallTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            $this->gateway[] = ['kind' => $r['kind'] ?? null, 'args' => $r['args'] ?? []];

            // The version probe answers like a server with two branches.
            $probe = str_contains(implode(' ', $r['args']['args'] ?? []), 'PHP_VERSION');

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $probe ? "8.3 8.3.27\n8.4 8.4.16\n" : '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
    }

    private function runner(): OperationRunner
    {
        return $this->app->make(OperationRunner::class);
    }

    public function test_installing_a_php_branch_applies_its_recipe_and_records_it(): void
    {
        $id = $this->runner()->queueInstallPhp('web-1.org-1', '8.3', 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $specs = array_column(array_filter($this->gateway, fn ($c) => $c['kind'] === 'apply_recipe'), 'args');
        $this->assertContains('php-8.3', array_map(fn ($a) => $a['spec']['name'] ?? null, $specs));
        $this->assertTrue(DB::table('server_php_versions')->where('node_id', 'web-1.org-1')->where('version', '8.3')->exists());
    }

    public function test_updating_a_branch_restarts_the_apps_that_use_it(): void
    {
        DB::table('sites')->insert(['project_id' => 1, 'organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'name' => 'shop',
            'domain' => 'shop.example.com', 'repository' => 'git@github.com:a/b.git', 'branch' => 'main', 'deploy_key_public' => 'x',
            'php_version' => '8.4', 'current_release' => '20260101T000000Z-main', 'created_at' => now(), 'updated_at' => now()]);

        $id = $this->runner()->queueUpdatePhp('web-1.org-1', '8.4', 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $specs = array_map(fn ($c) => $c['args']['spec']['name'] ?? null, array_filter($this->gateway, fn ($c) => $c['kind'] === 'apply_recipe'));
        $this->assertContains('php-update-8.4', $specs);

        $restart = array_filter($this->gateway, fn ($c) => $c['kind'] === 'exec'
            && str_contains(implode(' ', $c['args']['args'] ?? []), 'queue:restart'));
        $this->assertNotEmpty($restart, 'The application using the branch is restarted onto it.');
    }

    public function test_updating_a_branch_records_each_branch_s_full_version_for_the_servers_page(): void
    {
        $id = $this->runner()->queueUpdatePhp('web-1.org-1', '8.4', 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $this->assertSame(['8.3' => '8.3.27', '8.4' => '8.4.16'],
            json_decode((string) DB::table('nodes')->where('node_id', 'web-1.org-1')->value('php_patches'), true));
        $this->assertStringContainsString('php8.4 is now 8.4.16', (string) DB::table('operations')->where('id', $id)->value('log'));

        $this->actingAs(\App\Models\User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $node = $this->get('/servers/web-1.org-1')->assertOk()->viewData('page')['props']['node'];
        $this->assertSame('8.4.16', $node['phpPatches']['8.4']);
    }
}
