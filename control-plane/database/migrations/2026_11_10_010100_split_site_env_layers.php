<?php

declare(strict_types=1);

use App\Lib\Sites\Sites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits each existing site's single .env into the injected and custom layers.
 * The effective .env is the same before and after, so no site needs a deploy.
 *
 * A site that fails here is left as it was: the layers are also created the
 * first time anything injects a value into it, so nothing depends on this
 * having reached every site.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('sites')->exists()) {
            return;
        }
        $sites = app(Sites::class);
        foreach (DB::table('sites')->get() as $site) {
            try {
                $sites->splitEnvLayers($site);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    public function down(): void
    {
        // The layers are read together as one .env, so there is nothing to undo.
    }
};
