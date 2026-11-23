<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // This table exists so a deploy has a migration to run. If migrations
        // are skipped the status page errors loudly rather than quietly
        // serving stale behaviour.
        Schema::create('visits', function (Blueprint $table) {
            $table->id();
            $table->string('path');
            $table->string('release')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visits');
    }
};
