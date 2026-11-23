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
            // On a parent: pull requests into this branch get a preview;
            // null for none.
            $table->string('pr_previews_base')->nullable();
            // On a preview: the pull request it shows, and whether it was
            // made for it (and so goes when it closes) or was a branch
            // preview already.
            $table->unsignedInteger('pr_number')->nullable();
            $table->boolean('pr_created')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['pr_previews_base', 'pr_number', 'pr_created']);
        });
    }
};
