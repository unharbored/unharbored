<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * An application runs on the PHP branch chosen for it, so two applications
 * on one server can run different versions.
 */
final class PhpVersionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_url' => 'http://gw.test', 'control_plane.gateway_token' => 't']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
    }

    private function site(string $version = '8.4'): object
    {
        $sites = $this->app->make(Sites::class);
        $site = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['php_version' => $version]);

        return $sites->find('web-1.org-1', 'shop');
    }

    private function deployer(): Deployer
    {
        return new Deployer($this->app->make(Sites::class), $this->app->make(GatewayClient::class), new AuditLog(), $this->app->make(GitHubApp::class));
    }

    public function test_an_application_defaults_to_the_platform_php(): void
    {
        $site = $this->site();

        $this->assertSame('8.4', Sites::phpVersion($site));
    }

    public function test_the_build_runs_under_the_sites_php(): void
    {
        $steps = $this->deployer()->buildRecipe('/r', php: '8.3')['steps'];
        $commands = implode(' ', array_map(fn ($s) => implode(' ', $s['args']), $steps));

        $this->assertStringContainsString('/usr/bin/php8.3', $commands);
        $this->assertStringNotContainsString('/usr/bin/php8.4', $commands);
    }

    public function test_the_vhost_serves_the_sites_php_fpm_socket(): void
    {
        $site = $this->site('8.3');
        $vhost = base64_decode(collect($this->app->make(Sites::class)->setupRecipe($site)['steps'])->firstWhere('id', 'vhost')['content_b64']);

        $this->assertStringContainsString('/run/php/php8.3-fpm.sock', $vhost);
    }

    public function test_workers_and_the_scheduler_run_the_sites_php(): void
    {
        $site = $this->site('8.3');
        $deployer = $this->deployer();

        $after = collect($deployer->afterSwitchRecipe($site)['steps'])->keyBy('id');
        $this->assertStringContainsString('/usr/bin/php8.3', base64_decode($after['scheduler']['content_b64']));
        $this->assertStringContainsString('/usr/bin/php8.3', implode(' ', $after['queue-restart']['args']));

        $supervisor = base64_decode(collect($deployer->programsRecipe($site)['steps'])->firstWhere('id', 'supervisor-conf')['content_b64']);
        $this->assertStringContainsString('/usr/bin/php8.3', $supervisor);
    }
}
