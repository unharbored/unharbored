<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The SQL an application's database clones start with (conversion queries): optional, editable per clone. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('sites', 'clone_conversion')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->text('clone_conversion')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('clone_conversion');
        });
    }
};
