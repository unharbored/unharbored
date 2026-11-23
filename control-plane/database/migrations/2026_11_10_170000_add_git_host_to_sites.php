<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Code from any Git host: the SSH host key pinned for a repository that is not
 * on GitHub (GitHub's is built in), and the last commit seen on a public
 * repository we poll, since we cannot receive its webhooks.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $t) {
            $t->text('git_host_key')->nullable();
            $t->string('git_seen_sha', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $t) => $t->dropColumn(['git_host_key', 'git_seen_sha']));
    }
};
