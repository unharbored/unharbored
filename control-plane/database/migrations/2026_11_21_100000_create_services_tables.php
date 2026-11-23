<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Template services (docs/specs/templates.md section 4). A service is not an
 * application: no repository, no releases. Its domains are service_endpoints
 * rows (the spike chose them over repository-less sites rows, see the spec).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->unsignedBigInteger('project_id')->index();
            $table->string('node_id')->index();
            $table->string('template_id');
            $table->string('template_source');           // builtin, or the tier of a remote or local source
            $table->unsignedInteger('template_version');
            $table->string('name');                       // slug, one namespace with application names
            $table->string('runtime', 16);                // native | container
            $table->json('inputs');                       // never secrets
            $table->string('status', 16);                 // installing | running | stopped | failed | removing
            $table->string('data_path');
            $table->string('run_user', 32);               // the system user the unit runs as
            $table->text('error')->nullable();            // why it is failed
            $table->string('created_by');
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        Schema::create('service_endpoints', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('service_id')->index();
            $table->string('node_id')->index();
            $table->unsignedInteger('port');              // 127.0.0.1:port on the server
            $table->string('domain');
            $table->boolean('tls')->default(false);
            $table->timestamps();

            $table->unique(['service_id', 'port']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_endpoints');
        Schema::dropIfExists('services');
    }
};
