<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per "connect a server over SSH": where to connect, and which vault
 * secret holds the login for the few minutes it is needed. The secret is
 * deleted when the install ends and by a sweep of anything older than an hour;
 * nothing here is ever the credential itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_adoptions', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->unsignedBigInteger('operation_id')->nullable();
            $table->string('node_id');
            $table->string('host');
            $table->unsignedSmallInteger('port')->default(22);
            $table->string('ssh_user')->default('root');
            $table->string('expected_fingerprint')->nullable();
            $table->string('fingerprint')->nullable();
            $table->string('vault_key')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ssh_adoptions');
    }
};
