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
            // The PHP branch the application runs on, e.g. "8.4". Its own
            // servers may run other branches for other applications.
            $table->string('php_version')->default('8.4')->after('branch');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('php_version');
        });
    }
};
