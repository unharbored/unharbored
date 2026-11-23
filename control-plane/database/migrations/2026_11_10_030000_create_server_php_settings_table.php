<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * php.ini settings per server and PHP branch, since each branch has its own
 * FPM pool and its own settings on a server. A branch with no row runs on the
 * platform's defaults.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_php_settings', function (Blueprint $table) {
            $table->id();
            $table->string('node_id')->index();
            $table->string('version');
            $table->string('upload_max_filesize');
            $table->string('post_max_size');
            $table->string('memory_limit');
            $table->unsignedInteger('max_execution_time');
            $table->unsignedInteger('pm_max_children');
            // Directives of the user's own, written as they are.
            $table->text('extra')->nullable();
            $table->timestamps();
            $table->unique(['node_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_php_settings');
    }
};
