<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A named copy of an application's database, made from a fresh dump: for
 * staging, testing a migration or a one-off. The password is in the vault.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_clones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('site_id')->constrained('sites')->cascadeOnDelete();
            $table->string('name');
            $table->string('engine');
            $table->boolean('schema_only')->default(false);
            $table->string('host')->nullable();
            $table->unsignedInteger('port')->nullable();
            $table->string('database');
            $table->string('username')->nullable();
            // A SQLite copy is a file under the site's shared/ folder.
            $table->string('path')->nullable();
            $table->timestamps();
            $table->unique(['site_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_clones');
    }
};
