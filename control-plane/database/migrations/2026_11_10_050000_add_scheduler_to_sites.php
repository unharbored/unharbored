<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Laravel scheduler as a switch (on by default, as it always was), and the
 * last read of the tasks the application's code schedules.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->boolean('scheduler')->default(true);
            $table->text('schedule_tasks')->nullable();
            $table->timestamp('schedule_tasks_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn(['scheduler', 'schedule_tasks', 'schedule_tasks_at']);
        });
    }
};
