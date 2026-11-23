<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use Illuminate\Console\Command;

/**
 * node:readdress — move a node's agent to the control plane's current name.
 *
 * For when the name agents use changes: the server certificate must already
 * be valid for both names, so the node never loses its way mid-move.
 */
final class ReaddressNode extends Command
{
    protected $signature = 'node:readdress {node} {from : The name the agent uses now} {--to= : Defaults to CONTROL_PLANE_SERVER_NAME}';

    protected $description = "Point a node's agent at a new control plane name";

    public function handle(OperationRunner $runner, Operations $operations): int
    {
        $to = (string) ($this->option('to') ?: config('control_plane.server_name'));
        $id = $runner->recordReaddress((string) $this->argument('node'), (string) $this->argument('from'), $to, 'cli', 'artisan');

        if ($runner->run($id, fn (string $line) => $this->line($line)) !== 'succeeded') {
            $this->error((string) $operations->find($id)->error);

            return self::FAILURE;
        }

        $this->info("{$this->argument('node')} now connects to {$to}.");

        return self::SUCCESS;
    }
}
