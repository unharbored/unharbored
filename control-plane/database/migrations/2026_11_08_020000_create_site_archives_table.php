<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An application deleted with its data kept. The row outlives the site so
 * whoever cleans up later can find the files, database and bucket, and the
 * vault still holds its .env and deploy key under the same secret names.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('site_archives', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->unsignedBigInteger('original_site_id');
            $table->string('name');
            $table->string('domain');
            $table->string('node_id');
            $table->json('servers');
            $table->string('directory');
            $table->unsignedBigInteger('database_cluster_id')->nullable();
            $table->string('database_name')->nullable();
            $table->unsignedBigInteger('cache_cluster_id')->nullable();
            $table->unsignedBigInteger('bucket_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('site_archives');
    }
};
