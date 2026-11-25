<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which servers run the built-in queue workers: 'all' (as it always was), 'web' or 'worker'.
        Schema::table('sites', function (Blueprint $table) {
            $table->string('workers_on', 10)->default('all');
        });
    }

    public function down(): void
    {
        Schema::table('sites', fn (Blueprint $table) => $table->dropColumn('workers_on'));
    }
};
