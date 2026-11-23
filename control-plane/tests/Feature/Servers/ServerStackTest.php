<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Servers\ServerStack;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Tests\TestCase;

/**
 * The stack recipe runs as root on a customer's server and is re-applied
 * whenever something looks wrong, so the properties that matter are that the
 * agent can parse it and that a second run changes nothing it should not.
 */
final class ServerStackTest extends TestCase
{
    use RefreshDatabase;

    /** Mirrors internal/recipe/spec.go. The agent rejects anything else. */
    private const AGENT_STEP_TYPES = ['file', 'dir', 'symlink', 'absent', 'command', 'package', 'service'];

    /** @return list<array<string, mixed>> */
    private function steps(): array
    {
        return (new ServerStack())->spec()['steps'];
    }

    private function indexOf(string $id): int
    {
        foreach ($this->steps() as $i => $step) {
            if ($step['id'] === $id) {
                return $i;
            }
        }
        $this->fail("No step {$id}.");
    }

    public function test_every_step_is_a_type_the_agent_implements(): void
    {
        foreach ($this->steps() as $step) {
            $this->assertContains($step['type'], self::AGENT_STEP_TYPES, "Step {$step['id']} has type {$step['type']}.");
        }
    }

    public function test_apt_is_told_to_retry_a_download_before_any_package_is_installed(): void
    {
        $steps = $this->steps();

        $this->assertSame('apt-retries', $steps[0]['id']);
        $this->assertSame('/etc/apt/apt.conf.d/80-cm-retries', $steps[0]['path']);
        $this->assertSame("Acquire::Retries \"5\";\n", base64_decode($steps[0]['content_b64']));
    }

    public function test_step_ids_are_unique(): void
    {
        $ids = array_column($this->steps(), 'id');

        $this->assertSame(count($ids), count(array_unique($ids)));
    }

    public function test_every_command_step_is_guarded(): void
    {
        // An unguarded command runs on every apply, and re-applying the stack
        // is the normal response to a server that looks wrong.
        foreach ($this->steps() as $step) {
            if ($step['type'] === 'command') {
                $this->assertNotEmpty($step['unless'] ?? [], "Command step {$step['id']} has no guard.");
            }
        }
    }

    public function test_php_runs_as_the_deploy_user_and_nginx_can_reach_the_socket(): void
    {
        $pool = base64_decode($this->steps()[$this->indexOf('php-fpm-pool')]['content_b64'], true);

        $this->assertStringContainsString('user = deploy', $pool);
        $this->assertStringContainsString('group = deploy', $pool);
        $this->assertStringContainsString('listen.owner = www-data', $pool);
        $this->assertStringContainsString('listen = /run/php/php8.4-fpm.sock', $pool);
    }

    public function test_php_comes_from_the_ppa_and_composer_is_not_from_apt(): void
    {
        // apt's composer would pull the PPA's newest php-cli and make it the
        // system php, silently changing the version every site runs on.
        $packages = array_column(array_filter($this->steps(), fn ($s) => $s['type'] === 'package'), 'name');

        $this->assertNotContains('composer', $packages);
        $this->assertContains('php8.4-fpm', $packages);
        $this->assertLessThan($this->indexOf('pkg-php-fpm'), $this->indexOf('php-ppa'));
        $this->assertSame('/usr/local/bin/composer', $this->steps()[$this->indexOf('composer')]['unless'][2]);
    }

    public function test_apt_composer_is_removed_but_php_branches_are_kept(): void
    {
        // A server may run several PHP branches side by side, so the stack
        // never purges one. Only apt's composer goes, in favour of the phar.
        $retire = $this->steps()[$this->indexOf('retire-apt-composer')];

        $this->assertStringContainsString('apt-get purge -y composer', $retire['args'][1]);
        $this->assertStringNotContainsString('php8.', $retire['args'][1]);
        $this->assertGreaterThan($this->indexOf('composer'), $this->indexOf('retire-apt-composer'));
    }

