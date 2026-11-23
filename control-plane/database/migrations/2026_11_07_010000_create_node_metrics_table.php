<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per server per minute, kept two weeks: about 20,000 rows
        // per server. Bytes as reported; percentages are worked out on read.
        Schema::create('node_metrics', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id', 64);
            $table->string('node_id', 191);
            $table->timestamp('collected_at');
            $table->float('cpu');
            $table->float('load_1');
            $table->unsignedBigInteger('mem_total');
            $table->unsignedBigInteger('mem_available');
            $table->unsignedBigInteger('disk_total');
            $table->unsignedBigInteger('disk_free');
            $table->unsignedBigInteger('uptime');
            $table->index(['node_id', 'collected_at']);
            $table->index('collected_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_metrics');
    }
};
