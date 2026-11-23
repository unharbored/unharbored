<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Invitations to join the organisation. Only a hash of the link's
        // secret is kept, so the database cannot be used to sign anyone up.
        Schema::create('invitations', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('email');
            $table->string('token_hash')->unique();
            $table->string('invited_by');
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invitations');
    }
};
