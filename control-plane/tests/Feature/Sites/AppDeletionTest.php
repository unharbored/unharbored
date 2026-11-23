<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Deleting an application removes it from the console without taking a
 * server or a shared cluster with it, and keeps its data unless asked.
 */
final class AppDeletionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    private bool $nodeConnected = true;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();

        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $http = new HttpFactory();
        $http->fake(function (Request $r) use ($http) {
            $this->gateway[] = ['kind' => $r['kind'] ?? null, 'args' => $r['args'] ?? []];
            if (str_contains($r->url(), '/v1/nodes/') && ! str_ends_with($r->url(), '/commands')) {
                return $http->response(['connected' => $this->nodeConnected]);
            }

            if (! $this->nodeConnected) {
                return $http->response(['error' => 'not connected'], 409);
            }

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
    }

    private function site(string $name = 'shop'): object
    {
        return $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', $name, "{$name}.example.com", 'git@github.com:acme/shop.git');
    }

    private function runner(): OperationRunner
    {
        return $this->app->make(OperationRunner::class);
    }

    /** @return list<string> */
    private function recipeSteps(): array
    {
        $steps = [];
        foreach ($this->gateway as $call) {
            if ($call['kind'] === 'apply_recipe') {
                array_push($steps, ...array_column($call['args']['spec']['steps'] ?? [], 'id'));
            }
        }

        return $steps;
    }

    public function test_deleting_an_application_keeps_its_data_by_default(): void
    {
        $site = $this->site();
        $vault = $this->app->make(Vault::class);
        $this->assertNotEmpty($vault->names('org-1'));

        $id = $this->runner()->queueAppDelete((int) $site->id, false, 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id));

        $this->assertFalse(DB::table('sites')->where('id', $site->id)->exists());
        $archive = DB::table('site_archives')->where('name', 'shop')->first();
        $this->assertNotNull($archive);
        $this->assertSame('/home/deploy/shop', $archive->directory);
        $this->assertNotContains('files', $this->recipeSteps(), 'The directory is kept.');
        $this->assertNotEmpty($vault->names('org-1'), 'Secrets stay so the data can be reached.');
        $this->assertDatabaseHas('audit_records', ['action' => 'site.archived']);
    }

    public function test_an_application_never_deployed_is_deleted_even_when_its_server_cannot_be_reached(): void
    {
        $this->nodeConnected = false;
        $site = $this->site();
        $id = $this->runner()->queueAppDelete((int) $site->id, false, 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id));
        $this->assertFalse(DB::table('sites')->where('id', $site->id)->exists());

        // One that was deployed may have things on the server, so it stays until the server answers.
        $deployed = $this->site('blog');
        DB::table('sites')->where('id', $deployed->id)->update(['current_release' => 'r1']);
        $id = $this->runner()->queueAppDelete((int) $deployed->id, false, 'console', 'me');
        $this->assertSame('failed', $this->runner()->run($id));
        $this->assertTrue(DB::table('sites')->where('id', $deployed->id)->exists());
    }

    public function test_deleting_an_application_with_its_data_forgets_everything(): void
    {
        $site = $this->site();
        $vault = $this->app->make(Vault::class);

        $id = $this->runner()->queueAppDelete((int) $site->id, true, 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id));

        $this->assertFalse(DB::table('sites')->where('id', $site->id)->exists());
        $this->assertSame(0, DB::table('site_archives')->count());
        $this->assertContains('files', $this->recipeSteps());
        $this->assertSame([], array_filter($vault->names('org-1'), fn ($n) => str_contains($n, 'shop')));
        $this->assertDatabaseHas('audit_records', ['action' => 'site.deleted']);
    }

    public function test_a_preview_is_not_deleted_as_an_application(): void
    {
        $parent = $this->site();
        DB::table('sites')->insert(['project_id' => 1, 
            'organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'name' => 'shop-main', 'domain' => 'main.shop.example.com',
            'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'deploy_key_public' => 'x', 'parent_site_id' => $parent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $preview = DB::table('sites')->where('name', 'shop-main')->first();

        $this->expectException(InvalidArgumentException::class);
        $this->runner()->queueAppDelete((int) $preview->id, true, 'console', 'me');
    }

    public function test_an_application_with_previews_is_refused(): void
    {
        $parent = $this->site();
        DB::table('sites')->insert(['project_id' => 1, 
            'organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'name' => 'shop-main', 'domain' => 'main.shop.example.com',
            'repository' => 'git@github.com:acme/shop.git', 'branch' => 'main', 'deploy_key_public' => 'x', 'parent_site_id' => $parent->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            $this->runner()->queueAppDelete((int) $parent->id, true, 'console', 'me');
            $this->fail('Should have refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('previews first', $e->getMessage());
        }
        $this->assertTrue(DB::table('sites')->where('id', $parent->id)->exists());
    }

    public function test_a_running_operation_blocks_deletion(): void
    {
        $site = $this->site();
        DB::table('operations')->insert([
            'organization_id' => 'org-1', 'kind' => 'deploy', 'title' => 'Deploy shop', 'trigger' => 'console', 'actor' => 'me',
            'site_id' => $site->id, 'node_id' => 'web-1.org-1', 'status' => 'running', 'stages' => json_encode([]), 'log' => '',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->runner()->queueAppDelete((int) $site->id, true, 'console', 'me');
    }

    public function test_a_kept_applications_name_cannot_be_reused(): void
    {
        $site = $this->site();
        $this->assertSame('succeeded', $this->runner()->run($this->runner()->queueAppDelete((int) $site->id, false, 'console', 'me')));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('data was kept');
        $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
    }

    public function test_a_name_owned_by_old_cloud_resources_cannot_be_reused(): void
    {
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'bucket', 'slug' => 'shop', 'external_id' => 'b',
            'state' => 'active', 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('still owns cloud resources');
        $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
    }

    public function test_autoscaling_with_local_uploads_is_not_deleted_without_its_data(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['autoscale_pool_id' => 7]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keeps uploads on its own servers');
        $this->runner()->queueAppDelete((int) $site->id, false, 'console', 'me');
    }

    // Audit 2026-11-08, finding 6: a saved FILESYSTEM_DISK=s3 is not proof that uploads left the servers.
    public function test_a_saved_s3_setting_alone_does_not_let_a_pool_be_deleted_with_its_uploads(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['autoscale_pool_id' => 7]);
        $this->app->make(\App\Lib\Sites\Sites::class)->setEnv($this->site2($site), "FILESYSTEM_DISK=s3\n", 'test');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('keeps uploads on its own servers');
        $this->runner()->queueAppDelete((int) $site->id, false, 'console', 'me');
    }

    private function site2(object $site): object
    {
        return DB::table('sites')->find($site->id);
    }

    public function test_destructive_deletion_removes_preview_overrides(): void
    {
        $site = $this->site();
        $previews = $this->app->make(\App\Lib\Sites\Previews::class);
        $previews->setOverrides($site, 'OAUTH_CLIENT_SECRET=super-secret');
        $this->assertNotNull($this->app->make(Vault::class)->get('org-1', "site:{$site->id}:preview_env"));

        $this->assertSame('succeeded', $this->runner()->run($this->runner()->queueAppDelete((int) $site->id, true, 'console', 'me')));

        $this->assertNull($this->app->make(Vault::class)->get('org-1', "site:{$site->id}:preview_env"));
    }

    public function test_retained_data_can_be_deleted_to_free_its_name(): void
    {
        $site = $this->site();
        $this->runner()->run($this->runner()->queueAppDelete((int) $site->id, false, 'console', 'me'));
        $archive = DB::table('site_archives')->where('name', 'shop')->sole();

        $id = $this->runner()->queueArchiveDestroy((int) $archive->id, 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $this->assertSame(0, DB::table('site_archives')->count());
        $this->assertSame([], array_filter($this->app->make(Vault::class)->names('org-1'), fn ($n) => str_contains($n, 'shop')));
        $made = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $this->assertSame('shop', $made->name);
    }
}
