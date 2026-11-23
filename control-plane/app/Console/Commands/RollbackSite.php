<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use App\Lib\Sites\Sites;
use Illuminate\Console\Command;

/**
 * site:rollback — point current back at the previous release, and move
 * workers and PHP-FPM with it. Recorded as an operation.
 */
final class RollbackSite extends Command
{
    protected $signature = 'site:rollback {node} {name}';

    protected $description = 'Activate the release before the current one';

    public function handle(Sites $sites, OperationRunner $runner, Operations $operations): int
    {
        $site = $sites->find((string) $this->argument('node'), (string) $this->argument('name'));
        if ($site === null) {
            $this->error(sprintf('No site %s on %s.', $this->argument('name'), $this->argument('node')));

            return self::FAILURE;
        }

        $id = $runner->recordRollback((int) $site->id, 'cli', 'artisan');
        if ($runner->run($id, fn (string $line) => $this->line($line)) !== 'succeeded') {
            $this->error('Rollback failed: '.$operations->find($id)->error);

            return self::FAILURE;
        }

        $this->info('Live: '.$operations->find($id)->release_id);

        return self::SUCCESS;
    }
}
