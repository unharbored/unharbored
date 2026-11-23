<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class SiteServersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog());
    }

    public function test_an_extra_server_gets_the_sites_own_secrets(): void
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $this->sites()->setEnv($site, "APP_NAME=shop\n");
        $steps = array_column($this->sites()->setupRecipe(Sites::on($site, 'web-2.org-1'))['steps'], null, 'id');

        // What the server gets is both layers: the user's variables, then the injected defaults.
        $this->assertSame($this->sites()->env($site), base64_decode($steps['env']['content_b64']));
        $this->assertStringStartsWith("APP_NAME=shop\n", base64_decode($steps['env']['content_b64']));
        $this->assertStringContainsString("DB_CONNECTION=sqlite\n", base64_decode($steps['env']['content_b64']));
        $this->assertNotSame('', base64_decode($steps['deploy-key']['content_b64']));
    }

    public function test_an_extra_server_serves_plain_http_on_a_tls_site(): void
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['tls' => true]);
        $site = $this->sites()->find('web-1.org-1', 'shop');
        $vhost = fn ($s) => base64_decode(array_column($this->sites()->setupRecipe($s)['steps'], null, 'id')['vhost']['content_b64']);

        $this->assertStringContainsString('ssl_certificate', $vhost($site));
        $this->assertStringNotContainsString('ssl_certificate', $vhost(Sites::on($site, 'web-2.org-1')), 'It has no certificate, so nginx -t would fail.');
    }

    public function test_removal_stops_nginx_workers_and_schedule_then_deletes_the_files(): void
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $paths = array_column($this->sites()->removalRecipe($site)['steps'], 'path');

        $this->assertContains('/etc/nginx/sites-enabled/shop.conf', $paths);
        $this->assertContains('/etc/supervisor/conf.d/cm-shop.conf', $paths);
        $this->assertContains('/etc/cron.d/cm-shop', $paths);
        $this->assertSame('/home/deploy/shop', end($paths), 'The files go last, once nothing runs from them.');
    }

    private function balanced(): \stdClass
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-1', 'external_id' => '111', 'state' => 'active', 'desired_state' => 'present',
            'attributes' => json_encode(['region' => 'nyc3', 'ipv4' => '203.0.113.1', 'private_ipv4' => '10.108.0.3']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sites')->where('id', $site->id)->update(['tls' => true, 'load_balancer_id' => 99]);

        return $this->sites()->find('web-1.org-1', 'shop');
    }

    private function vhost(\stdClass $site): string
    {
        return base64_decode(array_column($this->sites()->setupRecipe($site)['steps'], null, 'id')['vhost']['content_b64']);
    }

    public function test_behind_a_balancer_every_server_serves_https_requests_on_port_80(): void
    {
        $site = $this->balanced();

        foreach ([$site, Sites::on($site, 'web-2.org-1')] as $s) {
            $vhost = $this->vhost($s);
            $this->assertStringNotContainsString('return 301', $vhost, 'The balancer sends HTTPS traffic to port 80; a redirect would loop.');
            $this->assertStringContainsString('fastcgi_param HTTPS on', $vhost);
            $this->assertStringContainsString('real_ip_header X-Forwarded-For', $vhost);
            $this->assertStringContainsString('listen '.Sites::healthPort($site).';', $vhost, 'Health checks carry no site name.');
        }
        $this->assertStringContainsString('listen 443 ssl', $this->vhost($site), 'The primary still answers directly while DNS moves.');
        $this->assertStringNotContainsString('ssl_certificate', $this->vhost(Sites::on($site, 'web-2.org-1')));
    }

    public function test_other_servers_pass_the_certificate_challenge_to_the_primary(): void
    {
        $site = $this->balanced();

        $this->assertStringContainsString('proxy_pass http://10.108.0.3;', $this->vhost(Sites::on($site, 'web-2.org-1')));
        $this->assertStringNotContainsString('proxy_pass http://10.108.0.3;', $this->vhost($site));
    }
}
