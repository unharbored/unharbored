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
            // The folder of the repository the application lives in, for a monorepo: "apps/web".
            // Empty means the top of the repository. See Sites::appDir.
            $table->string('root_dir', 200)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sites', function (Blueprint $table) {
            $table->dropColumn('root_dir');
        });
    }
};
