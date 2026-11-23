<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // A PHP application's own build commands, one per line (such as "npm ci" then "npm run build"), and the Node version they run under.
            $table->text('build_script')->nullable();
            $table->string('node_version', 20)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn(['build_script', 'node_version']));
    }
};
