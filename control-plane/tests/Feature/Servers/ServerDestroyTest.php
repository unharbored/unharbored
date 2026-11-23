<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Destroying a server deletes a Droplet the platform created and stops its
 * billing, but only once nothing depends on it.
 */
final class ServerDestroyTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        foreach ([KeyManager::class, Vault::class, CredentialStore::class, GatewayClient::class] as $c) {
            $this->app->forgetInstance($c);
        }
        Queue::fake();

        $this->http = new HttpFactory();
        $this->http->fake(function ($request) {
            if (str_contains($request->url(), '/v1/organisations')) {
                return $this->http->response(['organisations' => 0, 'disconnected' => 0]);
            }

            return $this->http->response([], 204);
        });
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()));

        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Test Org', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->make(CredentialStore::class)->connect('org-1', 'dop_v1_test_token');

        DB::table('nodes')->insert(['node_id' => 'web-9.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-9', 'external_id' => '555',
            'state' => 'active', 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function runner(): OperationRunner
    {
        return $this->app->make(OperationRunner::class);
    }

    public function test_destroying_a_platform_droplet_deletes_it_and_removes_the_node(): void
    {
        $id = $this->runner()->queueServerDestroy('web-9.org-1', 'console', 'me');

        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $this->assertSame('deleted', DB::table('resources')->where('slug', 'web-9')->value('state'));
        $this->assertFalse(DB::table('nodes')->where('node_id', 'web-9.org-1')->exists());
        $this->http->assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/droplets/555'));
    }

    public function test_a_server_serving_a_secondary_application_is_not_destroyed(): void
    {
        $siteId = DB::table('sites')->insertGetId(['project_id' => 1, 
            'organization_id' => 'org-1', 'node_id' => 'other.org-1', 'name' => 'shop', 'domain' => 'shop.example.com',
            'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'deploy_key_public' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('site_nodes')->insert(['site_id' => $siteId, 'node_id' => 'web-9.org-1', 'created_at' => now(), 'updated_at' => now()]);

        try {
            $this->runner()->queueServerDestroy('web-9.org-1', 'console', 'me');
            $this->fail('Should have refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('still serves shop', $e->getMessage());
        }
        $this->assertSame('active', DB::table('resources')->where('slug', 'web-9')->value('state'));
    }

    public function test_a_server_the_platform_did_not_create_is_not_destroyed(): void
    {
        DB::table('resources')->where('slug', 'web-9')->delete();

        $this->expectException(InvalidArgumentException::class);
        $this->runner()->queueServerDestroy('web-9.org-1', 'console', 'me');
    }

    public function test_a_pool_member_is_not_destroyed_on_its_own(): void
    {
        $siteId = DB::table('sites')->insertGetId(['project_id' => 1, 
            'organization_id' => 'org-1', 'node_id' => 'other.org-1', 'name' => 'shop', 'domain' => 'shop.example.com',
            'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'deploy_key_public' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('site_nodes')->insert(['site_id' => $siteId, 'node_id' => 'web-9.org-1', 'autoscale_pool_id' => 7,
            'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(InvalidArgumentException::class);
        $this->runner()->queueServerDestroy('web-9.org-1', 'console', 'me');
    }

    public function test_a_pool_owned_droplet_is_not_destroyed_before_its_join_finishes(): void
    {
        DB::table('resources')->where('slug', 'web-9')->update(['attributes' => json_encode(['pool' => 7])]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('autoscale pool');
        $this->runner()->queueServerDestroy('web-9.org-1', 'console', 'me');
    }

    public function test_a_server_holding_retained_data_is_not_destroyed(): void
    {
        DB::table('site_archives')->insert([
            'organization_id' => 'org-1', 'original_site_id' => 1, 'name' => 'shop', 'domain' => 'shop.example.com',
            'node_id' => 'web-9.org-1', 'servers' => json_encode(['web-9.org-1']), 'directory' => '/home/deploy/shop',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('retained data');
        $this->runner()->queueServerDestroy('web-9.org-1', 'console', 'me');
    }

    public function test_a_disconnected_droplet_can_still_be_destroyed(): void
    {
        DB::table('nodes')->where('node_id', 'web-9.org-1')->delete();
        $resourceId = (int) DB::table('resources')->where('slug', 'web-9')->value('id');

        $id = $this->runner()->queueDropletDestroy($resourceId, 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $this->assertSame('deleted', DB::table('resources')->where('slug', 'web-9')->value('state'));
        $this->http->assertSent(fn ($request) => $request->method() === 'DELETE' && str_ends_with($request->url(), '/droplets/555'));
    }

    // Audit 2026-11-08, finding 5: destroying by Droplet id carried no node, so the
    // live-application checks were skipped.
    public function test_destroying_by_droplet_id_still_refuses_a_server_that_hosts_an_application(): void
    {
        DB::table('sites')->insert(['project_id' => 1, 
            'organization_id' => 'org-1', 'node_id' => 'web-9.org-1', 'name' => 'shop', 'domain' => 'shop.example.com',
            'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'deploy_key_public' => 'x',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $resource = (int) DB::table('resources')->where('slug', 'web-9')->value('id');

        // Refused when it is queued...
        try {
            $this->runner()->queueDropletDestroy($resource, 'console', 'me');
            $this->fail('A Droplet hosting an application was queued for destruction.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('still serves shop', $e->getMessage());
        }

        // ...and again when it runs, if an operation was queued before the application arrived.
        $op = app(\App\Lib\Operations\Operations::class)->create('org-1', 'server_destroy', 'Destroy web-9', 'console', 'me', ['destroy'], meta: ['slug' => 'web-9', 'resource' => $resource]);
        $this->assertSame('failed', $this->runner()->run($op));
        $this->assertStringContainsString('still serves shop', (string) DB::table('operations')->where('id', $op)->value('error'));
        $this->http->assertNotSent(fn ($request) => $request->method() === 'DELETE');
    }
}
