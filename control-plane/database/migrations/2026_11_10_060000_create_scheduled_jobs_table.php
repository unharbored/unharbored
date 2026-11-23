<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An application's own scheduled jobs: what its Laravel schedule does not
 * cover. Rebuilt whole into the site's cron file whenever one changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->text('command');
            // The form's choice, and the cron expression it comes to.
            $table->string('frequency');
            $table->string('at', 5)->nullable();
            $table->string('cron');
            $table->boolean('everywhere')->default(false);
            $table->boolean('paused')->default(false);
            // pending: saved, not yet written to the servers.
            $table->string('state')->default('pending');
            $table->text('error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_jobs');
    }
};
