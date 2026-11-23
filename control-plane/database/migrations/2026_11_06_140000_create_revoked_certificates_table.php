<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Node certificates the gateway must refuse: removed servers. By
        // serial, so a server added again under an old name is a new one.
        Schema::create('revoked_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('node_id');
            $table->string('serial');
            $table->string('reason');
            $table->timestamp('revoked_at');
            $table->unique(['organization_id', 'serial']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revoked_certificates');
    }
};
