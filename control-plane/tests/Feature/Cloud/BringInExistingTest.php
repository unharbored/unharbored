<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Bringing in a database cluster or a bucket the application did not make: who may, and what the console says. */
final class BringInExistingTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api.digitalocean.com/v2';

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $this->http = new HttpFactory;
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(CredentialStore::class, new CredentialStore(vault: $this->app->make(Vault::class), http: $this->http, cache: Cache::store('array'), audit: new AuditLog));
        $this->app->make(CredentialStore::class)->connect('org-1', 'dop_v1_test_token');
    }

    private function member(string $role): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);

        return $user;
    }

    public function test_only_someone_who_may_change_infrastructure_can_look_for_clusters_or_bring_one_in(): void
    {
        $this->http->fake([self::API.'/databases?*' => $this->http->response(['databases' => []])]);

        $developer = $this->member('developer');
        $this->actingAs($developer)->getJson('/databases/discover')->assertForbidden();
        $this->actingAs($developer)->post('/databases/adopt', ['cluster' => 'old-pg'])->assertForbidden();

        $this->actingAs($this->member('owner'))->getJson('/databases/discover')->assertOk()->assertJson(['clusters' => []]);
    }

    public function test_the_console_brings_a_cluster_in_and_says_why_when_it_cannot(): void
    {
        $this->http->fake([
            self::API.'/databases/old-pg/ca' => $this->http->response(['ca' => ['certificate' => base64_encode('CA')]]),
            self::API.'/databases/old-pg' => $this->http->response(['database' => ['id' => 'old-pg', 'name' => 'Legacy', 'engine' => 'pg', 'status' => 'online', 'region' => 'nyc3',
                'connection' => ['host' => 'legacy.db.ondigitalocean.com', 'port' => 25060]]]),
            self::API.'/databases/gone' => $this->http->response([], 404),
        ]);
        $owner = $this->member('owner');

        $this->actingAs($owner)->post('/databases/adopt', ['cluster' => 'gone'])->assertSessionHasErrors('adopt');
        $this->assertSame(0, DB::table('resources')->where('type', 'database_cluster')->count());

        $this->actingAs($owner)->post('/databases/adopt', ['cluster' => 'old-pg'])->assertRedirect();
        $this->assertSame('active', DB::table('resources')->where('type', 'database_cluster')->sole()->state);
        $this->http->assertNotSent(fn (Request $r) => $r->method() !== 'GET');

        $this->actingAs($owner)->get('/databases')->assertInertia(fn ($page) => $page->where('clusters.0.slug', 'legacy')->where('clusters.0.adopted', true));
    }

    public function test_the_console_uses_an_existing_bucket_for_someone_who_may_and_reports_a_refusal(): void
    {
        $this->http->fake(['https://s3.example.net/*' => $this->http->response('', 200)]);
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $post = fn (User $u, array $over = []) => $this->actingAs($u)->post("/site/settings/{$site->id}/storage/existing",
            $over + ['endpoint' => 'https://s3.example.net', 'bucket' => 'client-uploads', 'access_key' => 'AK', 'secret_key' => 'SK']);

        $post($this->member('developer'))->assertForbidden();

        $owner = $this->member('owner');
        $post($owner, ['endpoint' => 'http://s3.example.net'])->assertSessionHasErrors('storage');
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('bucket_id'));

        $post($owner)->assertSessionHasNoErrors();
        $this->assertNotNull(DB::table('sites')->where('id', $site->id)->value('bucket_id'));
        $this->actingAs($owner)->get("/site/settings?site={$site->id}&tab=database")
            ->assertInertia(fn ($page) => $page->where('data.bucket', 'client-uploads')->where('data.bucketExisting', true));
    }
}
