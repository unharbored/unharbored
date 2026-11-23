<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Servers\NodeRemoval;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * node:remove — take a server out of the platform and revoke its certificate.
 */
final class RemoveNode extends Command
{
    protected $signature = 'node:remove {node}';

    protected $description = "Remove a server: uninstall its agent if reachable, revoke its certificate";

    public function handle(NodeRemoval $removal): int
    {
        try {
            $r = $removal->remove((string) $this->argument('node'), 'artisan');
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('Removed %s. Agent %s; certificate %s; gateway %s.', $this->argument('node'),
            $r['uninstalled'] ? 'uninstalled' : 'not uninstalled (unreachable, or the uninstall failed: remove it by hand if the machine lives on)',
            $r['revoked'] ? 'revoked' : 'unknown, nothing to revoke',
            $r['synced'] ? 'updated' : 'not reachable, will apply at its next sync'));
        if ($r['droplet']) {
            $this->warn("Droplet {$r['droplet']} still exists and is still billed. Destroy it in DigitalOcean if you are done with it.");
        }

        return self::SUCCESS;
    }
}
