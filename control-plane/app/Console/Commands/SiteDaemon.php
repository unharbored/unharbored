<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Sites\Sites;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * site:daemon — add, replace, remove or list a site's long-running processes.
 */
final class SiteDaemon extends Command
{
    protected $signature = 'site:daemon
        {node}
        {name : Site name}
        {daemon? : Daemon name; omit to list}
        {daemon_command? : Command to run from the live release, e.g. "php artisan horizon"}
        {--processes=1 : How many copies to run}
        {--remove : Remove the daemon}';

    protected $description = 'Manage a site\'s supervisor daemons; changes apply on the next deploy';

    public function handle(Sites $sites): int
    {
        $site = $sites->find((string) $this->argument('node'), (string) $this->argument('name'));
        if ($site === null) {
            $this->error(sprintf('No site %s on %s.', $this->argument('name'), $this->argument('node')));

            return self::FAILURE;
        }

        $daemon = $this->argument('daemon');

        if ($daemon === null) {
            $rows = array_map(fn ($d) => [$d['name'], $d['processes'], $d['command']], Sites::daemons($site));
            $rows === [] ? $this->line('No daemons.') : $this->table(['daemon', 'processes', 'command'], $rows);

            return self::SUCCESS;
        }

        try {
            if ($this->option('remove')) {
                $sites->removeDaemon($site, (string) $daemon);
                $this->info("Removed {$daemon}.");
            } else {
                $command = (string) $this->argument('daemon_command');
                $sites->setDaemon($site, (string) $daemon, $command, (int) $this->option('processes'));
                $this->info("Set {$daemon}: {$command}");
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line("Deploy to apply: php artisan site:deploy {$site->node_id} {$site->name}");

        return self::SUCCESS;
    }
}
