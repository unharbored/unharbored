<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\SiteBackups;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * sites:backup — nightly: a backup operation for every deployed site that has backups switched on. Each
 * runs on its site's primary, after any deploy already queued there.
 * Previews are left out: their data is made to be thrown away.
 */
final class BackupSites extends Command
{
    protected $signature = 'sites:backup';

    protected $description = "Queue a nightly backup of every deployed site's database";

    public function handle(SiteBackups $backups, OperationRunner $runner): int
    {
        // Without a bucket, only a database on the site's own server is dumped, to that server's disk.
        $bucket = $backups->enabled();
        if (! $bucket) {
            $this->warn('No backup bucket (run backup:offsite): only databases on their own servers are dumped, to those servers.');
        }
        foreach (DB::table('sites')->whereNull('archived_at')->whereNotNull('current_release')->whereNull('parent_site_id')->where('backups', true)
            ->when(! $bucket, fn ($q) => $q->where('database_mode', 'like', 'local:%'))->orderBy('id')->get() as $site) {
            $this->line("{$site->name}: operation ".$runner->queueSiteBackup($site->id, 'nightly', 'schedule', 'schedule'));
        }

        return self::SUCCESS;
    }
}
