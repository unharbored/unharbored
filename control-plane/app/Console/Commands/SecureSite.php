<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Dns\Lookup;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * site:https — issue a Let's Encrypt certificate and serve the site over TLS.
 */
final class SecureSite extends Command
{
    protected $signature = 'site:https {node} {name}';

    protected $description = 'Issue a certificate for the site domain and switch it to HTTPS';

    public function handle(Sites $sites, Deployer $deployer): int
    {
        $site = $sites->find((string) $this->argument('node'), (string) $this->argument('name'));
        if ($site === null) {
            $this->error(sprintf('No site %s on %s.', $this->argument('name'), $this->argument('node')));

            return self::FAILURE;
        }

        $resolved = Lookup::addresses($site->domain);
        $this->line(sprintf('%s resolves to %s.', $site->domain, $resolved === [] ? 'nothing' : implode(', ', $resolved)));

        try {
            $site = $deployer->secure($site);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->line('Let\'s Encrypt must reach the domain on port 80, so it has to resolve to this node first.');

            return self::FAILURE;
        }

        $this->info("https://{$site->domain}/ is live. APP_URL now uses https; redeploy to cache it.");

        return self::SUCCESS;
    }
}
