<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * An application's database and user are named after it, so two applications
 * with one name in an organisation would share and delete each other's data
 * (audit 2026-11-08, finding 7). The name is now unique per organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('sites')->select('organization_id', 'name')->groupBy('organization_id', 'name')->havingRaw('count(*) > 1')->get();
        if ($duplicates->isNotEmpty()) {
            // Never guess which one wins: a human renames one, then this runs.
            throw new RuntimeException('Duplicate application names in an organisation: '.$duplicates->map(fn ($d) => "{$d->organization_id}/{$d->name}")->implode(', '));
        }

        Schema::table('sites', function (Blueprint $table) {
            $table->unique(['organization_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropUnique(['organization_id', 'name']));
    }
};
