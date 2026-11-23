<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One data key per organisation. Secrets are encrypted with the data
        // key; the data key is encrypted with the master key. That split is
        // what makes a master key rotation cheap: re-wrap one row per
        // organisation instead of re-encrypting every secret.
        Schema::create('organization_data_keys', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();

            // Monotonic per organisation. Ciphertexts record the version they
            // were written with, so a data key rotation can roll forward
            // without a flag day.
            $table->unsignedInteger('version');

            $table->text('wrapped_key');

            // Which master key wrapped it. A master key rotation finds rows
            // by this column.
            $table->string('master_key_id');

            // Exactly one key per organisation accepts new writes. Older
            // versions stay readable until every ciphertext has moved.
            $table->boolean('is_primary')->default(true);

            $table->timestamps();

            $table->unique(['organization_id', 'version']);
            $table->index(['organization_id', 'is_primary']);
        });

        Schema::create('secrets', function (Blueprint $table) {
            $table->id();
            $table->string('organization_id')->index();

            // Scopes a secret within an organisation, e.g. "do.oauth" or
            // "env:production:app-1". Part of the authenticated context, so
            // renaming a row in the database breaks decryption rather than
            // silently handing the value to something else.
            $table->string('name');

            $table->unsignedInteger('key_version');
            $table->binary('nonce');
            $table->binary('ciphertext');

            // Free-form, not encrypted, and never secret. For things like
            // which DigitalOcean account a token belongs to.
            $table->json('metadata')->nullable();

            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'name']);
        });

        // MySQL's blob holds 64 KB; a stored certificate bundle or key file can be larger.
        if (DB::connection()->getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE secrets MODIFY ciphertext LONGBLOB NOT NULL');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('secrets');
        Schema::dropIfExists('organization_data_keys');
    }
};
