<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Projects (docs/specs/projects.md): a named folder of applications inside an
 * organisation. Every organisation gets a Default project and every existing
 * application and preview is put in it, then sites.project_id becomes NOT NULL.
 * Safe to run twice: each step checks what is already done.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('projects')) {
            Schema::create('projects', function (Blueprint $table) {
                $table->id();
                $table->string('organization_id')->index();
                $table->string('name');
                $table->string('slug');
                $table->timestamps();
                $table->unique(['organization_id', 'slug']);
                $table->unique(['organization_id', 'name']);
            });
        }
        if (! Schema::hasColumn('sites', 'project_id')) {
            Schema::table('sites', function (Blueprint $table) {
                $table->unsignedBigInteger('project_id')->nullable()->index();
            });
        }

        // Never guess: an application with no organisation has nowhere to go.
        if (DB::table('sites')->whereNull('project_id')->where(fn ($q) => $q->whereNull('organization_id')->orWhere('organization_id', ''))->exists()) {
            throw new RuntimeException('An application has no organisation; fix it, then this runs.');
        }

        // Organisations that have a row, and any that only sites mention (the default one on a fresh install).
        $organizations = DB::table('organizations')->pluck('id')->merge(DB::table('sites')->distinct()->pluck('organization_id'))->unique();
        foreach ($organizations as $id) {
            DB::table('projects')->insertOrIgnore(['organization_id' => $id, 'name' => 'Default', 'slug' => 'default', 'created_at' => now(), 'updated_at' => now()]);
        }

        $default = fn () => DB::table('projects')->select('id')->whereColumn('projects.organization_id', 'sites.organization_id')->where('slug', 'default');
        // Applications first, then each preview takes its parent's project.
        DB::table('sites')->whereNull('project_id')->whereNull('parent_site_id')->update(['project_id' => $default()]);
        // A derived table: MySQL refuses to update a table from a subquery that reads it directly.
        DB::table('sites')->whereNull('project_id')->update(['project_id' => DB::query()->fromSub(DB::table('sites')->select('id', 'project_id'), 'parent')->select('parent.project_id')->whereColumn('parent.id', 'sites.parent_site_id')]);
        DB::table('sites')->whereNull('project_id')->update(['project_id' => $default()]);

        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedBigInteger('project_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropIndex(['project_id']);
            $table->dropColumn('project_id');
        });
        Schema::dropIfExists('projects');
    }
};
