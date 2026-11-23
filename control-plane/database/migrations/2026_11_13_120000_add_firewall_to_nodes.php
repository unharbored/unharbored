<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            // {"enabled": bool, "ssh": [addresses], "web": [addresses]}; null means never configured.
            $table->text('firewall')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', fn (Blueprint $table) => $table->dropColumn('firewall'));
    }
};
