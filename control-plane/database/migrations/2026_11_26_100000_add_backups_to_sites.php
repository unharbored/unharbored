<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            // Automatic database backups (nightly, and before each deploy's migrations) are opt-in: off for a new application.
            $table->boolean('backups')->default(false);
        });
        // Applications that already have backups keep them.
        DB::table('sites')->whereNull('parent_site_id')->update(['backups' => true]);
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('backups'));
    }
};
