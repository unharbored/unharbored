<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Scaling\ServerScaler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run every minute: each application the autoscaler manages gets one look at
 * its servers' CPU, and at most one server added or removed.
 */
final class ScaleServers extends Command
{
    protected $signature = 'sites:scale-servers';

    protected $description = 'Add or remove one server per autoscaling application, from its servers\' CPU';

    public function handle(ServerScaler $scaler): int
    {
        $failed = false;
        foreach (DB::table('sites')->whereNull('archived_at')->whereNotNull('autoscale_pool_id')->whereNotNull('current_release')->get() as $site) {
            if (! ServerScaler::manages($site)) {
                continue;
            }
            try {
                if (($did = $scaler->tick($site)) !== null) {
                    $this->line("{$site->name}: {$did}");
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$site->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
