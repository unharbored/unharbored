<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // HTTP basic auth in front of the whole application (staging, previews). Only a hash is kept.
            $table->string('basic_auth_user')->nullable();
            $table->string('basic_auth_hash')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['basic_auth_user', 'basic_auth_hash']));
    }
};
