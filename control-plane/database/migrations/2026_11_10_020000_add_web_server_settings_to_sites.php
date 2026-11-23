<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A site's own nginx settings: how large an upload it accepts, how long a
 * request may take, and a snippet inside its server block. Null means the
 * platform default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->unsignedInteger('nginx_upload_mb')->nullable();
            $table->unsignedInteger('nginx_timeout_s')->nullable();
            $table->text('nginx_snippet')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['nginx_upload_mb', 'nginx_timeout_s', 'nginx_snippet']);
        });
    }
};
