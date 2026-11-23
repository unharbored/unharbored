<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_records', function (Blueprint $table) {
            $table->id();

            // Chains are per organisation, so one tenant can be verified
            // independently of the others and appends do not contend on a
            // single global head row.
            $table->string('organization_id')->index();

            // Position in that organisation's chain, from 1. A gap proves a
            // deletion.
            $table->unsignedBigInteger('sequence');

            // Who. Type distinguishes a person from the scheduler from an
            // agent, which matters when reading back "who destroyed this".
            $table->string('actor_type');
            $table->string('actor_id')->nullable();
            $table->string('actor_label')->nullable();

            // What, and to what.
            $table->string('action')->index();
            $table->string('resource_type')->nullable();
            $table->string('resource_id')->nullable();

            // Why. PLAN.md requires every DigitalOcean API call to be
            // attributable to an actor, an organisation and a reason.
            $table->text('reason')->nullable();

            $table->string('outcome');

            // Text, not json: the hash covers these exact bytes, and MySQL's json type
            // rewrites them (sorted keys, added spaces), which would break verification.
            $table->longText('metadata')->nullable();

            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();

            // Microsecond precision: the hash covers them, and MySQL's default would round them away.
            $table->timestamp('recorded_at', 6);

            // Tamper evidence. Each record commits to its predecessor, so
            // altering or removing one breaks every hash after it.
            $table->string('previous_hash', 64)->nullable();
            $table->string('hash', 64);

            $table->unique(['organization_id', 'sequence']);
            $table->index(['organization_id', 'recorded_at']);
            $table->index(['resource_type', 'resource_id']);
        });

        // One row per organisation holding the tip of its chain. Appends take
        // a row lock here, which serialises writers within an organisation
        // without blocking other tenants.
        Schema::create('audit_chain_heads', function (Blueprint $table) {
            $table->string('organization_id')->primary();
            $table->unsignedBigInteger('sequence')->default(0);
            $table->string('head_hash', 64)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_chain_heads');
        Schema::dropIfExists('audit_records');
    }
};
