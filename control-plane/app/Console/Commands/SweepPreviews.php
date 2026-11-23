<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Previews;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** previews:sweep — daily: previews idle past their days are deleted. */
final class SweepPreviews extends Command
{
    protected $signature = 'previews:sweep';

    protected $description = 'Delete previews with no deploy within the days they keep for';

    public function handle(Previews $previews, OperationRunner $runner): int
    {
        foreach ($previews->idle() as $preview) {
            $busy = DB::table('operations')->where('site_id', $preview->id)->where('kind', 'preview_destroy')->whereIn('status', ['queued', 'running'])->exists();
            if (! $busy) {
                $this->line("{$preview->name}: idle {$preview->preview_idle_days} days, operation ".$runner->queuePreviewDestroy($preview->id, 'schedule', 'schedule'));
            }
        }

        return self::SUCCESS;
    }
}
