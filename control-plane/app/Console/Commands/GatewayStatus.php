<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Gateway\Exceptions\GatewayUnavailable;
use App\Lib\Gateway\GatewayClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Shows which nodes the gateway is holding, next to what we think exists.
 *
 * The interesting column is the difference. A node the control plane
 * provisioned but the gateway has never seen either never finished booting or
 * cannot reach us, and nothing else in the system would say so.
 */
final class GatewayStatus extends Command
{
    protected $signature = 'gateway:status {organization? : Limit to one organisation}';

    protected $description = 'Show gateway health and which nodes are connected';

    public function handle(GatewayClient $gateway): int
    {
        $health = $gateway->health();

        if (! $health['reachable']) {
            $this->error('The gateway is not reachable. Every node is unmanaged until it returns.');
            $this->line('Nodes keep serving traffic; they just cannot be commanded.');

            return self::FAILURE;
        }

        $body = $health['body'];

        $this->line(sprintf(
            'gateway %s, %d organisation(s), %d node(s) connected',
            $body['version'] ?? 'unknown',
            $body['organisations'] ?? 0,
            $body['connected_nodes'] ?? 0,
        ));

        if (($body['organisations'] ?? 0) === 0) {
            $this->warn('The gateway knows no organisations, so it is refusing every node.');
            $this->line('Run gateway:sync-organisations.');
        }

        $organizationId = $this->argument('organization');

        try {
            $connected = collect($gateway->connectedNodes($organizationId !== null ? (string) $organizationId : null))
                ->keyBy('node_id');
        } catch (GatewayUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $query = DB::table('nodes');
        if ($organizationId !== null) {
            $query->where('organization_id', (string) $organizationId);
        }

        $known = $query->orderBy('node_id')->get();

        if ($known->isEmpty()) {
            $this->info('No nodes are recorded.');

            return self::SUCCESS;
        }

        $missing = 0;

        $this->newLine();
        foreach ($known as $node) {
            $isConnected = $connected->has($node->node_id);

            if ($isConnected) {
                $this->line(sprintf('  <info>connected</info>  %s', $node->node_id));
            } else {
                $missing++;
                $this->line(sprintf(
                    '  <comment>absent</comment>     %s  last seen %s',
                    $node->node_id,
                    $node->last_seen_at ?? 'never',
                ));
            }
        }

        // A node recorded but never seen is the interesting case: it either
        // never finished booting or cannot reach us.
        $neverSeen = $known->filter(fn ($n) => $n->last_seen_at === null)->count();

        $this->newLine();
        $this->line(sprintf('%d connected, %d absent, %d never seen.', $known->count() - $missing, $missing, $neverSeen));

        return $missing > 0 ? self::FAILURE : self::SUCCESS;
    }
}
