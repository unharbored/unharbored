<?php

declare(strict_types=1);

namespace App\Lib\Cloud;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\DigitalOcean\Client;
use App\Lib\DigitalOcean\Exceptions\RequestFailed;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceRecord;
use App\Lib\Resources\ResourceState;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * A snapshot of a Droplet with the server stack installed and nothing else:
 * no agent, no identity, no cloud-init history. Autoscale pools boot from it
 * so a new member skips the minutes of package installs; its cloud-init and
 * enrollment are exactly those of a stock Ubuntu Droplet, and the stack
 * recipe still runs on it, finding everything already in place.
 *
 * Built by the base_image operation on a throwaway Droplet that enrolls
 * like any server, gets the stack, is wiped and powered off, snapshotted,
 * and destroyed. Rebuilt monthly so security updates are not a month
 * stale on every new member.
 */
final class BaseImages
{
    public const STALE_DAYS = 30;

    public function __construct(
        private readonly CredentialStore $credentials,
        private readonly ResourceManager $resources,
        private readonly AuditLog $audit,
    ) {}

    /**
     * Run on the builder once its stack is in: everything that would make
     * two Droplets from the snapshot alike, or let one pass for the builder,
     * goes. Deferred a few seconds so the agent can report back first; the
     * machine powers itself off at the end.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function generaliseRecipe(): array
    {
        $wipe = 'systemctl disable --now cloud-manager-agent.service; '
            .'rm -rf /var/lib/cloud-manager /usr/local/bin/cloud-manager-agent /etc/systemd/system/cloud-manager-agent.service; '
            .'systemctl daemon-reload; '
            .'cloud-init clean --logs; '
            .'rm -f /etc/ssh/ssh_host_*; '
            .'truncate -s 0 /etc/machine-id; rm -f /var/lib/dbus/machine-id; '
            .'apt-get clean; rm -rf /tmp/* /var/tmp/*; '
            .'sync; poweroff';

        return ['name' => 'generalise', 'steps' => [[
            'id' => 'generalise-later',
            'type' => 'command',
            'name' => 'systemd-run',
            'args' => ['--on-active=3', '--timer-property=AccuracySec=1s', 'sh', '-c', $wipe],
            'description' => 'agent, identity and cloud-init state removed in 3s, then power off',
        ]]];
    }

    /** The newest usable image for a region, or null to boot stock Ubuntu. */
    public function current(string $organizationId, string $region): ?ResourceRecord
    {
        $rows = DB::table('resources')->where('organization_id', $organizationId)->where('type', 'base_image')
            ->where('state', 'active')->orderByDesc('id')->get();
        foreach ($rows as $row) {
            $record = ResourceRecord::fromRow($row);
            if (($record->attributes['region'] ?? null) === $region) {
                return $record;
            }
        }

        return null;
    }

    /** What a pool's Droplets boot from in this region. */
    public function imageFor(string $organizationId, string $region): string
    {
        return $this->current($organizationId, $region)?->externalId ?? 'ubuntu-24-04-x64';
    }

