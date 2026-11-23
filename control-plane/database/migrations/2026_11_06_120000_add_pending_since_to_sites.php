<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Set when a change that only a deploy applies is saved (.env,
            // workers, daemons, Reverb), cleared when a deploy succeeds. The
            // console shows it so a saved change is never mistaken for a
            // live one.
            $table->timestamp('pending_since')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('pending_since');
        });
    }
};
