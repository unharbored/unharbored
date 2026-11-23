<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // JSON: {address, name, joined_at} once the server has joined the organisation's tailnet.
        Schema::table('nodes', function (Blueprint $table) {
            $table->text('tailnet')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('tailnet'));
    }
};
