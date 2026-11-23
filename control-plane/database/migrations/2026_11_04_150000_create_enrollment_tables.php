<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('enrollment_grants', function (Blueprint $table) {
            $table->id();

            // Only the hash is stored. The plaintext token exists in
            // cloud-init user_data and in the agent's memory, and nowhere
            // else, so a leak of this table is not replayable.
            $table->string('token_hash', 64)->unique();

            // The identity this token entitles its bearer to become, decided
            // before the node exists. A node cannot choose its own name.
            $table->string('node_id');
            $table->string('organization_id')->index();

            $table->timestamp('expires_at');

            // Set when redeemed. The row is kept rather than deleted so a
            // replay reads as "already used" instead of "unknown", which is
            // the difference between a retry and someone guessing tokens.
            $table->timestamp('consumed_at')->nullable();

            // What enrolled, for the audit trail. None of it influences the
            // identity granted.
            $table->string('consumed_by_hostname')->nullable();
            $table->string('consumed_by_ip', 45)->nullable();

            $table->timestamps();

            $table->index(['organization_id', 'consumed_at']);
        });

        Schema::create('nodes', function (Blueprint $table) {
            $table->id();
            $table->string('node_id')->unique();
            $table->string('organization_id')->index();

            $table->text('certificate_pem')->nullable();
            $table->timestamp('certificate_expires_at')->nullable();

            $table->string('agent_version')->nullable();
            $table->string('hostname')->nullable();
            $table->string('os')->nullable();
            $table->string('arch')->nullable();

            $table->timestamp('enrolled_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();
        });

        // One CA per organisation, so a breach is contained to one tenant.
        // The private key is held in the vault, not here.
        Schema::create('organization_certificate_authorities', function (Blueprint $table) {
            $table->string('organization_id')->primary();
            $table->text('certificate_pem');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_certificate_authorities');
        Schema::dropIfExists('nodes');
        Schema::dropIfExists('enrollment_grants');
    }
};
