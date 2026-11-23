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
            // Long-running processes under supervisor, beyond queue workers:
            // a list of {name, command, processes}. Commands run as the
            // deploy user from the live release.
            $table->json('daemons')->nullable();

            // Laravel Reverb: a daemon on a per-site loopback port, with
            // nginx proxying /app and /apps to it.
            $table->boolean('reverb')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['daemons', 'reverb']);
        });
    }
};
