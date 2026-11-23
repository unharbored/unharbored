<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One row per saved .env. The contents are in the vault, under a
        // name per version; here only what changed, by key name, and why.
        Schema::create('site_env_versions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id');
            $table->unsignedInteger('version');
            $table->string('reason');
            $table->json('changes');
            $table->timestamp('created_at');
            $table->unique(['site_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_env_versions');
    }
};
