<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the Account page shows about a provider key without the key: its first
 * characters and last four, and the expiry its owner entered (the providers do
 * not report one). DNS keys get a table of their own: they never create
 * resources and must never be offered where a cloud provider is chosen. Their
 * key and secret stay in the vault.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cloud_credentials', function (Blueprint $table) {
            $table->string('key_hint')->nullable();
            $table->date('expires_at')->nullable();
        });

        Schema::create('dns_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('provider');
            $table->string('key_hint')->nullable();
            $table->date('expires_at')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dns_credentials');
        Schema::table('cloud_credentials', function (Blueprint $table) {
            $table->dropColumn(['key_hint', 'expires_at']);
        });
    }
};
