<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Where the certificate comes from (only letsencrypt for now) and when the server says it ends.
            $table->string('cert_source')->default('letsencrypt');
            $table->timestamp('cert_expires_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['cert_source', 'cert_expires_at']));
    }
};
