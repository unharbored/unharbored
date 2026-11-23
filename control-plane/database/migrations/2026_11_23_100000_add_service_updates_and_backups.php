<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Updates and backups for template services (docs/specs/templates.md section 5, step 5).
 *
 * services.installed is a summary of the definition the service runs (publisher, download host or image
 * repository, checksum or digest, extra ports, acknowledgement, input keys, address ports, a hash of the
 * environment, health, whether a backup stops it), so an update can say what changes without the old
 * definition, which the source no longer serves. services.previous lists the releases or images kept for
 * putting back, newest first. service_backups is one row per backup: listing them from the server would
 * need a round trip, and the bucket may not be configured.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('services', function (Blueprint $table) {
            $table->json('installed')->nullable();
            $table->json('previous')->nullable();
        });

        Schema::create('service_backups', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->unsignedBigInteger('service_id')->index();
            $table->string('name');                       // 20261115T031700Z-manual: the archive is {name}.tar.gz
            $table->string('kind', 16);                   // manual | scheduled | pre_update | pre_restore
            $table->unsignedBigInteger('bytes')->default(0);
            $table->string('location', 16);               // server | server+spaces
            $table->string('object')->nullable();         // the bucket key when it was uploaded
            $table->string('created_by');
            $table->timestamps();

            $table->unique(['service_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_backups');
        Schema::table('services', fn (Blueprint $t) => $t->dropColumn(['installed', 'previous']));
    }
};
