<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Services\ServiceBackups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * services:backup — nightly: a backup operation for every running or stopped template service whose backup setting is not
 * "none". "snapshot" keeps the copy on the server, "spaces" also uploads it to the backup bucket. Each backup prunes that
 * service's backups older than 14 days (the same retention as sites). Services with an operation running are skipped
 * until tomorrow.
 */
final class BackupServices extends Command
{
    protected $signature = 'services:backup';

    protected $description = "Queue a nightly backup of every template service's data";

    public function handle(ServiceBackups $backups, OperationRunner $runner): int
    {
        $due = $backups->nightly();
        foreach ($due as $s) {
            $this->line("{$s->name}: operation ".$runner->queueTemplateServiceBackup((int) $s->id, 'scheduled', 'schedule', 'schedule'));
        }
        $skipped = DB::table('services')->whereIn('status', ['running', 'stopped'])->count() - count($due);
        $skipped > 0 && $this->line("{$skipped} left out (backups off, or an operation running).");

        return self::SUCCESS;
    }
}
