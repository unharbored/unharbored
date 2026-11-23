<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Lib\Audit\AuditLog;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** An application's overview shows what it runs on: network, compute, data. */
final class SiteOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_overview_names_its_network_compute_and_data(): void
    {
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_url' => 'http://gw.invalid']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = (new Sites($this->app->make(Vault::class), new AuditLog()))->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'database_cluster', 'slug' => 'main', 'external_id' => 'x', 'state' => 'active',
            'desired_state' => 'present', 'attributes' => json_encode(['engine' => 'pg']), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('sites')->where('id', $site->id)->update(['database_cluster_id' => DB::table('resources')->value('id')]);

        $topology = $this->get('/site?site='.$site->id)->assertOk()->inertiaProps('view.topology');
        $text = json_encode($topology, JSON_UNESCAPED_UNICODE);

        $this->assertSame(['Network', 'Compute', 'Data'], array_keys($topology));
        foreach (['shop.example.com', 'web-1 · primary', 'PostgreSQL', 'the server disk'] as $expected) {
            $this->assertStringContainsString($expected, $text);
        }
    }

    public function test_a_static_site_shows_only_its_network_and_servers(): void
    {
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_url' => 'http://gw.invalid']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = (new Sites($this->app->make(Vault::class), new AuditLog()))->create('org-1', 'web-1.org-1', 'docs', 'docs.example.com', 'git@github.com:acme/docs.git');
        DB::table('sites')->where('id', $site->id)->update(['runtime' => 'static']);

        $topology = $this->get('/site?site='.$site->id)->assertOk()->inertiaProps('view.topology');

        $this->assertSame(['Network', 'Compute'], array_keys($topology));
        $this->assertSame(['Servers'], array_column($topology['Compute'], 'title'));
    }
}
