<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** The GitHub accounts, besides the app's owner, whose installations of an organisation's app are used. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('github_apps', fn (Blueprint $t) => $t->json('accounts')->nullable());
    }

    public function down(): void
    {
        Schema::table('github_apps', fn (Blueprint $t) => $t->dropColumn('accounts'));
    }
};
