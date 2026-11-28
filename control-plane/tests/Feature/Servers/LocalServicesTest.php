<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Servers\LocalServices;
use App\Lib\Sites\NewApplications;
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
use stdClass;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * PostgreSQL, MySQL and Valkey on the application's own server. The password
 * lives in the vault and in one 0600 program that is removed; nothing listens
 * beyond 127.0.0.1; one database and user per site.
 */
final class LocalServicesTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    /** @var list<array<string, mixed>> recipes and exec calls sent to the node */
    private array $recipes = [];

    private bool $recipeFails = false;

    private bool $everythingFails = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'provider' => 'custom', 'created_at' => now(), 'updated_at' => now()]);

        $this->http = new HttpFactory();
        $this->http->fake(function (Request $r) {
            $this->recipes[] = $r->data();
            $failing = $this->recipeFails && str_contains((string) json_encode($r->data()), 'local-database"');

            if ($this->everythingFails) {
                return $this->http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'apt exited 100']);
            }

            return $failing ? $this->http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'psql exited 1: boom'])
                : $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '', 'run' => ['steps' => [['action' => 'create', 'description' => 'done']]]]]);
        });
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()));
        foreach ([LocalServices::class, Sites::class, OperationRunner::class] as $c) {
            $this->app->forgetInstance($c);
        }
    }

    private function site(string $compute = 'single'): stdClass
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop-app', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['compute' => $compute]);

        return DB::table('sites')->where('id', $site->id)->first();
    }

    private function say(): \Closure
    {
        return function (string $a, string $t): void {};
    }

    public function test_each_service_has_an_idempotent_install_recipe_and_an_unknown_one_is_refused(): void
    {
        // Every install first clears an expired root password and a half-finished dpkg, each skipped when there is nothing to do.
        foreach (['pgsql', 'mysql', 'valkey'] as $service) {
            $prepare = array_slice(LocalServices::installRecipe($service)['steps'], 0, 2);
            $this->assertSame(['root-not-expired', 'dpkg-repair'], array_column($prepare, 'id'));
            $this->assertNotEmpty($prepare[0]['unless']);
            $this->assertNotEmpty($prepare[1]['unless']);
        }
        $pg = array_slice(LocalServices::installRecipe('pgsql')['steps'], 2);
        // A server made only for the database never had the stack, and Ubuntu has no PostgreSQL 18: the repository comes first.
        $this->assertSame(['command', 'package', 'service'], array_column($pg, 'type'));
        $this->assertSame('pgdg-repo', $pg[0]['id']);
        $this->assertSame('postgresql-18', $pg[1]['name']);
        $this->assertSame('mysql-server', LocalServices::installRecipe('mysql')['steps'][2]['name']);
        $valkey = LocalServices::installRecipe('valkey')['steps'][2];
        $this->assertStringContainsString('valkey-server', $valkey['args'][1]);
        $this->assertStringContainsString('redis-server', $valkey['args'][1]);
        $this->assertNotEmpty($valkey['unless']);

        $this->expectException(InvalidArgumentException::class);
        LocalServices::installRecipe('mongodb');
    }

    public function test_installing_records_the_service_and_a_second_install_does_nothing(): void
    {
        $services = $this->app->make(LocalServices::class);

        $services->install('org-1', 'web-1.org-1', 'pgsql', $this->say());
        $this->assertTrue($services->installed('web-1.org-1', 'pgsql'));
        $this->assertSame(['pgsql' => ''], $services->on('web-1.org-1'));
        $sent = count($this->recipes);

        $services->install('org-1', 'web-1.org-1', 'pgsql', $this->say());
        $this->assertCount($sent, $this->recipes, 'Already there: nothing sent.');
        $this->assertDatabaseHas('audit_records', ['action' => 'server.service_installed']);
    }

    public function test_a_failed_install_records_nothing_and_says_why(): void
    {
        $this->everythingFails = true;

        try {
            $this->app->make(LocalServices::class)->install('org-1', 'web-1.org-1', 'mysql', $this->say());
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('apt exited 100', $e->getMessage());
        }
        $this->assertSame(0, DB::table('server_services')->count());
    }

    public function test_the_grant_programs_are_valid_php_and_keep_the_password_off_every_command_line(): void
    {
        $password = str_repeat('ab', 16);
        foreach (['pgsql', 'mysql'] as $service) {
            $script = LocalServices::grantScript($service, 'shop_app', $password);
            $path = sys_get_temp_dir().'/grant-'.$service.'-'.getmypid().'.php';
            file_put_contents($path, $script);
            $lint = new Process(['php', '-l', $path]);
            $lint->run();
            unlink($path);
            $this->assertTrue($lint->isSuccessful(), $lint->getOutput().$lint->getErrorOutput());

            // The password is only inside the SQL written to the client's stdin.
            $this->assertStringContainsString($password, $script);
            $this->assertStringNotContainsString($password, explode("\n", $script)[2], 'Not in the proc_open command line.');
            $this->assertMatchesRegularExpression("/proc_open\(\['[^']+'[^\]]*\]/", $script);
        }
        $this->assertStringContainsString('runuser', LocalServices::grantScript('pgsql', 'shop_app', $password));
        $this->assertStringContainsString('\\gexec', LocalServices::grantScript('pgsql', 'shop_app', $password));
    }

    public function test_a_name_or_password_that_could_inject_sql_is_refused(): void
    {
        foreach ([['x"; DROP', str_repeat('a', 32)], ["x'; --", str_repeat('a', 32)], ['shop', "a'; DROP TABLE x; --"], ['shop', 'short']] as [$name, $password]) {
            try {
                LocalServices::grantScript('mysql', $name, $password);
                $this->fail("accepted {$name}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_attaching_postgres_installs_it_creates_the_database_and_points_the_env_at_it(): void
    {
        $site = $this->site();
        $this->app->make(LocalServices::class)->attach($site, 'pgsql', $this->say());

        $names = array_column($this->recipes, 'args');
        $steps = collect($this->recipes)->flatMap(fn ($r) => $r['args']['spec']['steps'] ?? [])->all();
        $script = collect($steps)->firstWhere('id', 'script');
        $this->assertTrue($script['sensitive']);
        $this->assertSame('0600', $script['mode']);
        $this->assertContains('forget', array_column($steps, 'id'));

        $injected = $this->app->make(Sites::class)->injected($site);
        $this->assertSame(['pgsql', '127.0.0.1', '5432', 'shop_app', 'shop_app'], array_map(fn ($k) => $injected[$k]['value'], ['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME']));
        $this->assertSame(32, strlen($injected['DB_PASSWORD']['value']));
        $this->assertArrayNotHasKey('DB_SSLMODE', $injected);
        $this->assertStringContainsString($injected['DB_PASSWORD']['value'], base64_decode($script['content_b64']), 'The password is in the one-off program.');
        $this->assertSame('local:pgsql', DB::table('sites')->where('id', $site->id)->value('database_mode'));

        // Never in the audit log or anywhere readable.
        $this->assertStringNotContainsString($injected['DB_PASSWORD']['value'], json_encode(DB::table('audit_records')->get()));
    }

    public function test_attaching_valkey_moves_cache_sessions_and_queue_onto_the_server(): void
    {
        $site = $this->site();
        $this->app->make(LocalServices::class)->attach($site, 'valkey', $this->say());

        $injected = $this->app->make(Sites::class)->injected($site);
        $this->assertSame(['127.0.0.1', 'redis', 'redis', 'redis', 'shop_app_'], array_map(fn ($k) => $injected[$k]['value'], ['REDIS_HOST', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION', 'REDIS_PREFIX']));
        $this->assertArrayNotHasKey('REDIS_PASSWORD', $injected);
        $this->assertSame('local:valkey', DB::table('sites')->where('id', $site->id)->value('cache_mode'));
    }

    public function test_a_failed_grant_removes_the_program_changes_nothing_and_says_why(): void
    {
        $this->recipeFails = true;
        $site = $this->site();

        try {
            $this->app->make(LocalServices::class)->attach($site, 'mysql', $this->say());
            $this->fail();
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $last = end($this->recipes);
        $this->assertSame('local-database-cleanup', $last['args']['spec']['name'], 'The program is removed whatever happened.');
        $this->assertSame('sqlite', DB::table('sites')->where('id', $site->id)->value('database_mode'));
        $this->assertArrayNotHasKey('DB_HOST', $this->app->make(Sites::class)->injected($site));
    }

    public function test_more_than_one_server_needs_a_database_they_share(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('single server');
        $this->app->make(LocalServices::class)->attach($this->site('autoscale'), 'pgsql', $this->say());
    }

    public function test_none_drops_the_defaults_and_installs_nothing(): void
    {
        $site = $this->site();
        $this->assertArrayHasKey('DB_CONNECTION', $this->app->make(Sites::class)->injected($site));

        $this->app->make(LocalServices::class)->detach($site, 'database');
        $this->app->make(LocalServices::class)->detach($site, 'cache');

        $injected = $this->app->make(Sites::class)->injected($site);
        $this->assertSame([], array_intersect(['DB_CONNECTION', 'DB_DATABASE', 'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION'], array_keys($injected)));
        $this->assertSame(['none', 'none'], [DB::table('sites')->where('id', $site->id)->value('database_mode'), DB::table('sites')->where('id', $site->id)->value('cache_mode')]);
        $this->assertSame([], $this->recipes);
    }

    private function plans(): NewApplications
    {
        return $this->app->make(NewApplications::class);
    }

    private function answers(array $over = []): array
    {
        return $over + ['name' => 'shop', 'repository' => 'acme/shop', 'branch' => 'main', 'compute' => 'single', 'provider' => 'digitalocean',
            'server' => ['mode' => 'existing', 'node' => 'web-1.org-1'], 'database' => 'sqlite'];
    }

    public function test_the_wizard_plans_services_on_the_server_and_none_and_labels_who_manages_them(): void
    {
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'k', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->update(['provider' => 'digitalocean']);

        $plan = $this->plans()->plan('org-1', $this->answers(['database' => 'local:pgsql', 'cache' => 'local:valkey']));
        $this->assertSame(['local:pgsql', 'local:valkey'], [$plan['database'], $plan['cache']]);
        $this->assertSame(['server' => false] + ['database' => true, 'cache' => true], ['server' => in_array('server', $plan['steps'], true), 'database' => in_array('database', $plan['steps'], true), 'cache' => in_array('cache', $plan['steps'], true)]);
        $this->assertSame('PostgreSQL on the server · Valkey on the server · uploads on disk', $plan['summary']);

        $none = $this->plans()->plan('org-1', $this->answers(['database' => 'none', 'cache' => 'none']));
        $this->assertNotContains('database', $none['steps']);
        $this->assertNotContains('cache', $none['steps']);
        $this->assertSame('no database (managed by you) · no cache (managed by you) · uploads on disk', $none['summary']);
    }

    public function test_the_wizard_refuses_what_makes_no_sense(): void
    {
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'k', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->update(['provider' => 'digitalocean']);

        foreach ([
            [['database' => 'none'], 'cache: the database cannot hold the cache'],
            [['database' => 'local:mysql', 'compute' => 'autoscale', 'server' => ['mode' => 'new', 'region' => 'nyc3', 'size' => 's-1vcpu-2gb']], 'database: autoscaling needs a database every server shares'],
            [['cache' => 'local:valkey', 'compute' => 'autoscale', 'database' => 'none', 'server' => ['mode' => 'new', 'region' => 'nyc3', 'size' => 's-1vcpu-2gb']], 'cache: Valkey on the server is for a single server'],
            [['database' => 'local:oracle'], 'database:'],
        ] as [$over, $message]) {
            try {
                $this->plans()->plan('org-1', $this->answers($over));
                $this->fail("expected {$message}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith($message, $e->getMessage());
            }
        }
    }

    public function test_the_local_service_operation_installs_then_attaches_in_order(): void
    {
        $site = $this->site();
        $op = $this->app->make(OperationRunner::class)->queueLocalService($site->id, 'mysql', 'test', 'matt');

        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($op));

        $row = DB::table('operations')->find($op);
        $this->assertSame(['done', 'done'], array_column(json_decode($row->stages, true), 'status'));
        $this->assertSame('local:mysql', DB::table('sites')->where('id', $site->id)->value('database_mode'));
        $this->assertStringNotContainsString($this->app->make(Sites::class)->injected($site)['DB_PASSWORD']['value'], (string) $row->log);
    }

    public function test_installing_from_the_server_card_is_an_operation(): void
    {
        $op = $this->app->make(OperationRunner::class)->queueServiceInstall('web-1.org-1', 'valkey', 'console', 'matt');

        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($op));
        $this->assertTrue($this->app->make(LocalServices::class)->installed('web-1.org-1', 'valkey'));
    }

    public function test_without_a_backup_bucket_a_local_database_is_dumped_to_its_own_servers_disk(): void
    {
        $site = $this->site();
        $this->app->make(LocalServices::class)->attach($site, 'pgsql', $this->say());
        $site = DB::table('sites')->where('id', $site->id)->first();
        $this->recipes = [];

        $result = $this->app->make(\App\Lib\Sites\SiteBackups::class)->take($site, 'nightly');

        $this->assertStringStartsWith('/home/deploy/shop-app/shared/backups/', $result['object']);
        $this->assertStringEndsWith('-nightly.pgdump', $result['object']);
        $exec = collect($this->recipes)->first(fn ($r) => ($r['name'] ?? $r['args']['name'] ?? null) === 'runuser' || str_contains(json_encode($r), 'runuser'));
        $this->assertNotNull($exec, 'The dump ran on the server.');
        $this->assertStringContainsString('filemtime', json_encode($exec), 'Old dumps are pruned there.');
        $this->assertDatabaseHas('audit_records', ['action' => 'site.backed_up']);
    }

    public function test_the_nightly_command_dumps_only_local_databases_when_there_is_no_bucket(): void
    {
        $local = $this->site();
        DB::table('sites')->where('id', $local->id)->update(['database_mode' => 'local:mysql', 'current_release' => 1, 'backups' => true]);
        $other = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');
        DB::table('sites')->where('id', $other->id)->update(['current_release' => 1, 'backups' => true]);

        $this->artisan('sites:backup')->expectsOutputToContain('only databases on their own servers')->expectsOutputToContain('shop-app')->assertSuccessful();
        $this->assertSame(1, DB::table('operations')->where('kind', 'site_backup')->count());
    }
}
