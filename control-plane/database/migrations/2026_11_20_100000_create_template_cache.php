<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Template files fetched from a remote index, so the catalogue works when the source is down. */
    public function up(): void
    {
        Schema::create('template_cache', function (Blueprint $table) {
            $table->id();
            $table->string('source');
            $table->string('template_id');
            $table->unsignedInteger('version');
            $table->string('sha256', 64);
            $table->string('publisher');
            $table->text('content');
            $table->timestamp('fetched_at');
            $table->unique(['source', 'template_id', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('template_cache');
    }
};
