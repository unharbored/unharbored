<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Containers (docs/specs/templates.md section 5, step 4). A server is "prepared" once Docker Engine is
 * installed and configured; the last read of what runs there is kept on the node so a page load never
 * runs a slow command. A container service's endpoint keeps the port inside the container beside the
 * host port it was allocated.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->timestamp('containers_ready_at')->nullable();
            $table->string('docker_version', 40)->nullable();
            $table->text('containers_info')->nullable();      // json: the last read of containers and disk use
            $table->timestamp('containers_read_at')->nullable();
        });
        Schema::table('service_endpoints', function (Blueprint $table) {
            $table->unsignedInteger('container_port')->nullable(); // set for a container service; port is then the host port on 127.0.0.1
        });
        Schema::table('services', function (Blueprint $table) {
            $table->string('image', 300)->nullable();          // the digest-pinned reference a container service was installed from
        });
    }

    public function down(): void
    {
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn('image'));
        Schema::table('service_endpoints', fn (Blueprint $t) => $t->dropColumn('container_port'));
        Schema::table('nodes', fn (Blueprint $t) => $t->dropColumn(['containers_ready_at', 'docker_version', 'containers_info', 'containers_read_at']));
    }
};
