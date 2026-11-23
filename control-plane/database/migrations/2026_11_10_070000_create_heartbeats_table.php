<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Heartbeat monitoring of the Laravel scheduler (no job) or one scheduled job.
 * The token is the credential in the ping URL: found by its hash, and kept
 * encrypted because the cron line that pings it is rebuilt from it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('heartbeats', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->foreignId('scheduled_job_id')->nullable()->constrained('scheduled_jobs')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamp('last_ping_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('heartbeats');
    }
};
