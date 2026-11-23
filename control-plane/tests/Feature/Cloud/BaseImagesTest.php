<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\BaseImages;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Pool members boot from a snapshot of the stack, taken only once the
 * builder is wiped and off, and old snapshots do not pile up.
 */
final class BaseImagesTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    private string $dropletStatus = 'active';

    private string $actionStatus = 'in-progress';

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Cache::store('array')->clear();
        $this->http = new HttpFactory();
        $this->app->instance(CredentialStore::class, new CredentialStore($this->app->make(Vault::class), $this->http, Cache::store('array'), new AuditLog()));
        $this->http->fake(function (Request $r) {
            $this->sent[] = $r;
            $url = $r->url();

            return match (true) {
                str_ends_with($url, '/droplets/900') => $this->http->response(['droplet' => ['id' => 900, 'status' => $this->dropletStatus]]),
                str_ends_with($url, '/droplets/900/actions') => $this->http->response(['action' => ['id' => 77]], 201),
                str_ends_with($url, '/actions/77') => $this->http->response(['action' => ['id' => 77, 'status' => $this->actionStatus]]),
                str_contains($url, '/droplets/900/snapshots') => $this->http->response(['snapshots' => [['id' => 5550, 'name' => 'cm-other'], ['id' => 5551, 'name' => 'cm-image-1']]]),
                str_contains($url, '/droplets/autoscale/pool-uuid') && $r->method() === 'GET' => $this->http->response(['autoscale_pool' => [
                    'name' => 'cm-shop-pool', 'config' => ['min_instances' => 1, 'max_instances' => 3],
                    'droplet_template' => ['image' => 'ubuntu-24-04-x64', 'region' => 'nyc3', 'size' => 's-1vcpu-1gb', 'ssh_keys' => ['9']]]]),
                default => $this->http->response([], 204),
            };
        });
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->make(CredentialStore::class)->connect('org-1', 'dop_v1_test_token');
    }

    private function images(): BaseImages
    {
        return $this->app->make(BaseImages::class);
    }

    private function resources(): ResourceManager
    {
        return new ResourceManager(new AuditLog());
    }

    public function test_the_snapshot_waits_for_the_builder_to_be_off_then_for_digitalocean(): void
    {
        $this->assertNull($this->images()->snapshot('org-1', 'image-1', '900', 'nyc3'), 'Still running: nothing asked for yet.');
        $this->assertSame([], array_filter($this->sent, fn (Request $r) => $r->method() === 'POST'));

        $this->dropletStatus = 'off';
        $this->assertNull($this->images()->snapshot('org-1', 'image-1', '900', 'nyc3'));
        $this->assertNull($this->images()->snapshot('org-1', 'image-1', '900', 'nyc3'), 'Asked once; now waiting on the action.');
        $posts = array_values(array_filter($this->sent, fn (Request $r) => $r->method() === 'POST'));
        $this->assertCount(1, $posts);
        $this->assertSame(['type' => 'snapshot', 'name' => 'cm-image-1'], $posts[0]->data());

        $this->actionStatus = 'completed';
        $image = $this->images()->snapshot('org-1', 'image-1', '900', 'nyc3');
        $this->assertSame(['5551', ResourceState::Active], [$image->externalId, $image->state]);
        $this->assertSame('5551', $this->images()->imageFor('org-1', 'nyc3'));
        $this->assertSame('ubuntu-24-04-x64', $this->images()->imageFor('org-1', 'sfo3'), 'No image in that region: stock Ubuntu.');
    }

    public function test_publishing_points_pools_at_the_image_and_deletes_the_one_it_replaces(): void
    {
        $old = $this->resources()->declare('org-1', 'base_image', 'image-0', ['region' => 'nyc3']);
        $old = $this->resources()->transition($old, ResourceState::Provisioning, reason: 't');
        $old = $this->resources()->recordExternalId($old, '4440');
        $this->resources()->transition($old, ResourceState::Active, reason: 't');
        $pool = $this->resources()->declare('org-1', 'autoscale_pool', 'shop-pool', ['region' => 'nyc3']);
        $pool = $this->resources()->transition($pool, ResourceState::Provisioning, reason: 't');
        $pool = $this->resources()->recordExternalId($pool, 'pool-uuid');
        $this->resources()->transition($pool, ResourceState::Active, reason: 't');
        $this->dropletStatus = 'off';
        $this->actionStatus = 'completed';
        $this->images()->snapshot('org-1', 'image-1', '900', 'nyc3');
        $image = $this->images()->snapshot('org-1', 'image-1', '900', 'nyc3');

        $done = $this->images()->publish($image);

        $this->assertSame(['shop-pool boots new members from it', 'image-0 deleted'], $done);
        $put = collect($this->sent)->first(fn (Request $r) => $r->method() === 'PUT');
        $this->assertSame('5551', $put['droplet_template']['image']);
        $this->assertSame(['9'], $put['droplet_template']['ssh_keys'], 'The rest of the template is kept.');
        $this->assertTrue(collect($this->sent)->contains(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/images/4440')));
        $this->assertSame(ResourceState::Deleted, $this->resources()->findById($old->id)->state);
    }

    public function test_the_wipe_removes_identity_and_powers_off(): void
    {
        $script = end(BaseImages::generaliseRecipe()['steps'][0]['args']);

        foreach (['/var/lib/cloud-manager', 'cloud-init clean', 'ssh_host_', '/etc/machine-id', 'poweroff'] as $part) {
            $this->assertStringContainsString($part, $script);
        }
    }

    public function test_an_image_is_built_only_where_a_pool_needs_one_and_only_when_stale(): void
    {
        Queue::fake();
        $this->artisan('images:refresh')->assertSuccessful();
        $this->assertSame(0, DB::table('operations')->count(), 'No pool, no image.');

        $pool = $this->resources()->declare('org-1', 'autoscale_pool', 'shop-pool', ['region' => 'nyc3']);
        $pool = $this->resources()->transition($pool, ResourceState::Provisioning, reason: 't');
        $this->resources()->transition($pool, ResourceState::Active, reason: 't');
        $this->artisan('images:refresh')->assertSuccessful();
        $this->artisan('images:refresh')->assertSuccessful();
        $op = DB::table('operations')->where('kind', 'base_image')->sole();
        $this->assertStringContainsString('nyc3', $op->title);
    }
}
