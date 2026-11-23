<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use App\Lib\Gateway\GatewayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Receives what the gateway learns about nodes.
 *
 * The gateway holds the connections, so it is the only thing that knows when
 * a node arrives or leaves. Without this endpoint the control plane would
 * have a list of nodes it provisioned and no idea which of them are alive.
 *
 * Authenticated by the same shared secret the control plane uses in the other
 * direction, compared in constant time. The link must not be reachable from
 * outside: anything that can post here can tell the control plane a node is
 * connected when it is not, or disconnected when it is.
 */
final class GatewayEventController extends Controller
{
    public function __construct(private readonly AuditLog $audit) {}

    public function store(Request $request): JsonResponse
    {
        if (! $this->authorized($request)) {
            Log::warning('Rejected an unauthorised gateway event', ['ip' => $request->ip()]);

            return response()->json(['error' => 'unauthorized'], 401);
        }

        $validated = $request->validate([
            'kind' => ['required', 'string', 'max:64'],
            'body' => ['required', 'array'],
        ]);

        match ($validated['kind']) {
            'node.connected' => $this->nodeConnected($validated['body']),
            'node.disconnected' => $this->nodeDisconnected($validated['body']),
            'command.progress' => $this->progress($validated['body']),
            'node.metrics' => $this->metrics($validated['body']),
            'node.logs' => $this->logs($validated['body']),
            'terminal.output' => app(\App\Lib\Console\Consoles::class)->output($validated['body']),
            default => Log::info('Ignoring an unknown gateway event', ['kind' => $validated['kind']]),
        };

        return response()->json(['received' => true]);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function nodeConnected(array $body): void
    {
        $nodeId = (string) ($body['node_id'] ?? '');
        $organizationId = (string) ($body['organization_id'] ?? '');

        if ($nodeId === '' || $organizationId === '') {
            return;
        }

        // The gateway resolved the organisation from the CA that signed the
        // node's certificate, so this is trustworthy in a way that a field
        // the node supplied would not be.
        $updated = DB::table('nodes')->where('node_id', $nodeId)->where('organization_id', $organizationId)->update([
            'last_seen_at' => now(),
            'agent_version' => $body['agent_version'] ?? null,
            'hostname' => $body['hostname'] ?? null,
            'os' => $body['os'] ?? null,
            'arch' => $body['arch'] ?? null,
            'updated_at' => now(),
        ]);

        if ($updated === 0) {
            // A node the gateway accepts but we have no record of. Its
            // certificate chains to a real organisation CA, so it was
            // enrolled by us at some point: most likely the row was deleted
            // while the node kept running. Worth knowing about rather than
            // discarding.
            Log::warning('A node connected that the control plane does not know', [
                'node_id' => $nodeId,
                'organization_id' => $organizationId,
            ]);
        }

        $this->audit->record(
            organizationId: $organizationId,
            actor: Actor::agent($nodeId),
            action: 'node.connected',
            resourceType: 'node',
            resourceId: $nodeId,
            metadata: [
                'agent_version' => $body['agent_version'] ?? null,
                'hostname' => $body['hostname'] ?? null,
                'tracked' => $updated > 0,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function nodeDisconnected(array $body): void
    {
        $nodeId = (string) ($body['node_id'] ?? '');
        $organizationId = (string) ($body['organization_id'] ?? '');

        if ($nodeId === '' || $organizationId === '') {
            return;
        }

        // last_seen_at is deliberately left alone. It records when the node
        // was last known to be alive, and a disconnect is precisely the
        // moment that stops being now.
        $this->audit->record(
            organizationId: $organizationId,
            actor: Actor::agent($nodeId),
            action: 'node.disconnected',
            // A disconnect is not a success. A node that drops off is either
            // being replaced or is in trouble, and both are worth finding in
            // an audit search for things that went wrong.
            outcome: Outcome::Failed,
            resourceType: 'node',
            resourceId: $nodeId,
            reason: $body['reason'] ?? null,
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function progress(array $body): void
    {
        // Deploy log lines. Not audited: they are output, not actions, and
        // writing each one into a hash-chained log would make the chain
        // enormous and no more trustworthy.
        //
        // Phase 5 gives these somewhere to live. Until then they are logged
        // so a deploy can still be followed.
        Log::info('node progress', [
            'node_id' => $body['node_id'] ?? null,
            'command_id' => $body['command_id'] ?? null,
            'seq' => $body['seq'] ?? null,
            'text' => $body['text'] ?? null,
        ]);
    }

    /**
     * One host sample a minute from each agent. The node id comes from the
     * gateway, which took it from the agent's certificate, so a server can
     * only report for itself.
     *
     * @param  array<string, mixed>  $body
     */
    private function metrics(array $body): void
    {
        $sample = (array) ($body['sample'] ?? []);
        $root = (array) ($sample['disks']['/'] ?? []);
        if (! isset($body['node_id'], $body['organization_id'], $sample['collected_at'])) {
            return;
        }
        DB::table('node_metrics')->insert([
            'organization_id' => (string) $body['organization_id'],
            'node_id' => (string) $body['node_id'],
            // The node's clock is not trusted for ordering: a sample is
            // filed when it arrives, which is within seconds of collection.
            'collected_at' => now(),
            'cpu' => round((float) ($sample['cpu_percent'] ?? 0), 2),
            'load_1' => round((float) ($sample['load_1'] ?? 0), 2),
            'mem_total' => (int) ($sample['mem_total'] ?? 0),
            'mem_available' => (int) ($sample['mem_available'] ?? 0),
            'disk_total' => (int) ($root['total'] ?? 0),
            'disk_free' => (int) ($root['free'] ?? 0),
            'uptime' => (int) ($sample['uptime_seconds'] ?? 0),
        ]);
    }

    /**
     * A batch of log lines, redacted by the agent before it sent them. A
     * gap the agent had to drop is stored as a line of its own, so a quiet
     * stretch is not mistaken for a healthy one.
     *
     * @param  array<string, mixed>  $body
     */
    private function logs(array $body): void
    {
        if (! isset($body['node_id'], $body['organization_id'])) {
            return;
        }
        $rows = [];
        $now = now();
        foreach ((array) ($body['batch']['lines'] ?? []) as $line) {
            $rows[] = [
                'organization_id' => (string) $body['organization_id'],
                'node_id' => (string) $body['node_id'],
                'source' => mb_substr((string) ($line['source'] ?? '?'), 0, 128),
                // The node's clock, but never in the future: it orders lines
                // within a batch, and a skewed clock must not bury them.
                'logged_at' => \Illuminate\Support\Carbon::createFromTimestampMs(min((int) ($line['ts'] ?? 0), $now->getTimestampMs()) ?: $now->getTimestampMs()),
                'text' => mb_substr((string) ($line['text'] ?? ''), 0, 8192),
            ];
        }
        if (($dropped = (int) ($body['batch']['dropped'] ?? 0)) > 0) {
            $rows[] = ['organization_id' => (string) $body['organization_id'], 'node_id' => (string) $body['node_id'], 'source' => 'agent',
                'logged_at' => $now, 'text' => "{$dropped} lines dropped: the server logged faster than they could be sent"];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            DB::table('log_lines')->insert($chunk);
        }
    }

    private function authorized(Request $request): bool
    {
        $expected = (string) config('control_plane.gateway_token');

        if ($expected === '') {
            return false;
        }

        return hash_equals($expected, (string) $request->header(GatewayClient::AUTH_HEADER, ''));
    }
}
