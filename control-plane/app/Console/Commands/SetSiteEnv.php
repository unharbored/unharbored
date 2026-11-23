<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Sites\Sites;
use Illuminate\Console\Command;

/**
 * site:env — replace a site's .env from a file. Takes effect on next deploy.
 */
final class SetSiteEnv extends Command
{
    protected $signature = 'site:env {node} {name} {file : Path to the new .env} {--show : Print the current .env instead}';

    protected $description = "Replace a site's custom variables; they reach the node on the next deploy";

    public function handle(Sites $sites): int
    {
        $site = $sites->find((string) $this->argument('node'), (string) $this->argument('name'));
        if ($site === null) {
            $this->error(sprintf('No site %s on %s.', $this->argument('name'), $this->argument('node')));

            return self::FAILURE;
        }

        if ($this->option('show')) {
            // The custom variables: what a file given here replaces. The injected
            // names are listed as comments, since a copy of them in the file would
            // become an override.
            $this->line($sites->customEnv($site));
            foreach ($sites->envLayers($site)['injected'] as $row) {
                $this->line("# injected by Unharbored, not part of this file: {$row['key']} ({$row['source']})");
            }

            return self::SUCCESS;
        }

        $file = (string) $this->argument('file');
        if (! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }

        $sites->setEnv($site, (string) file_get_contents($file));
        $this->info('Stored. Deploy to apply it.');

        return self::SUCCESS;
    }
}
