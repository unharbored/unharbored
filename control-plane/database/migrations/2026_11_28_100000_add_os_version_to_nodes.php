<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The Ubuntu release a server runs ('24.04', '26.04'), read when its stack is installed. Empty means 24.04, which is every server so far. */
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('os_version', 16)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('os_version');
        });
    }
};