    /**
     * Asks for the snapshot once the builder is off. Returns null while
     * waiting, and the finished image once DigitalOcean has it.
     */
    public function snapshot(string $organizationId, string $slug, string $dropletId, string $region): ?ResourceRecord
    {
        $client = $this->client($organizationId, "Snapshotting {$slug}");
        $record = $this->resources->declare($organizationId, 'base_image', $slug, ['region' => $region, 'droplet' => $dropletId]);
        if ($record->state === ResourceState::Active) {
            return $record;
        }

        $action = $record->attributes['action'] ?? null;
        if ($action === null) {
            if (($client->get("droplets/{$dropletId}")['droplet']['status'] ?? null) !== 'off') {
                return null;
            }
            $id = $client->post("droplets/{$dropletId}/actions", ['type' => 'snapshot', 'name' => "cm-{$slug}"])['action']['id']
                ?? throw new RuntimeException('DigitalOcean accepted the snapshot but returned no action id.');
            $record = $this->resources->transition($record, ResourceState::Provisioning, reason: 'Snapshot requested', actor: Actor::system('control-plane'));
            $this->resources->mergeAttributes($record, ['action' => (string) $id]);

            return null;
        }

        $status = $client->get("actions/{$action}")['action']['status'] ?? null;
        if ($status === 'errored') {
            $this->resources->transition($record, ResourceState::Failed, reason: 'DigitalOcean could not snapshot the builder', actor: Actor::system('control-plane'));

            throw new RuntimeException('DigitalOcean could not snapshot the builder.');
        }
        if ($status !== 'completed') {
            return null;
        }
        $image = null;
        foreach ($client->paginate("droplets/{$dropletId}/snapshots", 'snapshots') as $s) {
            if (($s['name'] ?? null) === "cm-{$slug}") {
                $image = (string) $s['id'];
            }
        }
        $record = $this->resources->recordExternalId($record, $image ?? throw new RuntimeException("The snapshot finished but cm-{$slug} is not among the builder's snapshots."));

        return $this->resources->transition($record, ResourceState::Active, reason: 'Snapshot ready', actor: Actor::system('control-plane'));
    }

    /**
     * Points every pool in the region at the new image, then deletes the
     * images it replaces. Members already running are untouched.
     *
     * @return list<string> what it did
     */
    public function publish(ResourceRecord $image): array
    {
        $client = $this->client($image->organizationId, "Publishing base image {$image->slug}");
        $done = [];
        $pools = DB::table('resources')->where('organization_id', $image->organizationId)->where('type', 'autoscale_pool')->where('state', 'active')->get();
        foreach ($pools as $row) {
            $pool = ResourceRecord::fromRow($row);
            if (($pool->attributes['region'] ?? null) !== $image->attributes['region']) {
                continue;
            }
            $live = $client->get("droplets/autoscale/{$pool->externalId}")['autoscale_pool'] ?? [];
            $client->put("droplets/autoscale/{$pool->externalId}", [
                'name' => $live['name'],
                'config' => $live['config'],
                'droplet_template' => ['image' => $image->externalId] + $live['droplet_template'],
            ]);
            $done[] = "{$pool->slug} boots new members from it";
        }

        $older = DB::table('resources')->where('organization_id', $image->organizationId)->where('type', 'base_image')
            ->where('state', 'active')->where('id', '<', $image->id)->get();
        foreach ($older as $row) {
            $old = ResourceRecord::fromRow($row);
            if (($old->attributes['region'] ?? null) !== $image->attributes['region']) {
                continue;
            }
            $old = $this->resources->transition($old, ResourceState::Deleting, reason: "Replaced by {$image->slug}", actor: Actor::system('control-plane'));
            try {
                $client->delete("images/{$old->externalId}");
            } catch (RequestFailed $e) {
                $this->resources->transition($old, ResourceState::Failed, reason: 'Could not delete the old image', error: $e->getMessage(), actor: Actor::system('control-plane'));
                $done[] = "{$old->slug} could not be deleted: {$e->getMessage()}";

                continue;
            }
            $this->resources->transition($old, ResourceState::Deleted, reason: "Replaced by {$image->slug}", actor: Actor::system('control-plane'));
            $done[] = "{$old->slug} deleted";
        }
        $this->audit->record($image->organizationId, Actor::system('control-plane'), 'base_image.published', resourceType: 'base_image',
            resourceId: $image->slug, metadata: ['image' => $image->externalId, 'region' => $image->attributes['region']]);

        return $done;
    }

    /** Whether the region's image is missing or older than STALE_DAYS. */
    public function stale(string $organizationId, string $region): bool
    {
        $current = $this->current($organizationId, $region);

        return $current === null || $current->stateEnteredAt === null || $current->stateEnteredAt->addDays(self::STALE_DAYS)->isPast();
    }

    private function client(string $organizationId, string $reason): Client
    {
        return $this->credentials->clientFor($organizationId, $reason, Actor::system('control-plane'));
    }
}
