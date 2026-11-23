<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Set when the keys for a server were picked when it was created, so the
 * organisation's defaults are not added on top of a choice, even an empty one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->boolean('ssh_keys_chosen')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('ssh_keys_chosen');
        });
    }
};
