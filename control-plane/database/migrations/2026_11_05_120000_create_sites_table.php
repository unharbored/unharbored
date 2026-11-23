<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sites', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('node_id')->index();

            // The directory under /home/deploy on the node and the key for
            // its secrets in the vault. Unique per node, since two sites
            // cannot share a directory.
            $table->string('name');
            $table->string('domain');

            // SSH form, git@github.com:owner/repo.git. The deploy key grants
            // read access to this repository and nothing else.
            $table->string('repository');
            $table->string('branch')->default('main');

            // The public half, kept so it can be shown again. The private half
            // lives in the vault only.
            $table->text('deploy_key_public');

            $table->string('current_release')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sites');
    }
};
