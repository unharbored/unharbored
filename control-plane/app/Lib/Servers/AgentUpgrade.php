<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Agent\AgentRelease;
use App\Lib\Gateway\GatewayClient;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * Replaces the agent on a running node with the build the control plane is
 * serving, which is the same binary new Droplets get from cloud-init.
 *
 * The agent cannot restart itself mid-command and still report the result,
 * so the restart is handed to systemd a few seconds later. The command
 * returns, the agent exits, systemd starts the new binary, and it reconnects.
 * Success is the node reporting the new version, not the swap.
 */
final class AgentUpgrade
{
    public const BINARY = '/usr/local/bin/cloud-manager-agent';

    public const UNIT = '/etc/systemd/system/cloud-manager-agent.service';

    private const RESTART_DELAY_SECONDS = 5;

    public function __construct(
        private readonly AgentRelease $release,
        private readonly GatewayClient $gateway,
        private readonly string $publicUrl,
    ) {}

    /**
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function recipe(stdClass $node): array
    {
        $platform = in_array($node->arch ?? '', ['arm64', 'aarch64'], true) ? 'linux-arm64' : 'linux-amd64';
        $sha = $this->release->sha256($platform);
        $url = rtrim($this->publicUrl, '/')."/api/agent/{$platform}";
        $bin = self::BINARY;
        $new = "{$bin}.new";

        // Download beside the live binary, verify, then rename over it. A
        // rename is atomic and safe while the old binary is running; a
        // failed download or checksum leaves the agent untouched. Pinned to
        // the organisation CA, as at first boot.
        $install = "curl -fsSL --retry 3 --cacert /var/lib/cloud-manager/ca.crt -o {$new} {$url}"
            ." && echo '{$sha}  {$new}' | sha256sum -c -"
            ." && chmod 0755 {$new} && mv -f {$new} {$bin}";

        return ['name' => 'agent-upgrade', 'steps' => [
            [
                'id' => 'install',
                'type' => 'command',
                'name' => 'sh',
                'args' => ['-c', $install],
                'unless' => ['sh', '-c', "echo '{$sha}  {$bin}' | sha256sum -c --status -"],
                'description' => "agent binary is {$platform} {$this->release->version()}",
            ],
            [
                // Units written before 2026-11-22 carry RestrictSUIDSGID, which breaks package scripts that set
                // setgid bits (see CloudInit). The restart below brings the agent up without it.
                'id' => 'unit-allow-setgid',
                'type' => 'command',
                'name' => 'sh',
                'args' => ['-c', "sed -i '/^RestrictSUIDSGID=/d' ".self::UNIT.' && systemctl daemon-reload'],
                'unless' => ['sh', '-c', "! grep -q '^RestrictSUIDSGID=' ".self::UNIT],
                'description' => 'agent may install packages that set setgid bits',
            ],
            [
                'id' => 'restart-later',
                'type' => 'command',
                'name' => 'systemd-run',
                'args' => ['--on-active='.self::RESTART_DELAY_SECONDS, '--timer-property=AccuracySec=1s',
                    'systemctl', 'restart', 'cloud-manager-agent.service'],
                'description' => 'agent restarts in '.self::RESTART_DELAY_SECONDS.'s',
            ],
        ]];
    }

    /**
     * Points a node's agent at a different control plane name: its saved
     * state and its unit both carry the name, so both are rewritten, then the
     * agent restarts onto it. The certificate it will be shown must already
     * be valid for the new name; it pins the CA, not the name.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function readdressRecipe(string $from, string $to): array
    {
        foreach ([$from, $to] as $name) {
            if (! preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $name)) {
                throw new \InvalidArgumentException("Not a hostname: {$name}");
            }
        }
        $files = '/var/lib/cloud-manager/node.json /etc/systemd/system/cloud-manager-agent.service';
        $pattern = str_replace('.', '\\.', $from);

        return ['name' => 'agent-readdress', 'steps' => [
            [
                'id' => 'rewrite',
                'type' => 'command',
                'name' => 'sh',
                'args' => ['-c', "sed -i 's/{$pattern}/{$to}/g' {$files}"],
                'unless' => ['sh', '-c', "! grep -qF '{$from}' {$files}"],
                'description' => "agent knows the control plane as {$to}",
            ],
            ['id' => 'daemon-reload', 'type' => 'command', 'name' => 'systemctl', 'args' => ['daemon-reload'], 'description' => 'systemd re-reads the agent unit'],
            [
                'id' => 'restart-later',
                'type' => 'command',
                'name' => 'systemd-run',
                'args' => ['--on-active='.self::RESTART_DELAY_SECONDS, '--timer-property=AccuracySec=1s', 'systemctl', 'restart', 'cloud-manager-agent.service'],
                'description' => 'agent restarts in '.self::RESTART_DELAY_SECONDS.'s',
            ],
        ]];
    }

    /**
     * @param  (Closure(string): void)|null  $progress
     *
     * @throws RuntimeException When the node does not come back on the new version.
     */
    public function upgrade(stdClass $node, ?Closure $progress = null, int $waitSeconds = 90): string
    {
        $progress ??= static function (string $line): void {};
        $target = $this->release->version();

        $result = $this->gateway->run(
            organizationId: $node->organization_id,
            nodeId: $node->node_id,
            kind: 'apply_recipe',
            args: ['spec' => $this->recipe($node)],
            timeoutSeconds: 120,
            reason: "Upgrade agent to {$target}",
        );
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException('Upgrade recipe failed: '.($result['error'] ?? 'no detail'));
        }
        $progress("binary installed, waiting for the agent to reconnect as {$target}");

        $deadline = time() + $waitSeconds;
        do {
            sleep(3);
            $version = DB::table('nodes')->where('node_id', $node->node_id)->value('agent_version');
            if ($version === $target && $this->connected($node)) {
                return $target;
            }
        } while (time() < $deadline);

        throw new RuntimeException(sprintf('%s did not reconnect as %s within %ds; it reports %s.', $node->node_id, $target, $waitSeconds, $version ?? 'nothing'));
    }

    private function connected(stdClass $node): bool
    {
        foreach ($this->gateway->connectedNodes($node->organization_id) as $connected) {
            if (($connected['node_id'] ?? null) === $node->node_id) {
                return true;
            }
        }

        return false;
    }
}
