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
            // Set once a certificate has been issued on the node. The vhost
            // only references the certificate after that, because nginx
            // refuses to start with a certificate path that does not exist.
            $table->boolean('tls')->default(false);

            // queue:work processes under supervisor. Zero disables workers.
            $table->unsignedSmallInteger('queue_workers')->default(1);
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['tls', 'queue_workers']);
        });
    }
};
