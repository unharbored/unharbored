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
            // A preview is a site of its own, on the preview server, made
            // from its parent's settings and one branch.
            $table->unsignedBigInteger('parent_site_id')->nullable()->index();
            // Removed after this many days without a deploy; null keeps it.
            $table->unsignedSmallInteger('preview_idle_days')->nullable();
            // On a parent: run once on a preview's fresh database, and the
            // .env keys a preview must not get.
            $table->string('preview_seeder')->nullable();
            $table->json('preview_exclude')->nullable();
        });
        Schema::table('nodes', function (Blueprint $table) {
            // "preview" for the server previews go to; null for the rest.
            $table->string('role', 32)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['parent_site_id', 'preview_idle_days', 'preview_seeder', 'preview_exclude']);
        });
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('role');
        });
    }
};
