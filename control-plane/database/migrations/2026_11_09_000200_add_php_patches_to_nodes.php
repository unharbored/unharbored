<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The full PHP version of each branch on a server, such as {"8.4": "8.4.16"},
 * read from the server after the stack or a PHP branch is installed or
 * updated. Null until one of those has run since this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->text('php_patches')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('php_patches');
        });
    }
};
