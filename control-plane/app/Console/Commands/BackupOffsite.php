<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Cloud\Spaces;
use Illuminate\Console\Command;

/**
 * backup:offsite — once: a private Spaces bucket for the nightly snapshots,
 * in another region from this server so one outage cannot take both.
 */
final class BackupOffsite extends Command
{
    protected $signature = 'backup:offsite {organization : Whose DigitalOcean account holds the bucket} {--region=sfo3 : Spaces region}';

    protected $description = 'Create the bucket the nightly database snapshots are copied to';

    public function handle(Spaces $spaces): int
    {
        foreach ($spaces->createForBackups((string) $this->argument('organization'), (string) $this->option('region')) as $line) {
            $this->line($line);
        }
        $this->info('The next backup:database run copies its snapshot there.');

        return self::SUCCESS;
    }
}
