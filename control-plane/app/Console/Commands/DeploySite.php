<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use App\Lib\Sites\Sites;
use Illuminate\Console\Command;

/**
 * site:deploy — build and activate a new release, here and now.
 *
 * Runs as an operation like a console or push deploy, so it lands in the
 * same history, but inline rather than on the queue.
 */
final class DeploySite extends Command
{
    protected $signature = 'site:deploy {node} {name}';

    protected $description = 'Deploy the site branch as a new release';

    public function handle(Sites $sites, OperationRunner $runner, Operations $operations): int
    {
        $site = $sites->find((string) $this->argument('node'), (string) $this->argument('name'));
        if ($site === null) {
            $this->error(sprintf('No site %s on %s.', $this->argument('name'), $this->argument('node')));

            return self::FAILURE;
        }

        $started = microtime(true);
        $id = $runner->recordDeploy((int) $site->id, 'cli', 'artisan');
        $status = $runner->run($id, fn (string $line) => $this->line($line));
        $op = $operations->find($id);

        $this->newLine();
        if ($status !== 'succeeded') {
            $this->error((string) $op->error);

            return self::FAILURE;
        }

        $this->info(sprintf('Live: %s (%s) in %ds.', $op->release_id, substr((string) $op->commit, 0, 12), (int) (microtime(true) - $started)));
        $this->line(((bool) $site->tls ? 'https' : 'http')."://{$site->domain}/");

        return self::SUCCESS;
    }
}
