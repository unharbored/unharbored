<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Every long action, whoever started it: a record with stages and a
        // log, run on the queue. The console reads these; nothing it shows
        // depends on the browser that asked for the work staying open.
        Schema::create('operations', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();
            $table->string('kind');                       // deploy, rollback, https, domain, stack, agent_upgrade
            $table->foreignId('site_id')->nullable()->index();
            $table->string('node_id')->nullable()->index();
            $table->string('title');
            $table->string('trigger');                    // console, push, cli
            $table->string('actor');                      // who: an email, a GitHub login, a command
            $table->string('status')->default('queued');  // queued, running, succeeded, failed
            $table->json('stages');                       // [{name, status, started_at, finished_at}]
            // MySQL refuses a plain default on a text column; an expression default is valid on both.
            $table->longText('log')->default(new Expression("('')"));
            $table->text('error')->nullable();
            $table->string('release_id')->nullable();
            $table->string('commit')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('operations');
    }
};
