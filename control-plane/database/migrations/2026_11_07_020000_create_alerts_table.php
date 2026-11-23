<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A condition that holds (a server offline, a disk nearly full) is
        // one open row until it clears; an event (a failed deploy) is a row
        // opened and resolved at once. Each is told once when it opens and
        // once when it clears.
        Schema::create('alerts', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id', 64);
            $table->string('key', 191);
            $table->string('severity', 16);
            $table->string('title');
            $table->text('detail')->nullable();
            $table->string('link')->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('resolved_at')->nullable();
            $table->index(['organization_id', 'key', 'resolved_at']);
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('alert_email')->nullable();
            // Failed operations above this id have been looked at.
            $table->unsignedBigInteger('alerts_seen_operation')->default(0);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('alerts');
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['alert_email', 'alerts_seen_operation']);
        });
    }
};
