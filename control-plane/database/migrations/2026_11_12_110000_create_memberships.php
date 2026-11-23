<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('organization_id')->index();
            $table->string('role', 16);
            $table->timestamps();
            $table->unique(['user_id', 'organization_id']);
        });

        Schema::table('users', function (Blueprint $table) {
            // Set only from the command line, never through the web.
            $table->boolean('is_platform_admin')->default(false);
        });

        // Everyone who can sign in today ran the one organisation.
        $now = now();
        foreach (DB::table('users')->pluck('id') as $id) {
            DB::table('memberships')->insert([
                'user_id' => $id, 'organization_id' => 'org-1', 'role' => 'owner',
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('is_platform_admin'));
        Schema::dropIfExists('memberships');
    }
};
