<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\NewApplications;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/** A new application can use a cluster that exists, or have one made for it. */
final class NewClustersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'k', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'provider' => 'digitalocean', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->resource('web-1', 'server', ['ipv4' => '203.0.113.7', 'region' => 'ams3']);
        $this->resource('main', 'database_cluster', ['engine' => 'pg']);
    }

    private function resource(string $slug, string $type, array $attributes, string $state = 'active'): void
    {
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => $type, 'slug' => $slug, 'external_id' => "x-{$slug}", 'state' => $state,
            'desired_state' => 'present', 'attributes' => json_encode($attributes), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function answers(array $over = []): array
    {
        return $over + ['name' => 'shop', 'repository' => 'acme/shop', 'compute' => 'single', 'server' => ['mode' => 'new', 'region' => 'nyc3', 'size' => 's-1vcpu-2gb'], 'database' => 'sqlite'];
    }

    private function plan(array $over = []): array
    {
        return $this->app->make(NewApplications::class)->plan('org-1', $this->answers($over));
    }

    public function test_an_existing_cluster_is_used_as_it_is_and_nothing_is_made(): void
    {
        $plan = $this->plan(['database' => (string) DB::table('resources')->where('slug', 'main')->value('id')]);

        $this->assertNotContains('cluster-db', $plan['steps']);
        $this->assertContains('database', $plan['steps']);
        $this->assertSame(DB::table('resources')->where('slug', 'main')->value('id'), $plan['database']);
        $this->assertSame(['low' => 12, 'high' => 12], $plan['monthly'], 'Only the server is new.');
    }

    public function test_a_new_cluster_is_planned_beside_the_server_named_for_the_application_and_priced(): void
    {
        $plan = $this->plan(['database' => 'new:pg', 'database_size' => 'db-s-1vcpu-2gb', 'cache' => 'new:valkey']);

        $this->assertSame(['shop-db', 'pg', 'db-s-1vcpu-2gb', 'nyc3'], [$plan['database']['new']['slug'], $plan['database']['new']['engine'], $plan['database']['new']['size'], $plan['database']['new']['region']]);
        $this->assertSame(['shop-cache', 'valkey', 'db-s-1vcpu-1gb'], [$plan['cache']['new']['slug'], $plan['cache']['new']['engine'], $plan['cache']['new']['size']], 'The smallest size unless one is chosen.');
        $steps = $plan['steps'];
        $this->assertLessThan(array_search('database', $steps, true), array_search('cluster-db', $steps, true));
        $this->assertLessThan(array_search('cache', $steps, true), array_search('cluster-cache', $steps, true));
        $this->assertLessThan(array_search('deploy', $steps, true), array_search('cache', $steps, true), 'Attached before the first deploy.');
        $this->assertSame(['low' => 12 + 30 + 15, 'high' => 12 + 30 + 15], $plan['monthly']);
        $this->assertStringContainsString('a new PostgreSQL cluster, shop-db', $plan['summary']);
        $this->assertStringContainsString('a new Valkey cluster, shop-cache', $plan['summary']);
    }

    public function test_an_existing_server_gives_the_region_the_cluster_is_made_in(): void
    {
        $plan = $this->plan(['database' => 'new:mysql', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1']]);

        $this->assertSame('ams3', $plan['database']['new']['region']);
    }

    public function test_a_server_with_no_known_region_cannot_have_a_cluster_made_beside_it(): void
    {
        DB::table('resources')->where('slug', 'web-1')->update(['attributes' => json_encode(['ipv4' => '203.0.113.7'])]);

        $this->expectExceptionMessage('database: the server\'s region is not known');
        $this->plan(['database' => 'new:pg', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1']]);
    }

    public function test_a_name_is_never_one_a_cluster_has_had(): void
    {
        $this->resource('shop-db', 'database_cluster', ['engine' => 'pg'], 'deleted');
        $this->resource('shop-db-2', 'database_cluster', ['engine' => 'pg']);

        $this->assertSame('shop-db-3', $this->plan(['database' => 'new:pg'])['database']['new']['slug']);
    }

    public function test_what_cannot_be_made_is_refused(): void
    {
        foreach ([
            [['database' => 'new:valkey'], 'database:'],
            [['database' => 'new:oracle'], 'database:'],
            [['database' => 'new:pg', 'database_size' => 'huge'], 'database: pick a cluster size'],
            [['database' => 'sqlite', 'cache' => 'new:pg'], 'cache:'],
        ] as [$over, $message]) {
            try {
                $this->plan($over);
                $this->fail($message);
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }

        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'aws', 'vault_key' => 'k2', 'created_at' => now(), 'updated_at' => now()]);
        $this->expectExceptionMessage('AWS has no managed PostgreSQL to create');
        $this->plan(['provider' => 'aws', 'database' => 'new:pg', 'server' => ['mode' => 'new', 'region' => 'us-east-1', 'size' => 't3.small']]);
    }

    public function test_autoscaling_can_have_its_shared_database_made_for_it(): void
    {
        $plan = $this->plan(['compute' => 'autoscale', 'database' => 'new:pg']);

        $this->assertContains('cluster-db', $plan['steps']);
        $this->assertSame('shop-db', $plan['database']['new']['slug']);
    }

    public function test_the_application_is_made_by_creating_the_cluster_then_attaching_that_one(): void
    {
        $plan = $this->plan(['database' => 'new:pg']);
        $started = $this->app->make(NewApplications::class)->start('org-1', $plan, 'matt');
        $runner = $this->app->make(OperationRunner::class);
        $op = $started['operation'];
        // Up to the cluster: the earlier steps are done.
        DB::table('operations')->where('id', $op)->update(['meta' => json_encode(array_replace(json_decode((string) DB::table('operations')->find($op)->meta, true), ['done' => ['server', 'address']]))]);

        $this->assertSame('waiting', $runner->run($op));

        $child = DB::table('operations')->where('kind', 'database_create')->first();
        $this->assertNotNull($child, 'The cluster is created first.');
        $this->assertEqualsCanonicalizing(['shop-db', 'pg', 'nyc3', 'db-s-1vcpu-1gb'], array_values(array_intersect_key(json_decode($child->meta, true), array_flip(['slug', 'engine', 'region', 'size']))));
        $this->assertSame(0, DB::table('operations')->where('kind', 'database_attach')->count(), 'Nothing is attached before it exists.');

        // Once it is made and the step is done, the next step attaches that cluster by the name it was given.
        $this->resource('shop-db', 'database_cluster', ['engine' => 'pg']);
        $meta = json_decode((string) DB::table('operations')->find($op)->meta, true);
        $meta['done'][] = 'cluster-db';
        DB::table('operations')->where('id', $op)->update(['meta' => json_encode($meta)]);
        $this->assertSame('waiting', $runner->run($op));

        $attach = DB::table('operations')->where('kind', 'database_attach')->first();
        $this->assertSame((int) DB::table('resources')->where('slug', 'shop-db')->value('id'), json_decode($attach->meta, true)['cluster']);
    }

    public function test_variables_that_override_what_is_set_up_are_warned_about_and_refused_for_autoscaling(): void
    {
        $cluster = (string) DB::table('resources')->where('slug', 'main')->value('id');

        $warned = $this->plan(['database' => $cluster, 'env' => ['DB_HOST' => 'elsewhere', 'STRIPE_KEY' => 'k']]);
        $this->assertCount(1, $warned['warnings']);
        $this->assertStringContainsString('DB_HOST override what Unharbored sets up', $warned['warnings'][0]);
        $this->assertStringNotContainsString('STRIPE_KEY', $warned['warnings'][0]);

        $this->assertSame([], $this->plan(['env' => ['STRIPE_KEY' => 'k']])['warnings'], 'Nothing is set up, so nothing is overridden.');
        $this->assertSame([], $this->plan(['database' => 'none', 'cache' => 'none', 'env' => ['DB_HOST' => 'mine']])['warnings'], 'None means it is yours to set.');

        try {
            $this->plan(['compute' => 'autoscale', 'database' => $cluster, 'env' => ['DB_CONNECTION' => 'sqlite', 'CACHE_STORE' => 'database']]);
            $this->fail();
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('env: DB_CONNECTION, CACHE_STORE would override', $e->getMessage());
        }
    }
}
