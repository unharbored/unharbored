<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Thresholds the organisation chose; Alerts::DEFAULTS fill the rest.
        Schema::table('organizations', function (Blueprint $table) {
            $table->json('alert_settings')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('alert_settings');
        });
    }
};
