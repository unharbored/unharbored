<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use App\Lib\Operations\RunOperation;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * The wizard's answers become one plan, the application is recorded at
 * once, and one operation makes the rest step by step, stopping at the step
 * that fails and saying which.
 */
final class NewApplicationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'cloud.digitalocean.token', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'provider' => 'digitalocean', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'preview-1.org-1', 'organization_id' => 'org-1', 'role' => 'preview', 'created_at' => now(), 'updated_at' => now()]);
        foreach ([['web-1', 'server', ['ipv4' => '203.0.113.7']], ['main', 'database_cluster', ['engine' => 'pg']], ['cache', 'database_cluster', ['engine' => 'valkey']]] as [$slug, $type, $attributes]) {
            DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => $type, 'slug' => $slug, 'external_id' => "x-{$slug}", 'state' => 'active',
                'desired_state' => 'present', 'attributes' => json_encode($attributes), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function apps(): NewApplications
    {
        return $this->app->make(NewApplications::class);
    }

    private function clusterId(string $slug): int
    {
        return (int) DB::table('resources')->where('slug', $slug)->value('id');
    }

    public function test_a_single_server_plan_on_a_new_server_with_sqlite(): void
    {
        $plan = $this->apps()->plan('org-1', ['name' => 'Shop', 'repository' => 'acme/shop', 'compute' => 'single',
            'server' => ['mode' => 'new', 'region' => 'nyc3', 'size' => 's-1vcpu-2gb']]);

        $this->assertSame('shop', $plan['name']);
        $this->assertSame(['server', 'address', 'deploy', 'https'], $plan['steps']);
        $this->assertSame(['mode' => 'new', 'region' => 'nyc3', 'size' => 's-1vcpu-2gb', 'slug' => 'shop-1', 'node' => 'shop-1.org-1'], $plan['server']);
        $this->assertSame(['low' => 12, 'high' => 12], $plan['monthly']);
    }

    public function test_an_autoscaling_plan_always_has_a_bucket_and_ends_with_the_pool(): void
    {
        $plan = $this->apps()->plan('org-1', ['name' => 'shop', 'repository' => 'acme/shop', 'compute' => 'autoscale',
            'server' => ['mode' => 'new', 'region' => 'nyc3', 'size' => 's-2vcpu-4gb'], 'pool' => ['min' => 1, 'max' => 4, 'cpu' => 70, 'size' => 's-2vcpu-4gb'],
            'database' => (string) $this->clusterId('main'), 'cache' => (string) $this->clusterId('cache'), 'storage' => 'disk']);

        $this->assertSame(['server', 'address', 'database', 'cache', 'storage', 'deploy', 'https', 'balancer', 'balancer-address', 'balancer-https', 'redeploy', 'pool'], $plan['steps']);
        $this->assertSame(0.7, $plan['pool']['cpu']);
        $this->assertSame(['low' => 24 + 5 + 12 + 24, 'high' => 24 + 5 + 12 + 96], $plan['monthly']);
    }

    public function test_autoscaling_needs_a_shared_database_not_sqlite(): void
    {
        $this->expectExceptionMessage('database: autoscaling needs a database every server shares');
        $this->apps()->plan('org-1', ['name' => 'shop', 'repository' => 'acme/shop', 'compute' => 'autoscale', 'database' => 'sqlite']);
    }

    public function test_answers_that_cannot_work_are_refused_by_field(): void
    {
        foreach ([
            'name' => ['name' => 'Bad Name!', 'repository' => 'acme/shop'],
            'repository' => ['name' => 'shop', 'repository' => 'not a repo'],
            'server' => ['name' => 'shop', 'repository' => 'acme/shop', 'server' => ['mode' => 'existing', 'node' => 'preview-1.org-1']],
            'database' => ['name' => 'shop', 'repository' => 'acme/shop', 'database' => (string) $this->clusterId('cache')],
            'cache' => ['name' => 'shop', 'repository' => 'acme/shop', 'cache' => (string) $this->clusterId('main')],
            'pool' => ['name' => 'shop', 'repository' => 'acme/shop', 'compute' => 'autoscale', 'pool' => ['min' => 5, 'max' => 2]],
        ] as $field => $input) {
            try {
                $this->apps()->plan('org-1', $input);
                $this->fail("{$field} was accepted.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith("{$field}:", $e->getMessage());
            }
        }
    }

    public function test_starting_records_the_application_with_its_variables_in_the_vault(): void
    {
        $plan = $this->apps()->plan('org-1', ['name' => 'shop', 'repository' => 'acme/shop', 'branch' => 'main', 'previews' => true,
            'server' => ['mode' => 'existing', 'node' => 'web-1.org-1'], 'env' => ['STRIPE_SECRET' => 'sk_live_x', 'EMPTY' => '']]);
        $started = $this->apps()->start('org-1', $plan, 'me@example.com');

        $site = DB::table('sites')->find($started['site']);
        $this->assertSame(['single', 'main', 'github_app', 'git@github.com:acme/shop.git'], [$site->compute, $site->pr_previews_base, $site->git_source, $site->repository]);
        $env = $this->app->make(Sites::class)->env($site);
        $this->assertStringContainsString("STRIPE_SECRET=sk_live_x\n", $env);
        $this->assertStringNotContainsString('EMPTY=', $env);

        $operation = DB::table('operations')->find($started['operation']);
        $this->assertSame(['app_create', (int) $site->id], [$operation->kind, (int) $operation->site_id]);
        $this->assertNull($operation->node_id, 'Locked by itself, so its steps on the server can run while it waits.');
        $this->assertStringNotContainsString('sk_live_x', (string) $operation->meta, 'Secrets stay in the vault.');
        Queue::assertPushed(RunOperation::class, fn ($job) => $job->operationId === $started['operation']);
    }

    public function test_a_node_application_is_made_with_its_runtime_and_its_own_variables_only(): void
    {
        $plan = $this->apps()->plan('org-1', ['name' => 'api', 'repository' => 'acme/api', 'branch' => 'main', 'runtime' => 'node',
            'runtime_config' => ['start' => 'node dist/server.js', 'version' => '20'],
            'server' => ['mode' => 'existing', 'node' => 'web-1.org-1'], 'env' => ['DATABASE_URL' => 'postgres://x']]);
        $started = $this->apps()->start('org-1', $plan, 'me@example.com');

        $site = DB::table('sites')->find($started['site']);
        $this->assertSame('node', $site->runtime);
        $this->assertSame(['start' => 'node dist/server.js', 'version' => '20'], json_decode($site->runtime_config, true));
        $env = $this->app->make(Sites::class)->env($site);
        $this->assertStringContainsString('DATABASE_URL=postgres://x', $env);
        $this->assertStringNotContainsString('APP_KEY', $env, 'None of Laravel\'s defaults.');
    }

    public function test_a_static_site_gets_no_database_cache_uploads_variables_or_backups(): void
    {
        $plan = $this->apps()->plan('org-1', ['name' => 'docs', 'repository' => 'acme/docs', 'branch' => 'main', 'runtime' => 'static',
            'server' => ['mode' => 'existing', 'node' => 'web-1.org-1'], 'database' => 'local:mysql', 'cache' => 'local:valkey', 'backups' => true, 'env' => ['X' => 'y']]);

        $this->assertSame(['none', 'none', 'disk', [], false], [$plan['database'], $plan['cache'], $plan['storage'], $plan['env'], $plan['backups']]);
        $this->assertSame('no database needed · no cache needed', $plan['summary']);
        $this->assertNotContains('database', $plan['steps']);
        $this->assertNotContains('cache', $plan['steps']);
    }

    public function test_a_size_the_region_does_not_sell_is_refused_and_not_offered(): void
    {
        $sizes = [['slug' => 's-1vcpu-1gb', 'available' => true, 'regions' => ['nyc3']], ['slug' => 's-1vcpu-2gb', 'available' => true, 'regions' => ['nyc3', 'tor1']],
            ['slug' => 's-2vcpu-4gb', 'available' => false, 'regions' => ['tor1']]];
        $this->app->make(Vault::class)->put('org-1', 'cloud.digitalocean.token', 'dop_v1_test');
        Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => $sizes])]);
        $request = fn (string $size) => ['name' => 'Shop', 'repository' => 'acme/shop', 'server' => ['mode' => 'new', 'region' => 'tor1', 'size' => $size]];

        try {
            $this->apps()->plan('org-1', $request('s-1vcpu-1gb'));
            $this->fail('A size toronto does not sell was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('server: DigitalOcean does not offer s-1vcpu-1gb in tor1. Pick another size or region.', $e->getMessage());
        }
        $this->assertSame('s-1vcpu-2gb', $this->apps()->plan('org-1', $request('s-1vcpu-2gb'))['server']['size']);

        $this->actingAs(\App\Models\User::factory()->create());
        $this->getJson('/apps/new/sizes?provider=digitalocean&region=tor1')->assertOk()->assertExactJson(['sizes' => ['s-1vcpu-2gb' => '1 vCPU · 2 GB · ~$12/mo']]);
    }

    public function test_a_runtime_that_does_not_exist_or_a_bad_setting_is_refused_before_anything_is_made(): void
    {
        foreach ([['runtime' => 'cobol'], ['runtime' => 'node', 'runtime_config' => ['output' => '../x']]] as $bad) {
            try {
                $this->apps()->plan('org-1', $bad + ['name' => 'api', 'repository' => 'acme/api', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1']]);
                $this->fail('Accepted '.json_encode($bad));
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('runtime:', $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('sites')->count());
    }

    public function test_the_operation_runs_each_step_in_turn_and_stops_at_a_failure(): void
    {
        $plan = $this->apps()->plan('org-1', ['name' => 'shop', 'repository' => 'acme/shop', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1']]);
        $id = $this->apps()->start('org-1', $plan, 'me')['operation'];
        $runner = $this->app->make(OperationRunner::class);
        $child = fn (string $step) => (int) (json_decode((string) DB::table('operations')->find($id)->meta, true)['children'][$step] ?? 0);

        $this->assertSame('waiting', $runner->run($id));
        $site = DB::table('sites')->where('name', 'shop')->first();
        $this->assertSame('shop.203-0-113-7.sslip.io', $site->domain, 'The address step names it after its server.');
        $this->assertStringContainsString('APP_URL=http://shop.203-0-113-7.sslip.io', $this->app->make(Sites::class)->env($site));
        $this->assertSame('deploy', DB::table('operations')->find($child('deploy'))->kind);

        $this->assertSame('waiting', $runner->run($id), 'Waits while the deploy runs.');
        $this->assertSame(0, $child('https'));

        DB::table('operations')->where('id', $child('deploy'))->update(['status' => 'succeeded']);
        $this->assertSame('waiting', $runner->run($id));
        DB::table('operations')->where('id', $child('https'))->update(['status' => 'failed', 'error' => 'NXDOMAIN']);

        $this->assertSame('failed', $runner->run($id));
        $this->assertSame('https failed (operation #'.$child('https').'): NXDOMAIN', DB::table('operations')->find($id)->error);
        $this->assertSame(['address', 'deploy'], json_decode((string) DB::table('operations')->find($id)->meta, true)['done']);
    }

    public function test_a_failed_application_is_retried_from_the_step_that_failed(): void
    {
        $plan = $this->apps()->plan('org-1', ['name' => 'shop', 'repository' => 'acme/shop', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1']]);
        $id = $this->apps()->start('org-1', $plan, 'me')['operation'];
        $runner = $this->app->make(OperationRunner::class);
        $meta = fn () => json_decode((string) DB::table('operations')->find($id)->meta, true);

        $runner->run($id);
        $deploy = $meta()['children']['deploy'];
        DB::table('operations')->where('id', $deploy)->update(['status' => 'failed', 'error' => 'composer exited 1']);
        $this->assertSame('failed', $runner->run($id));

        $runner->queueRetry($id, 'me');
        $this->assertSame('queued', DB::table('operations')->find($id)->status);
        $this->assertArrayNotHasKey('deploy', $meta()['children']);
        $this->assertSame('waiting', $runner->run($id));
        $this->assertNotSame($deploy, $meta()['children']['deploy'], 'The failed step runs afresh.');
        $this->assertSame(['address'], $meta()['done'], 'Steps before it are kept.');

        $this->expectException(InvalidArgumentException::class);
        $runner->queueRetry($id, 'me');
    }

    public function test_a_server_that_could_not_be_made_is_retried_with_another_region_and_size(): void
    {
        $this->app->make(Vault::class)->put('org-1', 'cloud.digitalocean.token', 'dop_v1_test');
        Http::fake(['api.digitalocean.com/v2/sizes*' => Http::response(['sizes' => [
            ['slug' => 's-1vcpu-2gb', 'available' => true, 'regions' => ['nyc3', 'tor1']], ['slug' => 's-1vcpu-1gb', 'available' => true, 'regions' => ['nyc3']]]])]);
        $plan = $this->apps()->plan('org-1', ['name' => 'shop', 'repository' => 'acme/shop', 'server' => ['mode' => 'new', 'region' => 'nyc3', 'size' => 's-1vcpu-1gb']]);
        $id = $this->apps()->start('org-1', $plan, 'me')['operation'];
        $runner = $this->app->make(OperationRunner::class);
        $meta = fn () => json_decode((string) DB::table('operations')->find($id)->meta, true);

        $runner->run($id);
        DB::table('operations')->where('id', $meta()['children']['server'])->update(['status' => 'failed', 'error' => 'Size is not available in this region.']);
        $this->assertSame('failed', $runner->run($id));
        $this->actingAs(\App\Models\User::factory()->create());
        $this->get("/operation?operation={$id}")->assertInertia(fn ($p) => $p->where('view.retryServer.size', 's-1vcpu-1gb')->where('view.retryServer.sizes', ['s-1vcpu-1gb' => '1 vCPU · 1 GB · ~$6/mo', 's-1vcpu-2gb' => '1 vCPU · 2 GB · ~$12/mo']));

        try {
            $runner->queueRetry($id, 'me', ['region' => 'tor1', 'size' => 's-1vcpu-1gb']);
            $this->fail('A size toronto does not sell was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertSame('failed', DB::table('operations')->find($id)->status);
        }

        $runner->queueRetry($id, 'me', ['region' => 'tor1', 'size' => 's-1vcpu-2gb']);
        $this->assertSame(['tor1', 's-1vcpu-2gb'], [$meta()['server']['region'], $meta()['server']['size']]);
        $this->assertSame('queued', DB::table('operations')->find($id)->status);
    }
}
