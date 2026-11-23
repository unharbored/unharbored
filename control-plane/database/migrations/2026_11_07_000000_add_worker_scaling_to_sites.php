<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Workers per server scale between these on queue depth; null
            // max means a fixed queue_workers. queue_workers is then the
            // number running now.
            $table->unsignedSmallInteger('queue_workers_min')->nullable();
            $table->unsignedSmallInteger('queue_workers_max')->nullable();
            // When the queue was last seen empty and has stayed so.
            $table->timestamp('queue_idle_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['queue_workers_min', 'queue_workers_max', 'queue_idle_since']);
        });
    }
};
