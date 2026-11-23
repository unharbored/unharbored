<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every server, resource and application records the provider it lives on, set
 * once and never changed. Idempotent, so a copy of production can be tried first.
 *
 *  - resources.provider: digitalocean for everything that exists today.
 *    The resource type 'droplet' becomes 'server'.
 *  - nodes.provider: digitalocean where a matching server resource exists, else
 *    custom (a machine the user provided, such as web-1).
 *  - sites.provider: copied from the node the application runs on.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['resources', 'nodes', 'sites'] as $table) {
            if (! Schema::hasColumn($table, 'provider')) {
                Schema::table($table, fn (Blueprint $t) => $t->string('provider')->default($table === 'nodes' ? 'custom' : 'digitalocean')->after('id'));
            }
        }

        DB::table('resources')->where('type', 'droplet')->update(['type' => 'server']);

        // A node whose slug has a server resource was created by the platform.
        foreach (DB::table('nodes')->get(['id', 'node_id', 'organization_id']) as $node) {
            $slug = strstr((string) $node->node_id, '.', true) ?: (string) $node->node_id;
            $made = DB::table('resources')->where('organization_id', $node->organization_id)->where('type', 'server')->where('slug', $slug)->value('provider');
            DB::table('nodes')->where('id', $node->id)->update(['provider' => $made ?: 'custom']);
        }

        foreach (DB::table('sites')->get(['id', 'node_id']) as $site) {
            $provider = DB::table('nodes')->where('node_id', $site->node_id)->value('provider');
            DB::table('sites')->where('id', $site->id)->update(['provider' => $provider ?: 'digitalocean']);
        }
    }

    public function down(): void
    {
        DB::table('resources')->where('type', 'server')->update(['type' => 'droplet']);
        foreach (['resources', 'nodes', 'sites'] as $table) {
            if (Schema::hasColumn($table, 'provider')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('provider'));
            }
        }
    }
};
