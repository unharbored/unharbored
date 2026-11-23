<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** The provider migration on a database shaped like production: one platform-made server, one the user provided. */
final class ProviderBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_backfill_types_servers_and_names_each_nodes_provider_and_is_repeatable(): void
    {
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'T', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'droplet', 'slug' => 'web-2', 'external_id' => '9', 'state' => 'active', 'desired_state' => 'active', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['web-1.org-1', 'web-2.org-1'] as $node) {
            DB::table('nodes')->insert(['node_id' => $node, 'organization_id' => 'org-1', 'provider' => 'custom', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('sites')->insert(['project_id' => 1, 'organization_id' => 'org-1', 'node_id' => $node, 'name' => "shop-{$node}", 'domain' => "{$node}.test", 'repository' => 'r', 'branch' => 'main', 'deploy_key_public' => 'k', 'provider' => 'digitalocean', 'created_at' => now(), 'updated_at' => now()]);
        }

        $migration = require base_path('database/migrations/2026_11_10_130000_add_provider_columns.php');
        $migration->up();
        $migration->up();

        $this->assertSame('server', DB::table('resources')->where('slug', 'web-2')->value('type'));
        $this->assertSame('custom', DB::table('nodes')->where('node_id', 'web-1.org-1')->value('provider'));
        $this->assertSame('digitalocean', DB::table('nodes')->where('node_id', 'web-2.org-1')->value('provider'));
        $this->assertSame('custom', DB::table('sites')->where('node_id', 'web-1.org-1')->value('provider'));
        $this->assertSame('digitalocean', DB::table('sites')->where('node_id', 'web-2.org-1')->value('provider'));
    }
}
