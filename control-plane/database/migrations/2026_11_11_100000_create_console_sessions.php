<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Off until an organisation turns it on: a shell in the browser is a way in.
        Schema::table('organizations', function (Blueprint $table) {
            $table->boolean('console_enabled')->default(false);
        });

        // One row per console opened. Never holds what was typed or shown.
        Schema::create('console_sessions', function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('organization_id');
            $table->unsignedBigInteger('site_id')->nullable();
            $table->string('node_id');
            $table->string('as_user');
            $table->string('opened_by_id');
            $table->string('opened_by_name');
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->integer('exit_code')->nullable();
            $table->string('ended_because')->nullable();
            $table->index(['site_id', 'opened_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('console_sessions');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn('console_enabled');
        });
    }
};
