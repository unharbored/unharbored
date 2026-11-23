<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The organisation's SSH public keys, and which servers each is installed on,
 * for the login user every server gets. Public keys only: cloud-manager never
 * holds a private one, and never uses SSH to command a server.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('name');
            $table->text('public_key');
            $table->string('type');
            $table->string('fingerprint');
            $table->boolean('install_on_new_servers')->default(false);
            $table->string('created_by');
            $table->timestamps();
            $table->unique(['organization_id', 'fingerprint']);
        });

        // One row per key a server should have. pending: asked for, not yet
        // written; installed: written; removing: asked to go, not yet removed.
        Schema::create('server_ssh_keys', function (Blueprint $table) {
            $table->id();
            $table->string('node_id')->index();
            $table->foreignId('ssh_key_id')->constrained('ssh_keys')->cascadeOnDelete();
            $table->string('state')->default('pending');
            $table->timestamps();
            $table->unique(['node_id', 'ssh_key_id']);
        });

        // The login user's name on each server, so changing the default later
        // never strands one built with another name.
        Schema::table('nodes', function (Blueprint $table) {
            $table->string('admin_user')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table) {
            $table->dropColumn('admin_user');
        });
        Schema::dropIfExists('server_ssh_keys');
        Schema::dropIfExists('ssh_keys');
    }
};
