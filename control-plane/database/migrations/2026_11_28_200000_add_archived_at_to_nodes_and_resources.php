<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An archived server (`nodes.archived_at` is its one source of truth; the provider-made `server` resource is not flagged)
 * or an archived database cluster (`resources.archived_at` on its `database_cluster` row) is no longer managed by
 * Unharbored but is left running.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });
        Schema::table('resources', function (Blueprint $table) {
            $table->timestamp('archived_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
        Schema::table('resources', function (Blueprint $table) {
            $table->dropColumn('archived_at');
        });
    }
};
