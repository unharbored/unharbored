<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Servers a site runs on besides its primary (sites.node_id). The
        // primary runs migrations and the scheduler; every server serves.
        Schema::create('site_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->index();
            $table->string('node_id');
            $table->timestamps();
            $table->unique(['site_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_nodes');
    }
};
