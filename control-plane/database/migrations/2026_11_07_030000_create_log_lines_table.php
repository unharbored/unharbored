<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Lines the agents ship, already redacted on the server. Kept a week.
        Schema::create('log_lines', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id', 64);
            $table->string('node_id', 191);
            $table->string('source', 128);
            $table->timestamp('logged_at');
            $table->text('text');
            $table->index(['organization_id', 'logged_at']);
            $table->index(['node_id', 'source', 'logged_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('log_lines');
    }
};
