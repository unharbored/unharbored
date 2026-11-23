<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Sites\WorkerScaling;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run every minute: queue workers follow each scaling site's queue. A site
 * that has never been deployed, or whose primary is unreachable, is skipped
 * until it can answer.
 */
final class ScaleWorkers extends Command
{
    protected $signature = 'sites:scale-workers';

    protected $description = 'Start or stop queue workers to match each site\'s queue';

    public function handle(WorkerScaling $scaling): int
    {
        $failed = false;
        foreach (DB::table('sites')->whereNotNull('queue_workers_max')->whereNotNull('current_release')->get() as $site) {
            try {
                if (($changed = $scaling->tick($site)) !== null) {
                    $this->line("{$site->name}: {$changed}");
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$site->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
