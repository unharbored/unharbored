<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Sites\Sites;
use Illuminate\Console\Command;

/**
 * site:reverb — run Laravel Reverb for a site behind its own domain.
 */
final class SiteReverb extends Command
{
    protected $signature = 'site:reverb {node} {name} {--disable}';

    protected $description = 'Enable or disable Laravel Reverb for a site; applies on the next deploy';

    public function handle(Sites $sites): int
    {
        $site = $sites->find((string) $this->argument('node'), (string) $this->argument('name'));
        if ($site === null) {
            $this->error(sprintf('No site %s on %s.', $this->argument('name'), $this->argument('node')));

            return self::FAILURE;
        }

        if ($this->option('disable')) {
            $sites->disableReverb($site);
            $this->info('Reverb disabled; broadcasting set to log.');
        } else {
            $site = $sites->enableReverb($site);
            $scheme = (bool) $site->tls ? 'wss' : 'ws';
            $this->info(sprintf('Reverb enabled on 127.0.0.1:%d.', Sites::reverbPort($site)));
            $this->line("Browsers connect at {$scheme}://{$site->domain}/app/<REVERB_APP_KEY>.");
            $this->line('The app must be using laravel/reverb.');
        }

        $this->line("Deploy to apply: php artisan site:deploy {$site->node_id} {$site->name}");

        return self::SUCCESS;
    }
}
