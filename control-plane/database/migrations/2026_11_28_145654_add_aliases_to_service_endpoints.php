<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_endpoints', function (Blueprint $table) {
            $table->text('aliases')->nullable(); // further names for the same port, comma separated; domain is the primary
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_endpoints', fn (Blueprint $table) => $table->dropColumn('aliases'));
    }
};