    public function test_installing_another_php_branch_gets_its_own_pool_and_service(): void
    {
        $steps = (new ServerStack())->phpInstallRecipe('8.3')['steps'];

        $packages = array_column(array_filter($steps, fn ($s) => $s['type'] === 'package'), 'name');
        $this->assertContains('php8.3-fpm', $packages);
        $this->assertContains('php8.3-pgsql', $packages);

        $pool = collect($steps)->firstWhere('id', 'php-fpm-pool');
        $this->assertStringContainsString('listen = /run/php/php8.3-fpm.sock', base64_decode($pool['content_b64']));

        $services = array_column(array_filter($steps, fn ($s) => $s['type'] === 'service'), 'name');
        $this->assertContains('php8.3-fpm', $services);
    }

    public function test_the_deploy_home_is_traversable_by_nginx(): void
    {
        $home = $this->steps()[$this->indexOf('deploy-home')];

        $this->assertSame('/home/deploy', $home['path']);
        $this->assertSame('0755', $home['mode']);
        $this->assertGreaterThan($this->indexOf('deploy-user'), $this->indexOf('deploy-home'));
    }

    public function test_the_distribution_default_site_is_removed(): void
    {
        $this->assertSame('/etc/nginx/sites-enabled/default', $this->steps()[$this->indexOf('nginx-no-default')]['path']);
    }

    public function test_configuration_is_written_before_the_reloads_that_apply_it(): void
    {
        $this->assertGreaterThan($this->indexOf('php-fpm-pool'), $this->indexOf('reload-php8.4-fpm'));
        $this->assertGreaterThan($this->indexOf('nginx-catch-all'), $this->indexOf('reload-nginx'));
        $this->assertGreaterThan($this->indexOf('svc-nginx'), $this->indexOf('reload-nginx'));
    }

    public function test_packages_are_installed_before_any_service_is_managed(): void
    {
        $lastPackage = max(array_keys(array_filter($this->steps(), fn ($s) => $s['type'] === 'package')));
        $firstService = min(array_keys(array_filter($this->steps(), fn ($s) => $s['type'] === 'service')));

        $this->assertLessThan($firstService, $lastPackage);
    }

    public function test_the_command_sends_the_recipe_and_reports_what_changed(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response([
            'status' => 'succeeded',
            'outcome_known' => true,
            'detail' => ['run' => ['steps' => [
                ['id' => 'pkg-nginx', 'description' => 'package nginx installed', 'action' => 'noop'],
                ['id' => 'reload-nginx', 'description' => 'service nginx reloaded', 'action' => 'run'],
            ]]],
        ], 200)]);

        $this->app->instance(GatewayClient::class, new GatewayClient('http://gateway.test', 'secret', $http, new AuditLog()));

        $this->artisan('server:install-stack', ['organization' => 'org-1', 'node' => 'web-1.org-1'])
            ->expectsOutputToContain('2 step(s), 1 changed.')
            ->assertSuccessful();

        $http->assertSent(function (Request $r) {
            return str_ends_with($r->url(), '/v1/nodes/web-1.org-1/commands')
                && $r['kind'] === 'apply_recipe'
                && $r['args']['spec']['name'] === 'server-stack'
                && $r['args']['dry_run'] === false;
        });
    }

    public function test_a_failed_recipe_fails_the_command(): void
    {
        $http = new HttpFactory();
        $http->fake(['*' => $http->response([
            'status' => 'failed',
            'error' => 'recipe step "pkg-nginx" failed: apt-get exited 100',
            'outcome_known' => true,
        ], 200)]);

        $this->app->instance(GatewayClient::class, new GatewayClient('http://gateway.test', 'secret', $http, new AuditLog()));

        $this->artisan('server:install-stack', ['organization' => 'org-1', 'node' => 'web-1.org-1'])
            ->expectsOutputToContain('apt-get exited 100')
            ->assertFailed();
    }
}
