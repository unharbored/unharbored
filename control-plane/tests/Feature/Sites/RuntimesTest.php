<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Services\ContainerRecipes;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Runtimes;
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
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

/**
 * Applications that are not Laravel: a static site, Node, Go. What each builds, runs and serves.
 */
final class RuntimesTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{kind: string, spec: array<string, mixed>}> */
    private array $calls = [];

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->http = new HttpFactory;
        $this->http->fake(['gw.test/*' => function (Request $r) {
            $this->calls[] = ['kind' => $r['kind'], 'spec' => $r['args']['spec'] ?? []];

            return $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => "abc123\n", 'current' => 'older', 'deleted' => []]]);
        }]);
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog);
    }

    private function deployer(): Deployer
    {
        return new Deployer($this->sites(), new GatewayClient('http://gw.test', 't', $this->http, new AuditLog), new AuditLog,
            new GitHubApp($this->http, $this->app->make(Vault::class), new AuditLog));
    }

    private function site(string $runtime, array $changes = []): stdClass
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'https://github.com/acme/shop.git');

        return $this->sites()->setRuntime($site, $runtime, $changes);
    }

    /** @return array<string, array<string, mixed>> The steps of a recipe the deploy sent, by id. */
    private function steps(string $recipe): array
    {
        foreach ($this->calls as $c) {
            if (($c['spec']['name'] ?? '') === $recipe) {
                return array_column($c['spec']['steps'], null, 'id');
            }
        }

        return [];
    }

    private function script(array $step): string
    {
        return implode(' ', $step['args']);
    }

    public function test_laravel_is_what_an_application_is_unless_set_otherwise(): void
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');

        $this->assertSame('php', $site->runtime);
        $this->assertTrue(Runtimes::has($site, 'composer'));
    }

    public function test_only_laravel_has_composer_fpm_workers_and_the_rest_and_the_settings_page_is_told_which(): void
    {
        $this->actingAs(User::factory()->create());
        $laravel = $this->sites()->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');
        $node = $this->site('node');
        $container = (object) ['runtime' => 'dockerfile'];

        foreach (['composer', 'fpm', 'workers', 'scheduler', 'php-version', 'env-file', 'framework-defaults'] as $capability) {
            $this->assertTrue(Runtimes::has($laravel, $capability), $capability);
            $this->assertFalse(Runtimes::has($node, $capability), $capability);
            $this->assertFalse(Runtimes::has($container, $capability), $capability);
        }
        $this->get("/site/settings?site={$laravel->id}&tab=build")->assertInertia(fn ($p) => $p->where('site.capabilities', Runtimes::capabilities($laravel)));
        $this->get("/site/settings?site={$node->id}&tab=build")->assertInertia(fn ($p) => $p->where('site.capabilities', []));
    }

    public function test_a_static_site_that_builds_uses_the_node_version_it_was_given(): void
    {
        $c = Runtimes::config($this->site('static', ['build' => 'npm run build', 'version' => '20']));

        $this->assertSame(['node', '20'], [$c['tool'], $c['version']]);
    }

    public function test_a_static_site_that_builds_gets_node_22_unless_told_otherwise(): void
    {
        $c = Runtimes::config($this->site('static', ['build' => 'npm run build']));
        $this->assertSame(['node', '22'], [$c['tool'], $c['version']]);
    }

    public function test_build_commands_on_several_lines_stop_at_the_first_failure(): void
    {
        $site = $this->site('static', ['build' => "npm ci\nnpm run build"]);
        $steps = array_column(Runtimes::buildRecipe($site, '/home/deploy/shop/releases/1')['steps'], null, 'id');

        $script = end($steps['build']['args']);
        $this->assertStringContainsString("set -e\nnpm ci\nnpm run build", $script);
    }

    public function test_a_runtime_starts_with_its_presets_and_keeps_only_what_was_changed(): void
    {
        $site = $this->site('node', ['version' => '20', 'start' => 'node server.js']);

        $c = Runtimes::config($site);
        $this->assertSame('20', $c['version']);
        $this->assertSame('node server.js', $c['start']);
        $this->assertSame('npm run build --if-present', $c['build'], 'untouched, so the preset');
        $this->assertEquals(['version' => '20', 'start' => 'node server.js'], json_decode($site->runtime_config, true));
        $this->assertSame(4000 + $site->id, $c['port']);
    }

    public function test_the_band_cloud_manager_keeps_covers_container_host_ports_and_nothing_else_is_refused(): void
    {
        $this->assertSame([20000, 23999], [Runtimes::RESERVED_FROM, Runtimes::RESERVED_TO]);
        $this->assertGreaterThanOrEqual(Runtimes::RESERVED_FROM, ContainerRecipes::PORT_MIN);
        $this->assertLessThanOrEqual(Runtimes::RESERVED_TO, ContainerRecipes::PORT_MAX);
        foreach ([19999, 24000, 3000, 65535] as $ok) {
            $this->assertSame(['port' => $ok], Runtimes::validate('node', ['port' => $ok]));
            $this->assertFalse(Runtimes::reservedPort($ok));
        }
        foreach ([20000, 22999, 23000, 23999] as $bad) {
            $this->assertTrue(Runtimes::reservedPort($bad));
        }
        $this->expectExceptionMessage('not 20000 to 23999');
        Runtimes::validate('node', ['port' => 23001]);
    }

    public function test_the_default_port_of_an_application_never_lands_in_the_reserved_band(): void
    {
        foreach ([0, 1, 1999, 9999, 10000, 19999, 20000, 123456, 99999999] as $id) {
            $port = Runtimes::defaultPort((object) ['id' => $id]);
            $this->assertFalse(Runtimes::reservedPort($port), "site {$id} would get {$port}");
            $this->assertGreaterThanOrEqual(4000, $port);
        }
    }

    public function test_what_is_not_a_command_or_a_version_or_a_path_is_refused(): void
    {
        foreach ([['version' => 'twenty'], ['output' => '../etc'], ['output' => '/abs'], ['health' => 'health'], ['port' => 80], ['port' => 21000], ['port' => 23000], ['port' => 23500], ['port' => 23999], ['start' => '']] as $bad) {
            try {
                Runtimes::validate('node', $bad);
                $this->fail('Accepted '.json_encode($bad));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->expectException(InvalidArgumentException::class);
        Runtimes::validate('ruby', []);
    }

    public function test_a_new_application_that_is_not_laravel_has_none_of_laravels_defaults_injected(): void
    {
        $site = $this->site('go');

        $env = $this->sites()->env($site);
        $this->assertStringNotContainsString('APP_KEY', $env);
        $this->assertStringNotContainsString('DB_CONNECTION', $env);
    }

    public function test_node_builds_with_its_toolchain_and_never_runs_composer_or_artisan(): void
    {
        $site = $this->site('node');
        $this->deployer()->deploy($site, now: '20261111T000000Z');

        $build = $this->steps('build');
        $this->assertSame(['mise', 'toolchain', 'install', 'build', 'release-present'], array_keys($build));
        $this->assertStringContainsString("install --yes 'node@22'", $this->script($build['toolchain']));
        $this->assertStringContainsString("'node@22' -- sh -c", $this->script($build['build']));
        $this->assertStringContainsString('npm run build --if-present', $this->script($build['build']));
        $this->assertStringNotContainsString('composer', json_encode($this->calls));
        $this->assertStringNotContainsString('artisan', json_encode($this->calls));
    }

    public function test_a_node_application_runs_as_one_supervised_process_with_its_port_and_variables(): void
    {
        $site = $this->site('node', ['start' => 'node server.js']);
        $this->deployer()->deploy($site, now: '20261111T000000Z');

        $conf = base64_decode($this->steps('after-switch-shop')['supervisor-conf']['content_b64']);
        $this->assertStringContainsString('[program:cm-shop-app]', $conf);
        $this->assertStringContainsString('PORT="'.(4000 + $site->id).'"', $conf);
        // sh -c '…' with the inner quotes escaped: what supervisor splits back into one command.
        $this->assertStringContainsString("-- node server.js'", $conf);
        $this->assertStringContainsString('.local/bin/mise', $conf);
        $this->assertStringContainsString('node@22', $conf);
        $this->assertStringContainsString('shared/.env', $conf);
        $this->assertStringNotContainsString('queue:work', $conf);
    }

    public function test_after_the_switch_the_process_restarts_and_is_waited_for_with_no_php_steps(): void
    {
        $site = $this->site('node');
        $this->deployer()->deploy($site, now: '20261111T000000Z');

        $after = $this->steps('after-switch-shop');
        $this->assertArrayHasKey('restart-cm-shop-app', $after);
        $this->assertArrayHasKey('health', $after);
        $this->assertStringContainsString('http://127.0.0.1:'.(4000 + $site->id).'/', $this->script($after['health']));
        foreach (['queue-restart', 'reload-php-fpm', 'scheduler'] as $php) {
            $this->assertArrayNotHasKey($php, $after);
        }
    }

    public function test_go_builds_a_binary_and_runs_it(): void
    {
        $site = $this->site('go');
        $this->deployer()->deploy($site, now: '20261111T000000Z');

        $this->assertStringContainsString('go build -o bin/app .', $this->script($this->steps('build')['build']));
        $this->assertStringContainsString("install --yes 'go@latest'", $this->script($this->steps('build')['toolchain']));
        $conf = base64_decode($this->steps('after-switch-shop')['supervisor-conf']['content_b64']);
        $this->assertStringContainsString('go@latest', $conf);
        $this->assertStringContainsString("-- ./bin/app'", $conf);
    }

    public function test_a_release_command_runs_once_on_the_primary_before_the_switch(): void
    {
        $site = $this->site('node', ['release' => 'npx prisma migrate deploy']);
        $this->deployer()->deploy($site, now: '20261111T000000Z');

        $this->assertStringContainsString('npx prisma migrate deploy', $this->script($this->steps('build')['release']));
    }

    public function test_a_static_site_with_nothing_to_build_has_no_toolchain_no_process_and_no_restart(): void
    {
        $site = $this->site('static');
        $this->deployer()->deploy($site, now: '20261111T000000Z');

        $this->assertSame(['release-present'], array_keys($this->steps('build')));
        $after = $this->steps('after-switch-shop');
        $this->assertSame('absent', $after['supervisor-conf']['type'], 'no program');
        $this->assertArrayNotHasKey('health', $after);
    }

    public function test_a_static_site_that_builds_gets_node(): void
    {
        $site = $this->site('static', ['build' => 'npm run build', 'install' => 'npm ci', 'output' => 'dist']);
        $this->deployer()->deploy($site, now: '20261111T000000Z');

        $build = $this->steps('build');
        $this->assertStringContainsString('node@22', $this->script($build['toolchain']));
        $this->assertStringContainsString('npm ci', $this->script($build['install']));
    }

    public function test_nginx_serves_a_static_folder_and_can_fall_back_to_index_html(): void
    {
        $site = $this->site('static', ['output' => 'dist']);
        $vhost = $this->sites()->generatedVhost($site);
        $this->assertStringContainsString('root /srv/sites/shop/current/dist;', str_replace(Sites::root($site), '/srv/sites/shop', $vhost));
        $this->assertStringContainsString('try_files $uri $uri/ =404;', $vhost);
        $this->assertStringNotContainsString('fastcgi', $vhost);

        $site = $this->sites()->setRuntime($site, 'static', ['spa' => true]);
        $this->assertStringContainsString('try_files $uri $uri/ /index.html;', $this->sites()->generatedVhost($site));
    }

    public function test_nginx_passes_a_node_or_go_application_to_its_port_with_websockets(): void
    {
        $site = $this->site('node');
        $vhost = $this->sites()->generatedVhost($site);

        $this->assertStringContainsString('proxy_pass http://127.0.0.1:'.(4000 + $site->id).';', $vhost);
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $vhost);
        $this->assertStringNotContainsString('fastcgi', $vhost);
        $this->assertStringContainsString('acme-challenge', $vhost, 'a certificate can still be issued');
    }

    public function test_a_laravel_vhost_is_unchanged(): void
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');

        $this->assertStringContainsString('fastcgi_pass unix:/run/php/', $this->sites()->generatedVhost($site));
    }

    public function test_it_can_go_back_to_laravel_and_its_settings_go_with_it(): void
    {
        $site = $this->site('node', ['version' => '20']);
        $site = $this->sites()->setRuntime($site, 'php');

        $this->assertSame('php', $site->runtime);
        $this->assertNull($site->runtime_config);
    }

    public function test_the_runtime_change_is_audited(): void
    {
        $this->site('go');

        $this->assertTrue(DB::table('audit_records')->where('action', 'site.runtime_changed')->exists());
    }

    public function test_the_settings_page_changes_what_the_application_is_and_rewrites_the_web_server(): void
    {
        Queue::fake();
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'https://github.com/acme/shop.git');

        $rt = null;
        $this->get("/site/settings?site={$site->id}&tab=build")->assertInertia(function (Assert $page) use (&$rt) {
            $page->where('data.rt.runtime', 'php');
            $rt = $page->toArray()['props']['data']['rt'];
        });

        $url = "/site/settings/{$site->id}/runtime";
        $this->post($url, ['rt' => ['runtime' => 'node', 'start' => 'node server.js', 'build' => ''] + $rt])->assertRedirect();

        $row = DB::table('sites')->where('id', $site->id)->first();
        $this->assertSame('node', $row->runtime);
        $this->assertSame(['start' => 'node server.js'], json_decode($row->runtime_config, true), 'Blank fields are not stored.');
        $this->assertSame(1, DB::table('operations')->where('kind', 'web_server')->count());

        $this->post($url, ['rt' => ['runtime' => 'node', 'port' => 80] + $rt])->assertSessionHasErrors('runtime');
    }

    public function test_install_and_multi_line_build_commands_saved_for_a_static_site_come_back_on_the_page(): void
    {
        Queue::fake();
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'https://github.com/acme/shop.git');

        $this->post("/site/settings/{$site->id}/runtime", ['rt' => ['runtime' => 'static', 'root' => '', 'version' => '', 'install' => 'npm ci', 'build' => "npm run lint\nnpm run build", 'release' => '', 'start' => '', 'output' => 'dist', 'health' => '', 'port' => '', 'spa' => false]])->assertRedirect();

        $this->get("/site/settings?site={$site->id}&tab=build")->assertInertia(fn (Assert $page) => $page
            ->where('data.rt.runtime', 'static')
            ->where('data.rt.install', 'npm ci')
            ->where('data.rt.build', "npm run lint\nnpm run build")
            ->where('data.rt.output', 'dist'));
    }
}
