<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organizations', function (Blueprint $table) {
            // A stable public identifier rather than an auto-increment, since
            // it appears in audit chains, vault keys, resource tags and
            // certificate subjects. Those outlive rows.
            $table->string('id')->primary();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('cloud_credentials', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();

            // Only digitalocean today. Named rather than assumed so adding a
            // second provider is a row, not a migration.
            $table->string('provider')->default('digitalocean');

            // How the credential was obtained. A personal access token is
            // account-wide and cannot be scoped, which is why ADR 0001 and
            // PLAN.md call for OAuth before external tenants exist. Recording
            // the kind means the difference is visible rather than implied.
            $table->string('kind')->default('personal_access_token');

            // The secret itself lives in the vault, never here. This is the
            // name to look it up by.
            $table->string('vault_key');

            // Non-secret context: which DigitalOcean account this is, so an
            // operator can tell two credentials apart without decrypting
            // either.
            $table->string('account_email')->nullable();
            $table->string('account_uuid')->nullable();

            // Rate limit budgets are per DigitalOcean account, not per
            // organisation, because two organisations could in principle
            // connect the same account.
            $table->string('rate_limit_key')->nullable();

            $table->timestamp('verified_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            $table->unique(['organization_id', 'provider']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cloud_credentials');
        Schema::dropIfExists('organizations');
    }
};
