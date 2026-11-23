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
            // fresh, clone or shared. On a parent: what its previews get
            // unless asked otherwise. On a preview: what it got.
            $table->string('preview_database', 16)->default('fresh');
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('preview_database');
        });
    }
};
