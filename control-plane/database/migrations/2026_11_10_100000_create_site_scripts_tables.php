<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An application's server setup script, every version kept, and which version
 * has run on which server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_scripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->text('content');
            $table->string('actor');
            $table->timestamp('created_at')->nullable();
            $table->unique(['site_id', 'version']);
        });

        Schema::create('site_script_runs', function (Blueprint $table) {
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('node_id');
            $table->unsignedInteger('version');
            $table->timestamp('ran_at')->nullable();
            $table->primary(['site_id', 'node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_script_runs');
        Schema::dropIfExists('site_scripts');
    }
};
