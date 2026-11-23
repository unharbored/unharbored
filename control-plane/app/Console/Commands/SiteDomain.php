<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Dns\Lookup;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;

/**
 * site:domain — move a site to a new domain, with a certificate if it had one.
 */
final class SiteDomain extends Command
{
    protected $signature = 'site:domain {node} {name} {domain} {--force : Skip the DNS check}';

    protected $description = 'Move a site to a new domain, issuing a certificate for it if the site uses HTTPS';

    public function handle(Sites $sites, Deployer $deployer): int
    {
        $site = $sites->find((string) $this->argument('node'), (string) $this->argument('name'));
        if ($site === null) {
            $this->error(sprintf('No site %s on %s.', $this->argument('name'), $this->argument('node')));

            return self::FAILURE;
        }

        $domain = strtolower((string) $this->argument('domain'));

        // The move stops the old name being served, so a typo or DNS that
        // has not propagated would take the site down. The old domain
        // resolves to this node, so the new one must resolve to the same.
        $current = Lookup::addresses($site->domain);
        $next = Lookup::addresses($domain);
        $this->line(sprintf('%s resolves to %s.', $domain, $next === [] ? 'nothing' : implode(', ', $next)));

        if (! $this->option('force') && ($next === [] || array_intersect($current, $next) === [])) {
            $this->error("{$domain} does not resolve to the same address as {$site->domain}. Point it at the node first, or pass --force.");

            return self::FAILURE;
        }

        try {
            $site = $deployer->moveDomain($site, $domain);
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('%s://%s/ is live. Deploy to cache the new APP_URL.', (bool) $site->tls ? 'https' : 'http', $domain));

        return self::SUCCESS;
    }
}
