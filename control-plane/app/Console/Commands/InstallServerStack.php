<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Gateway\Exceptions\CommandOutcomeUnknown;
use App\Lib\Gateway\Exceptions\GatewayUnavailable;
use App\Lib\Gateway\Exceptions\NodeNotConnected;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Servers\PhpSettings;
use App\Lib\Servers\ServerStack;
use Illuminate\Console\Command;

/**
 * Installs the base stack on a connected node. Safe to run again: steps that
 * are already in place report noop and change nothing.
 */
final class InstallServerStack extends Command
{
    protected $signature = 'server:install-stack
        {organization : Organisation id}
        {node : Node id, e.g. web-1.org-1}
        {--dry-run : Report what would change without changing it}';

    protected $description = 'Install nginx, PHP-FPM, supervisor, git and Composer on a node';

    /** Package installs on a fresh Droplet take a few minutes. */
    private const TIMEOUT_SECONDS = 900;

    public function handle(GatewayClient $gateway, ServerStack $stack): int
    {
        $organizationId = (string) $this->argument('organization');
        $nodeId = (string) $this->argument('node');
        $dryRun = (bool) $this->option('dry-run');

        $this->line(sprintf('%s the server stack on %s ...', $dryRun ? 'Planning' : 'Installing', $nodeId));

        try {
            $result = $gateway->run(
                organizationId: $organizationId,
                nodeId: $nodeId,
                kind: 'apply_recipe',
                args: ['spec' => $stack->spec(PhpSettings::for($nodeId, ServerStack::defaultPhpFor($nodeId)), null, ServerStack::osOf($nodeId)), 'dry_run' => $dryRun],
                timeoutSeconds: self::TIMEOUT_SECONDS,
                waitSeconds: self::TIMEOUT_SECONDS + 30,
                reason: $dryRun ? 'Plan server stack' : 'Install server stack',
            );
        } catch (NodeNotConnected|CommandOutcomeUnknown|GatewayUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if (($result['status'] ?? null) !== 'succeeded') {
            $this->error(sprintf('The recipe %s: %s', $result['status'] ?? 'failed', $result['error'] ?? 'no detail'));

            return self::FAILURE;
        }

        $steps = $result['detail']['run']['steps'] ?? [];
        $changed = 0;

        foreach ($steps as $step) {
            $action = (string) ($step['action'] ?? '?');
            if ($action !== 'noop') {
                $changed++;
            }
            $this->line(sprintf('  %-7s %s', $action, $step['description'] ?? $step['id'] ?? ''));
        }

        $this->newLine();
        $this->info(sprintf('%d step(s), %d changed.', count($steps), $changed));

        return self::SUCCESS;
    }
}
