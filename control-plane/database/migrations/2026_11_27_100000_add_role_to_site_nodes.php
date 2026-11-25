<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 'web' serves the application and runs its processes (all there was until now); 'worker'
        // runs only the processes assigned to it, and gets no web server, certificate or balancer place.
        // The primary (sites.node_id) has no row here and is always web.
        Schema::table('site_nodes', function (Blueprint $table) {
            $table->string('role', 10)->default('web');
        });
    }

    public function down(): void
    {
        Schema::table('site_nodes', fn (Blueprint $table) => $table->dropColumn('role'));
    }
};
