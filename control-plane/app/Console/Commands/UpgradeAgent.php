<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Servers\AgentUpgrade;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * node:upgrade-agent — put the agent build the control plane serves onto a
 * running node.
 */
final class UpgradeAgent extends Command
{
    protected $signature = 'node:upgrade-agent {node : Node id, e.g. web-1.org-1}';

    protected $description = 'Replace the agent on a running node with the build the control plane serves';

    public function handle(AgentUpgrade $upgrade): int
    {
        $node = DB::table('nodes')->where('node_id', (string) $this->argument('node'))->first();
        if ($node === null) {
            $this->error("No node {$this->argument('node')}.");

            return self::FAILURE;
        }

        $this->line(sprintf('%s runs agent %s.', $node->node_id, $node->agent_version ?? 'unknown'));

        try {
            $version = $upgrade->upgrade($node, fn (string $line) => $this->line("  {$line}"));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$node->node_id} reconnected on agent {$version}.");

        return self::SUCCESS;
    }
}
