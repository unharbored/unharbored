<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // A resources row of type autoscale_pool: Droplets DigitalOcean
            // adds and removes, serving the site alongside its fixed servers.
            $table->unsignedBigInteger('autoscale_pool_id')->nullable();
        });
        Schema::table('enrollment_grants', function (Blueprint $table) {
            // A pool's grant is used once per member Droplet, not once.
            $table->unsignedBigInteger('autoscale_pool_id')->nullable()->index();
        });
        Schema::table('site_nodes', function (Blueprint $table) {
            // Set for a server the pool created, which leaves when DigitalOcean
            // removes it rather than when someone presses Remove.
            $table->unsignedBigInteger('autoscale_pool_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('autoscale_pool_id'));
        Schema::table('enrollment_grants', function (Blueprint $table) {
            $table->dropIndex(['autoscale_pool_id']);
            $table->dropColumn('autoscale_pool_id');
        });
        Schema::table('site_nodes', fn (Blueprint $table) => $table->dropColumn('autoscale_pool_id'));
    }
};
