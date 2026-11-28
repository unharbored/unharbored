<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use App\Lib\Servers\ServerArchiving;
use App\Lib\Servers\Underneath;
use App\Lib\Sites\Archiving;
use App\Lib\Sites\Sites;
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
 * Deleting a server lists everything under it, lets the person decide per item, and runs it in order:
 * applications, retained data, the database server, then the machine.
 */
final class ServerDeleteTest extends TestCase
{
    use RefreshDatabase;

    private bool $nodeConnected = true;

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        foreach ([KeyManager::class, Vault::class, CredentialStore::class, GatewayClient::class] as $c) {
            $this->app->forgetInstance($c);
        }
        Queue::fake();

        $this->http = new HttpFactory;
        $this->http->fake(function (Request $r) {
            if (str_contains($r->url(), '/v1/organisations')) {
                return $this->http->response(['organisations' => 0, 'disconnected' => 0]);
            }
            if (str_contains($r->url(), '/v1/nodes/') && ! str_ends_with($r->url(), '/commands')) {
                return $this->http->response(['connected' => $this->nodeConnected]);
            }
            if (str_contains($r->url(), 'digitalocean')) {
                return $this->http->response([], 204);
            }
            if (! $this->nodeConnected) {
                return $this->http->response(['error' => 'not connected'], 409);
            }

            return $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog));

        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Test Org', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->make(CredentialStore::class)->connect('org-1', 'dop_v1_test_token');
        $this->node('web-9');
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-9', 'external_id' => '555',
            'state' => 'active', 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function node(string $slug): void
    {
        DB::table('nodes')->insert(['node_id' => "{$slug}.org-1", 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function site(string $name, string $node = 'web-9.org-1'): object
    {
        return $this->app->make(Sites::class)->create('org-1', $node, $name, "{$name}.example.com", 'git@github.com:acme/shop.git');
    }

    private function archive(string $name = 'old'): int
    {
        return DB::table('site_archives')->insertGetId([
            'organization_id' => 'org-1', 'original_site_id' => 1, 'name' => $name, 'domain' => "{$name}.example.com",
            'node_id' => 'web-9.org-1', 'servers' => json_encode(['web-9.org-1']), 'directory' => "/home/deploy/{$name}",
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function runner(): OperationRunner
    {
        return $this->app->make(OperationRunner::class);
    }

    /** @return array<string, array<string, mixed>> */
    private function items(array $list): array
    {
        return array_column($list['items'], null, 'key');
    }

    public function test_it_lists_applications_with_archived_ones_and_previews_and_retained_data(): void
    {
        $shop = $this->site('shop');
        $blog = $this->site('blog');
        DB::table('sites')->where('id', $blog->id)->update(['load_balancer_id' => 5, 'bucket_id' => 3]);
        DB::table('sites')->insert(['project_id' => 1, 'organization_id' => 'org-1', 'node_id' => 'web-9.org-1', 'name' => 'shop-pr-1', 'domain' => 'pr1.example.com',
            'repository' => 'x', 'branch' => 'main', 'deploy_key_public' => 'x', 'parent_site_id' => $shop->id, 'created_at' => now(), 'updated_at' => now()]);
        app(Archiving::class)->archive('org-1', (int) $shop->id, Actor::system('t'));
        $archive = $this->archive();

        $list = Underneath::of('web-9.org-1');
        $items = $this->items($list);

        $this->assertSame([], $list['blockers']);
        $this->assertSame('archived_application', $items["app:{$shop->id}"]['type']);
        $this->assertStringContainsString('shop-pr-1', $items["app:{$shop->id}"]['does']);
        $this->assertSame('application', $items["app:{$blog->id}"]['type']);
        $this->assertTrue($items["app:{$blog->id}"]['costs']);
        $this->assertStringContainsString('load balancer stops billing', $items["app:{$blog->id}"]['costNote']);
        $this->assertStringContainsString('Spaces bucket, every upload in it and its key are deleted', $items["app:{$blog->id}"]['does']);
        $this->assertSame(['erase'], array_column($items["archive:{$archive}"]['choices'], 'value'));
        $this->assertSame('retained_data', $items["archive:{$archive}"]['type']);
        $this->assertContains('web-9 at DigitalOcean stops billing.', $list['summary']['stops']);
        $this->assertNotEmpty(array_filter($list['summary']['stops'], fn ($l) => str_contains($l, 'bucket')));
    }

    public function test_a_server_of_your_own_is_only_disconnected_and_keeps_billing_elsewhere(): void
    {
        DB::table('resources')->where('slug', 'web-9')->delete();

        $list = Underneath::of('web-9.org-1');

        $this->assertFalse($list['providerMade']);
        $this->assertStringContainsString('disconnected', $list['summary']['server']);
        $this->assertSame([], $list['summary']['stops']);
        $this->assertNotEmpty($list['summary']['keeps']);
    }

    public function test_a_database_server_is_listed_and_apps_on_other_servers_using_it_block(): void
    {
        $this->node('other');
        $cluster = DB::table('resources')->insertGetId(['organization_id' => 'org-1', 'type' => 'database_cluster', 'slug' => 'main', 'state' => 'active', 'desired_state' => 'active',
            'attributes' => json_encode(['mode' => 'self_run', 'own_server' => true, 'engine' => 'mysql']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->where('slug', 'web-9')->update(['attributes' => json_encode(['database_cluster' => 'main'])]);
        $inside = $this->site('inside');
        DB::table('sites')->where('id', $inside->id)->update(['database_cluster_id' => $cluster, 'database_mode' => 'cluster']);
        $outside = $this->site('outside', 'other.org-1');
        DB::table('sites')->where('id', $outside->id)->update(['database_cluster_id' => $cluster, 'database_mode' => 'cluster']);

        $list = Underneath::of('web-9.org-1');
        $items = $this->items($list);

        $this->assertSame('database_server', $items["cluster:{$cluster}"]['type']);
        $this->assertStringContainsString('left in place', $items["cluster:{$cluster}"]['does']);
        $this->assertStringContainsString('goes with the database server', $items["app:{$inside->id}"]['does']);
        $this->assertArrayNotHasKey("app:{$outside->id}", $items);
        $this->assertCount(1, $list['blockers']);
        $this->assertStringContainsString('outside on other uses the database on main', $list['blockers'][0]);

        $this->expectException(InvalidArgumentException::class);
        $this->runner()->queueServerDelete('web-9.org-1', ["cluster:{$cluster}" => 'erase', "app:{$inside->id}" => 'erase'], 'console', 'me');
    }

    public function test_a_pool_member_and_a_busy_server_are_blockers_not_items(): void
    {
        $shop = $this->site('shop', 'other.org-1');
        DB::table('site_nodes')->insert(['site_id' => $shop->id, 'node_id' => 'web-9.org-1', 'autoscale_pool_id' => 7, 'created_at' => now(), 'updated_at' => now()]);
        app(Operations::class)->create('org-1', 'stack', 'Stack', 'console', 'me', ['x'], nodeId: 'web-9.org-1');

        $list = Underneath::of('web-9.org-1');

        $this->assertSame([], $list['items']);
        $this->assertCount(2, $list['blockers']);
        $this->assertStringContainsString('autoscale pool', implode(' ', $list['blockers']));
    }

    public function test_every_choice_must_be_made_from_the_offered_ones(): void
    {
        $shop = $this->site('shop');

        try {
            $this->runner()->queueServerDelete('web-9.org-1', [], 'console', 'me');
            $this->fail('Queued without a choice.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Choose what happens to shop', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $this->runner()->queueServerDelete('web-9.org-1', ["app:{$shop->id}" => 'keep'], 'console', 'me');
    }

    public function test_it_deletes_applications_then_retained_data_then_the_server(): void
    {
        $shop = $this->site('shop');
        $blog = $this->site('blog');
        app(Archiving::class)->archive('org-1', (int) $blog->id, Actor::system('t'));
        $this->archive();

        $id = $this->runner()->queueServerDelete('web-9.org-1', ["app:{$shop->id}" => 'erase', "app:{$blog->id}" => 'erase', 'archive:'.DB::table('site_archives')->value('id') => 'erase'], 'console', 'me');
        $status = $this->runner()->run($id);

        $this->assertSame('succeeded', $status, (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame(0, DB::table('sites')->count());
        $this->assertSame(0, DB::table('site_archives')->count());
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'web-9')->value('state'));
        $this->assertFalse(DB::table('nodes')->where('node_id', 'web-9.org-1')->exists());
        $this->http->assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/droplets/555'));

        $log = (string) DB::table('operations')->where('id', $id)->value('log');
        $positions = array_map(fn ($needle) => strpos($log, $needle), ['Deleting blog', 'Deleting shop', 'Erasing retained data for old', '555 destroyed']);
        $this->assertNotContains(false, $positions);
        $this->assertSame($positions, collect($positions)->sort()->values()->all(), 'blog (archived, first by name), shop, retained data, then the server');
        $this->assertSame(3, DB::table('operations')->where('kind', 'app_delete')->orWhere('kind', 'archive_destroy')->count());
    }

    public function test_an_archived_server_is_deleted_like_any_other_and_is_restored_only_to_run_the_delete(): void
    {
        $shop = $this->site('shop');
        app(ServerArchiving::class)->archiveServer('org-1', 'web-9.org-1', Actor::system('t'));

        $id = $this->runner()->queueServerDelete('web-9.org-1', ["app:{$shop->id}" => 'erase'], 'console', 'me');

        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertStringContainsString('web-9 was archived: brought back under management', (string) DB::table('operations')->where('id', $id)->value('log'));
        $this->assertSame(0, DB::table('sites')->count());
        $this->assertFalse(DB::table('nodes')->where('node_id', 'web-9.org-1')->exists());
        $this->assertDatabaseHas('audit_records', ['action' => 'server.restored', 'resource_id' => 'web-9.org-1']);
    }

    public function test_a_server_of_your_own_is_disconnected_after_its_applications_go(): void
    {
        DB::table('resources')->where('slug', 'web-9')->delete();
        $shop = $this->site('shop');

        $id = $this->runner()->queueServerDelete('web-9.org-1', ["app:{$shop->id}" => 'erase'], 'console', 'me');

        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertFalse(DB::table('nodes')->where('node_id', 'web-9.org-1')->exists());
        $this->http->assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_contains($r->url(), 'digitalocean'));
    }

    public function test_the_first_failure_stops_the_rest_and_a_retry_carries_on(): void
    {
        $this->nodeConnected = false;
        $shop = $this->site('shop');
        DB::table('sites')->where('id', $shop->id)->update(['current_release' => 'r1']);
        $archive = $this->archive();

        $id = $this->runner()->queueServerDelete('web-9.org-1', ["app:{$shop->id}" => 'erase', "archive:{$archive}" => 'erase'], 'console', 'me');

        $this->assertSame('failed', $this->runner()->run($id));
        $this->assertStringContainsString('Deleting shop and erasing its data did not finish', (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertTrue(DB::table('site_archives')->where('id', $archive)->exists(), 'Nothing after the failed step ran.');
        $this->assertSame('active', DB::table('resources')->where('slug', 'web-9')->value('state'));
        $this->assertTrue(DB::table('nodes')->where('node_id', 'web-9.org-1')->exists());

        $this->nodeConnected = true;
        $this->assertSame($id, $this->runner()->queueRetry($id, 'me'));
        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame('deleted', DB::table('resources')->where('slug', 'web-9')->value('state'));
    }

    public function test_something_that_appeared_after_the_review_stops_the_run(): void
    {
        $id = $this->runner()->queueServerDelete('web-9.org-1', [], 'console', 'me');
        $this->site('late');

        $this->assertSame('failed', $this->runner()->run($id));
        $this->assertStringContainsString('Choose what happens to late', (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame('active', DB::table('resources')->where('slug', 'web-9')->value('state'));
    }

    public function test_the_page_carries_the_list_and_the_delete_needs_the_name_typed(): void
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'manager', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);
        $shop = $this->site('shop');

        $this->get('/servers/web-9.org-1?tab=settings')->assertInertia(fn ($page) => $page
            ->where('underneath.server', 'web-9')
            ->where('underneath.items.0.key', "app:{$shop->id}")
            ->where('underneath.items.0.name', 'shop'));

        $this->postJson('/servers/web-9.org-1/delete', ['confirm' => 'wrong', 'choices' => ["app:{$shop->id}" => 'erase']])
            ->assertStatus(422)->assertJsonValidationErrors('delete');
        $this->assertSame(0, DB::table('operations')->where('kind', 'server_delete')->count());

        $this->postJson('/servers/web-9.org-1/delete', ['confirm' => 'web-9', 'choices' => ["app:{$shop->id}" => 'erase']])->assertRedirectContains('/operation?operation=');
        $this->assertSame(1, DB::table('operations')->where('kind', 'server_delete')->count());
    }
}
