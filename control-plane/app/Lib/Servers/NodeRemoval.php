<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Gateway\OrganisationSync;
use App\Lib\Providers\Incus\IncusHost;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * Takes a server out of the platform.
 *
 * Revocation is what guarantees it: the node's certificate is refused by the
 * gateway from the moment the sync lands, connected or not. Uninstalling the
 * agent first is a courtesy to whoever owns the machine, and best effort.
 *
 * The Droplet, if the platform made one, is left alone. Destroying a machine
 * is a separate, deliberate act, and it keeps billing until someone does.
 */
final class NodeRemoval
{
    public function __construct(
        private readonly GatewayClient $gateway,
        private readonly OrganisationSync $sync,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @return array{uninstalled: bool, revoked: bool, synced: bool, droplet: ?string}
     */
    public function remove(string $nodeId, string $actor): array
    {
        $node = DB::table('nodes')->where('node_id', $nodeId)->first()
            ?? throw new InvalidArgumentException("No server {$nodeId}.");

        $sites = DB::table('sites')->where('node_id', $nodeId)->pluck('name')->all();
        $secondary = DB::table('sites')->whereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id'))->pluck('name')->all();
        $sites = array_values(array_unique([...$sites, ...$secondary]));
        if ($sites !== []) {
            throw new InvalidArgumentException("{$nodeId} still serves ".implode(', ', $sites).'. Move or remove them first.');
        }
        if (DB::table('operations')->where('node_id', $nodeId)->where('kind', '!=', 'server_delete')->whereIn('status', ['queued', 'running'])->exists()) {
            throw new InvalidArgumentException("An operation is running on {$nodeId}. Wait for it to finish.");
        }

        $slug = strstr($nodeId, '.', true) ?: $nodeId;
        $dropletResource = DB::table('resources')->where('organization_id', $node->organization_id)->where('type', 'server')->where('slug', $slug)->first();
        if (! empty((json_decode((string) ($dropletResource->attributes ?? ''), true) ?: [])['pool'])) {
            throw new InvalidArgumentException("{$nodeId} is in an autoscale pool; stop autoscaling that application instead.");
        }

        if (($node->role ?? null) === 'ingress') {
            throw new InvalidArgumentException('The IPv4 ingress belongs to the Incus host; it goes when the host is removed.');
        }
        if (($node->role ?? null) === IncusHost::ROLE) {
            // Incus itself stays installed on the machine; only what Unharbored keeps on and about it goes.
            app(IncusHost::class)->teardown($node->organization_id, $nodeId, static function (): void {}, $actor);
        }

        $uninstalled = false;
        try {
            if ($this->gateway->isConnected($nodeId)) {
                $result = $this->gateway->run($node->organization_id, $nodeId, 'apply_recipe', ['spec' => self::uninstallRecipe()],
                    timeoutSeconds: 30, reason: "Remove {$nodeId}");
                $uninstalled = ($result['status'] ?? null) === 'succeeded';
            }
        } catch (Throwable) {
            // Unreachable or refused: revocation below still cuts it off.
        }

        $slug = strstr($nodeId, '.', true) ?: $nodeId;
        $droplet = DB::table('resources')->where('organization_id', $node->organization_id)->where('slug', $slug)->value('external_id');
        ['serial' => $serial, 'synced' => $synced] = $this->revoke($node);

        $this->audit->record($node->organization_id, Actor::system($actor), 'node.removed',
            resourceType: 'node', resourceId: $nodeId,
            metadata: ['serial' => $serial, 'uninstalled' => $uninstalled, 'droplet' => $droplet, 'gateway_synced' => $synced]);

        return ['uninstalled' => $uninstalled, 'revoked' => $serial !== null, 'synced' => $synced, 'droplet' => $droplet];
    }

    /**
     * Removes a node record whose Droplet is already gone at the provider.
     *
     * For a server the platform provisioned and that was destroyed, or whose
     * Droplet was deleted outside cloud-manager. Nothing is sent to the
     * machine: it no longer exists. This exists because the ordinary removal
     * path refuses a server that still carries an autoscale pool marker, so
     * a destroyed pool member could otherwise never be cleared.
     */
    public function removeStale(string $nodeId, string $actor): void
    {
        $node = DB::table('nodes')->where('node_id', $nodeId)->first()
            ?? throw new InvalidArgumentException("No server {$nodeId}.");
        $slug = strstr($nodeId, '.', true) ?: $nodeId;

        $resource = DB::table('resources')->where('organization_id', $node->organization_id)
            ->where('type', 'server')->where('slug', $slug)->first();
        if ($resource === null || $resource->state !== 'deleted') {
            throw new InvalidArgumentException("{$nodeId}'s Droplet still exists here; destroy it instead.");
        }

        $primary = DB::table('sites')->where('node_id', $nodeId)->pluck('name')->all();
        $secondary = DB::table('sites')->whereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id'))->pluck('name')->all();
        $sites = array_values(array_unique([...$primary, ...$secondary]));
        if ($sites !== []) {
            throw new InvalidArgumentException("{$nodeId} still serves ".implode(', ', $sites).'. Move or remove them first.');
        }
        if (DB::table('operations')->where('node_id', $nodeId)->where('kind', '!=', 'server_delete')->whereIn('status', ['queued', 'running'])->exists()) {
            throw new InvalidArgumentException("An operation is running on {$nodeId}. Wait for it to finish.");
        }

        ['serial' => $serial] = $this->revoke($node, 'stale');
        $this->audit->record($node->organization_id, Actor::system($actor), 'node.stale_removed',
            resourceType: 'node', resourceId: $nodeId, metadata: ['serial' => $serial, 'droplet' => $resource->external_id]);
    }

    /**
     * Cuts a node off: its certificate revoked at the gateway and its record
     * gone. No checks; remove() makes them for a server someone removes.
     *
     * @return array{serial: ?string, synced: bool}
     */
    public function revoke(object $node, string $reason = 'removed'): array
    {
        $serial = $node->certificate_pem ? OrganisationSync::serialOf((string) $node->certificate_pem) : null;
        if ($serial !== null) {
            DB::table('revoked_certificates')->insertOrIgnore([
                'organization_id' => $node->organization_id,
                'node_id' => $node->node_id,
                'serial' => $serial,
                'reason' => $reason,
                'revoked_at' => now(),
            ]);
        }
        DB::table('enrollment_grants')->where('node_id', $node->node_id)->delete();
        DB::table('nodes')->where('node_id', $node->node_id)->delete();

        $synced = true;
        try {
            $this->sync->push();
        } catch (Throwable) {
            // Recorded; the gateway picks it up at its next sync or restart.
            $synced = false;
        }

        return ['serial' => $serial, 'synced' => $synced];
    }

    /**
     * Stops and deletes the agent a few seconds later, so the command can
     * report back first: its service, binary, and saved state with the key.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function uninstallRecipe(): array
    {
        $cleanup = 'systemctl disable --now cloud-manager-agent.service; '
            .'rm -rf /var/lib/cloud-manager /usr/local/bin/cloud-manager-agent /etc/systemd/system/cloud-manager-agent.service; '
            .'systemctl daemon-reload';

        return ['name' => 'agent-uninstall', 'steps' => [[
            'id' => 'uninstall-later',
            'type' => 'command',
            'name' => 'systemd-run',
            'args' => ['--on-active=3', '--timer-property=AccuracySec=1s', 'sh', '-c', $cleanup],
            'description' => 'agent removes itself in 3s',
        ]]];
    }
}
