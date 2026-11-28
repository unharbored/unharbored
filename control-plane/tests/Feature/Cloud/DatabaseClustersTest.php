<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * Managed databases bill by the hour and hold the data, so: a create that
 * might have worked is never forgotten, a site's credentials reach only its
 * own database, attaching never loosens or replaces what the firewall
 * already allows, and nothing in use is destroyed.
 */
final class DatabaseClustersTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api.digitalocean.com/v2';

    private HttpFactory $http;

    private ResourceManager $resources;

    private Sites $sites;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Cache::store('array')->clear();
        $this->http = new HttpFactory;
        $this->resources = new ResourceManager(new AuditLog);
        $this->sites = new Sites($this->app->make(Vault::class), new AuditLog);
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        $this->credentials()->connect('org-1', 'dop_v1_test_token');
    }

    private function credentials(): CredentialStore
    {
        return new CredentialStore(vault: $this->app->make(Vault::class), http: $this->http, cache: Cache::store('array'), audit: new AuditLog);
    }

    private function clusters(): DatabaseClusters
    {
        return new DatabaseClusters($this->credentials(), $this->resources, $this->sites, new AuditLog,
            new GatewayClient('http://gw.test', 't', $this->http, new AuditLog));
    }

    private function onlineCluster(): ResourceRecord
    {
        $this->http->fake([self::API.'/databases' => $this->http->response(['database' => ['id' => 'db-uuid', 'status' => 'creating']], 201)]);
        $record = $this->clusters()->create('org-1', 'main', 'pg', 'nyc3', 'db-s-1vcpu-1gb');
        $this->http->fake([
            self::API.'/databases/db-uuid/ca' => $this->http->response(['ca' => ['certificate' => base64_encode("-----BEGIN CERTIFICATE-----\nCA\n-----END CERTIFICATE-----\n")]]),
            self::API.'/databases/db-uuid' => $this->http->response(['database' => ['id' => 'db-uuid', 'status' => 'online', 'version' => '17',
                'connection' => ['host' => 'main.db.ondigitalocean.com', 'port' => 25060, 'password' => 'admin-secret']]]),
        ]);

        return $this->clusters()->refresh($record);
    }

    private function siteOnDroplet(): stdClass
    {
        $droplet = $this->resources->declare('org-1', 'server', 'web-1', []);
        $this->resources->recordExternalId($droplet, '616267966');

        return $this->sites->create('org-1', 'web-1.org-1', 'shop-app', 'shop.example.com', 'git@github.com:acme/shop.git');
    }

    public function test_a_create_is_recorded_before_and_after_the_request(): void
    {
        $this->http->fake([self::API.'/databases' => $this->http->response(['database' => ['id' => 'db-uuid', 'status' => 'creating']], 201)]);

        $record = $this->clusters()->create('org-1', 'main', 'pg', 'nyc3', 'db-s-1vcpu-1gb');

        $this->assertSame(['provisioning', 'db-uuid'], [$record->state->value, $record->externalId]);
        $this->http->assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/databases')
            && $r['engine'] === 'pg' && $r['num_nodes'] === 1 && in_array('cloud-manager', $r['tags'], true)
            && $r->hasHeader('Idempotency-Key'));
    }

    public function test_a_create_with_no_usable_answer_is_orphaned_not_forgotten(): void
    {
        // A billable cluster may exist; "failed" would let it be forgotten.
        $this->http->fake([self::API.'/*' => $this->http->response(['message' => 'boom'], 503)]);

        try {
            $this->clusters()->create('org-1', 'main', 'pg', 'nyc3', 'db-s-1vcpu-1gb');
            $this->fail('Should have thrown.');
        } catch (RuntimeException) {
        }

        $this->assertSame(ResourceState::Orphaned, $this->resources->find('org-1', 'database_cluster', 'main')->state);
    }

    public function test_coming_online_records_where_it_is_but_never_the_admin_password(): void
    {
        $record = $this->onlineCluster();

        $this->assertSame(ResourceState::Active, $record->state);
        $this->assertSame(['main.db.ondigitalocean.com', 25060], [$record->attributes['host'], $record->attributes['port']]);
        $this->assertStringContainsString('BEGIN CERTIFICATE', $record->attributes['ca_pem']);
        $this->assertStringNotContainsString('admin-secret', (string) DB::table('resources')->where('id', $record->id)->value('attributes'));
    }

    public function test_a_cluster_still_creating_stays_provisioning(): void
    {
        $this->http->fake([self::API.'/databases' => $this->http->response(['database' => ['id' => 'db-uuid']], 201)]);
        $record = $this->clusters()->create('org-1', 'main', 'pg', 'nyc3', 'db-s-1vcpu-1gb');
        $this->http->fake([self::API.'/databases/db-uuid' => $this->http->response(['database' => ['status' => 'creating']])]);

        $this->assertSame(ResourceState::Provisioning, $this->clusters()->refresh($record)->state);
    }

    public function test_attaching_gives_the_site_its_own_user_and_keeps_existing_firewall_rules(): void
    {
        $cluster = $this->onlineCluster();
        $site = $this->siteOnDroplet();
        $this->http->fake([
            self::API.'/databases/db-uuid/dbs' => $this->http->response(['db' => ['name' => 'shop_app']], 201),
            self::API.'/databases/db-uuid/users/shop_app' => $this->http->response([], 404),
            self::API.'/databases/db-uuid/users' => $this->http->response(['user' => ['name' => 'shop_app', 'password' => 'site-secret']], 201),
            'gw.test/*' => $this->http->response(['status' => 'succeeded', 'outcome_known' => true]),
            self::API.'/databases/db-uuid/firewall' => function (Request $r) {
                return $r->method() === 'GET'
                    ? $this->http->response(['rules' => [['type' => 'ip_addr', 'value' => '203.0.113.7', 'uuid' => 'x']]])
                    : $this->http->response([], 204);
            },
        ]);

        $this->clusters()->attach($site, $cluster);

        $this->http->assertSent(fn (Request $r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/firewall')
            && $r['rules'] === [['type' => 'ip_addr', 'value' => '203.0.113.7'], ['type' => 'droplet', 'value' => '616267966']]);
        $env = $this->sites->env($this->sites->find('web-1.org-1', 'shop-app'));
        foreach (['DB_CONNECTION=pgsql', 'DB_HOST=main.db.ondigitalocean.com', 'DB_PORT=25060', 'DB_DATABASE=shop_app', 'DB_USERNAME=shop_app', 'DB_PASSWORD=site-secret', 'DB_SSLMODE=require'] as $line) {
            $this->assertStringContainsString($line, $env);
        }
        $this->assertStringNotContainsString('admin-secret', $env, 'A site never gets the admin account.');
        $row = DB::table('sites')->where('id', $site->id)->first();
        $this->assertSame($cluster->id, (int) $row->database_cluster_id);
        $this->assertNotNull($row->pending_since, 'It reaches the server on the next deploy.');
    }

    public function test_reattaching_is_harmless_and_rotates_the_password(): void
    {
        $cluster = $this->onlineCluster();
        $site = $this->siteOnDroplet();
        $this->http->fake([
            self::API.'/databases/db-uuid/dbs' => $this->http->response(['message' => 'already exists'], 422),
            self::API.'/databases/db-uuid/users/shop_app' => $this->http->response([], 204),
            self::API.'/databases/db-uuid/users' => $this->http->response(['user' => ['password' => 'rotated']], 201),
            'gw.test/*' => $this->http->response(['status' => 'succeeded', 'outcome_known' => true]),
            self::API.'/databases/db-uuid/firewall' => fn (Request $r) => $r->method() === 'GET'
                ? $this->http->response(['rules' => [['type' => 'droplet', 'value' => 616267966]]])
                : $this->http->response([], 204),
        ]);

        $done = $this->clusters()->attach($site, $cluster);

        $this->assertContains('database shop_app already exists', $done);
        $this->assertContains('firewall already admits droplet 616267966', $done);
        $this->http->assertNotSent(fn (Request $r) => $r->method() === 'PUT');
        $this->assertStringContainsString('DB_PASSWORD=rotated', $this->sites->env($this->sites->find('web-1.org-1', 'shop-app')));
    }

    public function test_a_cluster_not_yet_online_cannot_be_attached(): void
    {
        $this->http->fake([self::API.'/databases' => $this->http->response(['database' => ['id' => 'db-uuid']], 201)]);
        $cluster = $this->clusters()->create('org-1', 'main', 'pg', 'nyc3', 'db-s-1vcpu-1gb');

        $this->expectException(InvalidArgumentException::class);
        $this->clusters()->attach($this->siteOnDroplet(), $cluster);
    }

    public function test_a_server_brought_by_hand_is_admitted_by_the_address_it_enrolled_from(): void
    {
        $cluster = $this->onlineCluster();
        $site = $this->sites->create('org-1', 'hetzner-1.org-1', 'shop-app', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('enrollment_grants')->insert(['token_hash' => str_repeat('a', 64), 'node_id' => 'hetzner-1.org-1', 'organization_id' => 'org-1',
            'expires_at' => now()->addHour(), 'consumed_at' => now(), 'consumed_by_ip' => '203.0.113.40', 'created_at' => now(), 'updated_at' => now()]);
        $this->http->fake([
            self::API.'/databases/db-uuid/dbs' => $this->http->response([], 201),
            self::API.'/databases/db-uuid/users/shop_app' => $this->http->response([], 204),
            self::API.'/databases/db-uuid/users' => $this->http->response(['user' => ['password' => 'pw']], 201),
            'gw.test/*' => $this->http->response(['status' => 'succeeded', 'outcome_known' => true]),
            self::API.'/databases/db-uuid/firewall' => fn (Request $r) => $r->method() === 'GET'
                ? $this->http->response(['rules' => []]) : $this->http->response([], 204),
        ]);

        $done = $this->clusters()->attach($site, $cluster);

        $this->assertContains('firewall admits 203.0.113.40', $done);
        $this->http->assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['rules'] === [['type' => 'ip_addr', 'value' => '203.0.113.40']]);
    }

    public function test_a_server_with_no_droplet_and_no_public_address_cannot_be_named_in_the_firewall(): void
    {
        $cluster = $this->onlineCluster();
        $site = $this->sites->create('org-1', 'hetzner-1.org-1', 'shop-app', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('enrollment_grants')->insert(['token_hash' => str_repeat('b', 64), 'node_id' => 'hetzner-1.org-1', 'organization_id' => 'org-1',
            'expires_at' => now()->addHour(), 'consumed_at' => now(), 'consumed_by_ip' => '10.1.2.3', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectExceptionMessage('no public IPv4 address');
        $this->clusters()->attach($site, $cluster);
    }

    public function test_a_removed_server_is_released_from_the_firewall_unless_another_site_there_uses_the_cluster(): void
    {
        $cluster = $this->onlineCluster();
        $site = $this->siteOnDroplet();
        $web2 = $this->resources->declare('org-1', 'server', 'web-2', []);
        $this->resources->recordExternalId($web2, '222');
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster->id]);
        $site = DB::table('sites')->find($site->id);
        $rules = [['type' => 'droplet', 'value' => 616267966], ['type' => 'droplet', 'value' => 222], ['type' => 'ip_addr', 'value' => '10.0.0.5']];
        $this->http->fake([self::API.'/databases/db-uuid/firewall' => fn (Request $r) => $r->method() === 'GET'
            ? $this->http->response(['rules' => $rules]) : $this->http->response([], 204)]);

        $this->assertSame(['main no longer admits droplet 222'], $this->clusters()->releaseServer($site, 'web-2.org-1'));
        $this->http->assertSent(fn (Request $r) => $r->method() === 'PUT' && $r['rules'] === [
            ['type' => 'droplet', 'value' => '616267966'], ['type' => 'ip_addr', 'value' => '10.0.0.5']]);

        $other = $this->sites->create('org-1', 'web-2.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');
        DB::table('sites')->where('id', $other->id)->update(['database_cluster_id' => $cluster->id]);
        $this->http->fake([self::API.'/*' => fn () => $this->fail('No firewall change while blog still needs it.')]);
        $this->assertSame(['main still admits it: blog uses that cluster there'], $this->clusters()->releaseServer($site, 'web-2.org-1'));
    }

    public function test_a_cluster_in_use_is_not_destroyed(): void
    {
        $cluster = $this->onlineCluster();
        $site = $this->siteOnDroplet();
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => $cluster->id]);
        $this->http->fake();

        try {
            $this->clusters()->destroy($cluster);
            $this->fail('Should have refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('shop-app', $e->getMessage());
        }
        $this->http->assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
    }

    public function test_an_unused_cluster_is_destroyed_and_recorded(): void
    {
        $cluster = $this->onlineCluster();
        $this->http->fake([self::API.'/databases/db-uuid' => $this->http->response([], 204)]);

        $this->clusters()->destroy($cluster);

        $this->http->assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/databases/db-uuid'));
        $this->assertSame(ResourceState::Deleted, $this->resources->findById($cluster->id)->state);
    }

    private function fakeAttachWithGrant(string $grantStatus): void
    {
        $this->http->fake([
            self::API.'/databases/db-uuid/dbs' => $this->http->response(['db' => ['name' => 'shop_app']], 201),
            self::API.'/databases/db-uuid/users/shop_app' => $this->http->response([], 404),
            self::API.'/databases/db-uuid/users' => $this->http->response(['user' => ['password' => 'site-secret']], 201),
            self::API.'/databases/db-uuid/firewall' => fn (Request $r) => $this->http->response(['rules' => []]),
            'gw.test/*' => fn (Request $r) => ($r['args']['spec']['name'] ?? '') === 'database-grant'
                ? $this->http->response(['status' => $grantStatus, 'error' => 'permission denied', 'outcome_known' => true])
                : $this->http->response(['status' => 'succeeded', 'outcome_known' => true]),
        ]);
    }

    public function test_the_grant_runs_on_the_node_from_a_private_file_that_is_then_removed(): void
    {
        // From PostgreSQL 15 a user DigitalOcean creates cannot create tables
        // in a new database: the first live deploy failed there.
        $cluster = $this->onlineCluster();
        $site = $this->siteOnDroplet();
        $this->fakeAttachWithGrant('succeeded');

        $this->clusters()->attach($site, $cluster);

        $this->http->assertSent(function (Request $r) {
            if (($r['args']['spec']['name'] ?? '') !== 'database-grant') {
                return false;
            }
            $steps = array_column($r['args']['spec']['steps'], null, 'id');
            $script = base64_decode($steps['script']['content_b64']);

            return $steps['script']['sensitive'] === true && $steps['script']['mode'] === '0600'
                && str_contains($script, 'GRANT ALL ON SCHEMA public TO "shop_app"')
                && str_contains($script, "'admin-secret'")
                && ! str_contains(json_encode($steps['grant']['args']), 'admin-secret')
                && $steps['forget']['type'] === 'absent';
        });
        $this->http->assertSent(fn (Request $r) => ($r['args']['spec']['name'] ?? '') === 'database-grant-cleanup');
    }

    public function test_a_failed_grant_still_removes_the_file_and_changes_nothing(): void
    {
        $cluster = $this->onlineCluster();
        $site = $this->siteOnDroplet();
        $this->fakeAttachWithGrant('failed');

        try {
            $this->clusters()->attach($site, $cluster);
            $this->fail('Should have thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('permission denied', $e->getMessage());
        }

        $this->http->assertSent(fn (Request $r) => ($r['args']['spec']['name'] ?? '') === 'database-grant-cleanup');
        $this->assertStringNotContainsString('DB_PASSWORD', $this->sites->env($this->sites->find('web-1.org-1', 'shop-app')));
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('database_cluster_id'));
    }

    public function test_attaching_valkey_moves_cache_sessions_and_queue_with_a_site_prefix(): void
    {
        $this->http->fake([self::API.'/databases' => $this->http->response(['database' => ['id' => 'vk-uuid']], 201)]);
        $record = $this->clusters()->create('org-1', 'cache', 'valkey', 'nyc3', 'db-s-1vcpu-1gb');
        $this->http->fake([
            self::API.'/databases/vk-uuid/ca' => $this->http->response(['ca' => ['certificate' => '']]),
            self::API.'/databases/vk-uuid/firewall' => fn (Request $r) => $r->method() === 'GET' ? $this->http->response(['rules' => []]) : $this->http->response([], 204),
            self::API.'/databases/vk-uuid' => $this->http->response(['database' => ['status' => 'online', 'connection' => [
                'host' => 'cache.db.ondigitalocean.com', 'port' => 25061, 'user' => 'default', 'password' => 'valkey-secret']]]),
        ]);
        $cluster = $this->clusters()->refresh($record);
        $site = $this->siteOnDroplet();

        $this->clusters()->attach($site, $cluster);

        $env = $this->sites->env($this->sites->find('web-1.org-1', 'shop-app'));
        foreach (['REDIS_HOST=tls://cache.db.ondigitalocean.com', 'REDIS_PORT=25061', 'REDIS_PASSWORD=valkey-secret', 'REDIS_PREFIX=shop_app_',
            'CACHE_STORE=redis', 'SESSION_DRIVER=redis', 'QUEUE_CONNECTION=redis'] as $line) {
            $this->assertStringContainsString($line, $env);
        }
        $this->assertStringNotContainsString('DB_CONNECTION=pgsql', $env, 'A cache cluster does not move the data.');
        $this->http->assertNotSent(fn (Request $r) => str_contains($r->url(), '/dbs') || str_contains($r->url(), '/users'));
        $this->assertSame($cluster->id, (int) DB::table('sites')->where('id', $site->id)->value('cache_cluster_id'));
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('database_cluster_id'));
    }

    public function test_a_cache_cluster_in_use_is_not_destroyed(): void
    {
        $cluster = $this->onlineCluster();
        $site = $this->siteOnDroplet();
        DB::table('sites')->where('id', $site->id)->update(['cache_cluster_id' => $cluster->id]);
        $this->http->fake();

        $this->expectExceptionMessage('shop-app');
        $this->clusters()->destroy($cluster);
    }

    private function accountClusters(): void
    {
        $this->http->fake([
            self::API.'/databases?*' => $this->http->response(['databases' => [
                ['id' => 'old-pg', 'name' => 'Legacy PG', 'engine' => 'pg', 'status' => 'online', 'version' => '15', 'region' => 'nyc3', 'size' => 'db-s-2vcpu-4gb', 'num_nodes' => 2, 'tags' => []],
                ['id' => 'old-my', 'name' => 'legacy-my', 'engine' => 'mysql', 'status' => 'online', 'version' => '8', 'region' => 'nyc3', 'size' => 'db-s-1vcpu-1gb', 'tags' => ['prod']],
                ['id' => 'cache', 'name' => 'cache', 'engine' => 'valkey', 'status' => 'online', 'tags' => []],
                ['id' => 'ours', 'name' => 'cm-forked', 'engine' => 'pg', 'status' => 'online', 'tags' => ['cloud-manager', 'temporary']],
                ['id' => 'building', 'name' => 'building', 'engine' => 'pg', 'status' => 'creating', 'tags' => []],
                ['id' => 'db-uuid', 'name' => 'cm-main', 'engine' => 'pg', 'status' => 'online', 'tags' => []],
            ]]),
        ]);
    }

    private function adoptable(): ResourceRecord
    {
        $this->http->fake([
            self::API.'/databases/old-pg/ca' => $this->http->response(['ca' => ['certificate' => base64_encode("-----BEGIN CERTIFICATE-----\nCA\n-----END CERTIFICATE-----\n")]]),
            self::API.'/databases/old-pg' => $this->http->response(['database' => ['id' => 'old-pg', 'name' => 'Legacy PG', 'engine' => 'pg', 'status' => 'online', 'version' => '15',
                'region' => 'nyc3', 'size' => 'db-s-2vcpu-4gb', 'connection' => ['host' => 'legacy.db.ondigitalocean.com', 'port' => 25060, 'password' => 'admin-secret']]]),
        ]);

        return $this->clusters()->adopt('org-1', 'old-pg');
    }

    public function test_only_online_postgres_and_mysql_clusters_not_made_here_are_offered(): void
    {
        $this->onlineCluster();
        $this->accountClusters();

        $found = $this->clusters()->discover('org-1');

        $this->assertSame(['old-pg', 'old-my'], array_column($found, 'id'), 'Not Valkey, not ours by tag, not still creating, not one already tracked.');
        $this->assertSame(['Legacy PG', 'pg', 2], [$found[0]['name'], $found[0]['engine'], $found[0]['nodes']]);
    }

    public function test_bringing_a_cluster_in_records_it_and_changes_nothing_at_digitalocean(): void
    {
        $record = $this->adoptable();

        $this->assertSame(ResourceState::Active, $record->state);
        $this->assertSame('legacy-pg', $record->slug);
        $this->assertSame('old-pg', $record->externalId);
        $this->assertTrue(DatabaseClusters::isAdopted($record));
        $this->assertSame(['legacy.db.ondigitalocean.com', 25060], [$record->attributes['host'], $record->attributes['port']]);
        $this->assertStringNotContainsString('admin-secret', (string) json_encode($record->attributes), 'The admin password is never stored.');
        $this->http->assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        $this->assertSame(1, DB::table('audit_records')->where('action', 'database_cluster.adopted')->count());
    }

    public function test_a_cluster_cannot_be_brought_in_twice_or_when_it_is_not_postgres_or_mysql_or_not_there(): void
    {
        $this->adoptable();
        try {
            $this->clusters()->adopt('org-1', 'old-pg');
            $this->fail('Twice.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already here', $e->getMessage());
        }

        $this->http->fake([
            self::API.'/databases/cache' => $this->http->response(['database' => ['id' => 'cache', 'engine' => 'valkey', 'status' => 'online']]),
            self::API.'/databases/gone' => $this->http->response(['message' => 'not found'], 404),
        ]);
        foreach (['cache' => 'Only PostgreSQL and MySQL', 'gone' => 'not in your DigitalOcean account'] as $id => $why) {
            try {
                $this->clusters()->adopt('org-1', $id);
                $this->fail($id);
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($why, $e->getMessage());
            }
        }
    }

    public function test_attaching_to_a_brought_in_cluster_never_meets_a_database_or_user_already_there(): void
    {
        $cluster = $this->adoptable();
        $site = $this->siteOnDroplet();
        $this->http->fake([
            self::API.'/databases/old-pg/dbs' => $this->http->response(['dbs' => [['name' => 'defaultdb'], ['name' => 'shop_app']]]),
            self::API.'/databases/old-pg/users' => $this->http->response(['users' => [['name' => 'doadmin']]]),
        ]);

        try {
            $this->clusters()->attach($site, $cluster);
            $this->fail('The existing database must stop it.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already has a database or user called shop_app', $e->getMessage());
        }
        $this->http->assertNotSent(fn (Request $r) => $r->method() !== 'GET');
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('database_cluster_id'));
    }

    public function test_destroying_a_brought_in_cluster_only_forgets_it(): void
    {
        $cluster = $this->adoptable();
        $this->http->fake();

        $this->clusters()->destroy($cluster);

        $this->http->assertNotSent(fn (Request $r) => $r->method() === 'DELETE');
        $this->assertSame(ResourceState::Deleted, $this->resources->findById($cluster->id)->state);
    }
}
