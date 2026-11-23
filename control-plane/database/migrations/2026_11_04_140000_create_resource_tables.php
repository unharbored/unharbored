<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();

            // droplet, load_balancer, database, firewall, vpc, bucket.
            $table->string('type');

            // Our name for it, unique within the organisation so a retry
            // cannot create a second one.
            $table->string('slug');

            // The provider's identifier. Written as soon as it is known,
            // which is the single most important column in this table: a row
            // with an external id is a resource we can find and delete, and a
            // row without one may be a resource the customer is paying for
            // that we cannot see.
            $table->string('external_id')->nullable()->index();

            $table->string('state');

            // What we are trying to reach. A reconciler compares the two.
            $table->string('desired_state');

            // Sent to the provider so a retry after a timeout cannot
            // provision twice.
            $table->string('idempotency_key')->nullable();

            $table->json('attributes')->nullable();

            // Why the last attempt failed, and how many times we have tried.
            $table->text('last_error')->nullable();
            $table->unsignedInteger('attempts')->default(0);

            // When the current transient state was entered. A resource that
            // has been provisioning for an hour is stuck, and nothing else
            // in the row says so.
            $table->timestamp('state_entered_at')->nullable();

            // Optimistic concurrency. Two workers reconciling the same
            // resource must not both move it.
            $table->unsignedBigInteger('version')->default(1);

            $table->timestamps();

            $table->unique(['organization_id', 'type', 'slug']);
            $table->index(['state', 'state_entered_at']);
            $table->index(['organization_id', 'state']);
        });

        // Every state change, kept forever. This is what makes a failed
        // provision diagnosable after the fact rather than a mystery.
        Schema::create('resource_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('resource_id')->constrained('resources')->cascadeOnDelete();
            $table->string('from_state')->nullable();
            $table->string('to_state');
            $table->string('reason')->nullable();
            $table->text('error')->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');

            $table->index(['resource_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('resource_transitions');
        Schema::dropIfExists('resources');
    }
};
