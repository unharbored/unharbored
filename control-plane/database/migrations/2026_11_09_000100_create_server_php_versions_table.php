<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The PHP branches cloud-manager has installed on each server beyond the
 * default one. A server can carry several at once, each with its own FPM
 * service and socket, so different applications on it can run different
 * versions.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_php_versions', function (Blueprint $table) {
            $table->id();
            $table->string('node_id')->index();
            $table->string('version');
            $table->timestamps();
            $table->unique(['node_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_php_versions');
    }
};
