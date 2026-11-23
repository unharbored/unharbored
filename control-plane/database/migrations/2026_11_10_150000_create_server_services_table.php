<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Databases and caches installed on an application's own server, and how each
 * application uses them: database_mode is sqlite, local:pgsql, local:mysql,
 * cluster or none; cache_mode is database, local:valkey, cluster or none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_services', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('node_id');
            $table->string('service');
            $table->string('version')->nullable();
            $table->string('state')->default('installed');
            $table->timestamps();
            $table->unique(['node_id', 'service']);
        });
        Schema::table('sites', function (Blueprint $table) {
            $table->string('database_mode')->default('sqlite');
            $table->string('cache_mode')->default('database');
        });
        // Applications already on a cluster say so.
        \Illuminate\Support\Facades\DB::table('sites')->whereNotNull('database_cluster_id')->update(['database_mode' => 'cluster']);
        \Illuminate\Support\Facades\DB::table('sites')->whereNotNull('cache_cluster_id')->update(['cache_mode' => 'cluster']);
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn(['database_mode', 'cache_mode']));
        Schema::dropIfExists('server_services');
    }
};
