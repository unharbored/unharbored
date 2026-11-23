<?php

declare(strict_types=1);

use App\Lib\Sites\Sites;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * An application's name, key, address and logging used to be written into the
 * user's own variables when it was made, as if they had typed them. They are
 * injected now. For an application that already has them: moved, only where
 * they are what would have been made, so what the server is given does not
 * change. Repeatable.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sites = app(Sites::class);
        foreach (DB::table('sites')->get() as $site) {
            try {
                $sites->moveGeneratedDefaults($site);
            } catch (Throwable) {
                // An application whose secrets cannot be read is left as it is.
            }
        }
    }

    public function down(): void {}
};
