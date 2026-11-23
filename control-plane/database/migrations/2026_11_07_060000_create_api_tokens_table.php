<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Only a SHA-256 of each token is kept: shown once, never stored.
        Schema::create('api_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id', 64);
            $table->string('name');
            $table->char('hash', 64)->unique();
            $table->string('prefix', 12);
            $table->json('scopes');
            $table->string('created_by');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_tokens');
    }
};
