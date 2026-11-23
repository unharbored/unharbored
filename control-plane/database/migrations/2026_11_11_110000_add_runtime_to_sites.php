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
            // What the application is: php (Laravel, the default), static, node, go. See App\Lib\Sites\Runtimes.
            $table->string('runtime', 16)->default('php');
            // What the user changed from the runtime's defaults, as JSON.
            $table->json('runtime_config')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['runtime', 'runtime_config']);
        });
    }
};
