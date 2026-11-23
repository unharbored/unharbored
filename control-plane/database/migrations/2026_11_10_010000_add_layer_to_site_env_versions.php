<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site's environment has two layers: the user's custom variables and the
 * values cloud-manager injects from the resources the site uses. Both keep a
 * history, and each version says which layer it changed. Only a custom version
 * holds a snapshot to restore; an injected one records key names only, since
 * the platform can regenerate its values.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('site_env_versions', function (Blueprint $table) {
            $table->string('layer')->default('custom');
        });
    }

    public function down(): void
    {
        Schema::table('site_env_versions', function (Blueprint $table) {
            $table->dropColumn('layer');
        });
    }
};
