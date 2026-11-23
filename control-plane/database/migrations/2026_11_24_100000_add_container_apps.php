<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Applications from a Dockerfile or a Docker image (docs/specs/templates.md, step 6). They are `sites` rows whose runtime is
 * `dockerfile` or `image`; `container` holds what is specific to them (Dockerfile path, port, host port, kept images, the
 * pinned digest). An image application has no repository and no deploy key, so those two columns stop being required:
 * NULL means "no git" and every git path (poller, webhooks, previews) already skips such a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->string('repository')->nullable()->change();
            $table->text('deploy_key_public')->nullable()->change();
            $table->json('container')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('container');
        });
    }
};
