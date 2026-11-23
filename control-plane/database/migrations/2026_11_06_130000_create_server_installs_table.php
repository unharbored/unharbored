<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One-time install links for servers the platform did not create.
        // Only a hash of the link's secret is kept.
        Schema::create('server_installs', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('node_id');
            $table->string('secret_hash')->unique();
            $table->foreignId('operation_id')->nullable();
            $table->timestamp('expires_at');
            $table->timestamp('fetched_at')->nullable();
            $table->string('fetched_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_installs');
    }
};
