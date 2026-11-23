<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One GitHub App per organisation, created through GitHub's manifest
        // flow. The private key and webhook secret are in the vault; this is
        // the public half.
        Schema::create('github_apps', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->unique();
            $table->unsignedBigInteger('app_id');
            $table->string('slug');
            $table->string('owner');
            $table->string('html_url');
            $table->timestamps();
        });

        Schema::table('sites', function (Blueprint $table) {
            // How the node fetches code: 'deploy_key' (SSH, a key on GitHub)
            // or 'github_app' (HTTPS with a short-lived installation token).
            $table->string('git_source')->default('deploy_key');

            // Deploy when GitHub reports a push to the site's branch.
            $table->boolean('push_to_deploy')->default(false);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('github_apps');
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['git_source', 'push_to_deploy']);
        });
    }
};
