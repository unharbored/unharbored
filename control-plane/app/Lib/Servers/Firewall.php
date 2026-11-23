<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Cloud\DatabaseServers;
use App\Lib\Providers\Incus\Ingress;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * A host firewall (ufw) per server, with allow-lists.
 *
 * Off by default: it only ever comes on when someone turns it on. On, it denies
 * everything incoming except SSH and the web ports, each optionally limited to
 * a list of addresses. An empty list means "anyone", so turning it on with no
 * lists changes nothing a visitor can see, and a list is always something
 * the person typed. The agent dials out, so management keeps working whatever
 * the lists say; a console from the browser still reaches the server if SSH
 * is closed to you. Private addresses (10.0.0.0/8) are always let through to
 * the web ports, so a load balancer and the other servers of a pool still
 * reach their members.
 */
final class Firewall
{
    public const MAX_ENTRIES = 50;

    /** @return array{enabled: bool, ssh: list<string>, web: list<string>, ssh_private: bool, web_private: bool, database: ?array{port: int, sources: list<string>}} */
    public static function settings(string $nodeId): array
    {
        $raw = json_decode((string) DB::table('nodes')->where('node_id', $nodeId)->value('firewall'), true) ?: [];
        // A database server is never open: its engine's port is for the admitted servers alone, so the firewall is always on.
        $database = DatabaseServers::firewallFor($nodeId);

        return ['database' => $database, 'enabled' => $database !== null || (bool) ($raw['enabled'] ?? false), 'ssh' => array_values($raw['ssh'] ?? []), 'web' => array_values($raw['web'] ?? []),
            // "Tailnet only": that port is open on the tailscale0 interface and nowhere else.
            'ssh_private' => (bool) ($raw['ssh_private'] ?? false), 'web_private' => (bool) ($raw['web_private'] ?? false)];
    }

    /**
     * One address or network per line (or comma): 203.0.113.7, 203.0.113.0/24, 2001:db8::/32.
     *
     * @return list<string>
     */
    public static function parse(string $text, string $label): array
    {
        $out = [];
        // A # starts a comment that runs to the end of its line.
        foreach (preg_split('/[\s,]+/', trim((string) preg_replace('/#[^\n]*/', '', $text))) ?: [] as $entry) {
            if ($entry === '') {
                continue;
            }
            if (! self::valid($entry)) {
                throw new InvalidArgumentException("{$label}: \"{$entry}\" is not an IP address or network such as 203.0.113.7 or 203.0.113.0/24.");
            }
            $out[] = strtolower($entry);
        }
        $out = array_values(array_unique($out));
        if (count($out) > self::MAX_ENTRIES) {
            throw new InvalidArgumentException("{$label}: at most ".self::MAX_ENTRIES.' entries.');
        }

        return $out;
    }

    public static function valid(string $entry): bool
    {
        [$ip, $prefix] = array_pad(explode('/', $entry, 2), 2, null);
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return false;
        }
        if ($prefix === null) {
            return true;
        }
        $max = str_contains($ip, ':') ? 128 : 32;

        return ctype_digit($prefix) && (int) $prefix <= $max;
    }

    /**
     * @param  list<string>  $ssh
     * @param  list<string>  $web
     */
    public static function save(string $nodeId, bool $enabled, array $ssh, array $web, bool $sshPrivate = false, bool $webPrivate = false): void
    {
        DB::table('nodes')->where('node_id', $nodeId)->update(['firewall' => json_encode(['enabled' => $enabled, 'ssh' => $ssh, 'web' => $web, 'ssh_private' => $sshPrivate, 'web_private' => $webPrivate]), 'updated_at' => now()]);
    }

    /**
     * What the agent runs. Rules are rebuilt from nothing each time, so the
     * firewall is exactly what the lists say. Between the reset and the enable
     * the server is unfiltered, never closed, and a connection already open
     * (the agent's, a shell's) is kept by ufw's own state tracking.
     *
     * @param  array{enabled: bool, ssh: list<string>, web: list<string>, ssh_private?: bool, web_private?: bool, ingress?: ?string}  $s
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function recipe(array $s): array
    {
        if (! $s['enabled']) {
            return ['name' => 'firewall-off', 'steps' => [
                ['id' => 'ufw-off', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'command -v ufw >/dev/null 2>&1 && ufw --force disable || true'], 'description' => 'firewall off'],
            ]];
        }

        $ufw = fn (string $id, array $args, string $why) => ['id' => $id, 'type' => 'command', 'name' => 'ufw', 'args' => $args, 'description' => $why];
        $steps = [
            ['id' => 'ufw-package', 'type' => 'package', 'name' => 'ufw'],
            $ufw('ufw-reset', ['--force', 'reset'], 'start from no rules'),
            $ufw('ufw-in', ['default', 'deny', 'incoming'], 'deny incoming by default'),
            $ufw('ufw-out', ['default', 'allow', 'outgoing'], 'allow outgoing'),
        ];

        $allow = function (string $id, array $sources, array $ports, string $what) use (&$steps, $ufw): void {
            foreach ($ports as $port) {
                if ($sources === []) {
                    $steps[] = $ufw("{$id}-{$port}-any", ['allow', "{$port}/tcp"], "{$what} port {$port} from anywhere");

                    continue;
                }
                foreach ($sources as $i => $source) {
                    $steps[] = $ufw("{$id}-{$port}-{$i}", ['allow', 'from', $source, 'to', 'any', 'port', (string) $port, 'proto', 'tcp'], "{$what} port {$port} from {$source}");
                }
            }
        };
        // Tailnet only replaces a port's public rule (and its list): the port opens on tailscale0 alone.
        $tailnet = function (string $id, int $port) use (&$steps, $ufw): void {
            $steps[] = $ufw("{$id}-{$port}-tailnet", ['allow', 'in', 'on', 'tailscale0', 'to', 'any', 'port', (string) $port, 'proto', 'tcp'], "port {$port} from the tailnet only");
        };
        if ($s['ssh_private'] ?? false) {
            $tailnet('ufw-ssh', 22);
        } else {
            $allow('ufw-ssh', $s['ssh'], [22], 'SSH');
        }
        if ($s['web_private'] ?? false) {
            $tailnet('ufw-web', 80);
            $tailnet('ufw-web', 443);
        } else {
            $allow('ufw-web', $s['web'], [80, 443], 'web');
        }
        if ($s['web'] !== [] || ($s['web_private'] ?? false)) {
            // The private network: a load balancer, a pool's other servers, a database's grant.
            foreach ([80, 443] as $port) {
                $steps[] = $ufw("ufw-private-{$port}", ['allow', 'from', '10.0.0.0/8', 'to', 'any', 'port', (string) $port, 'proto', 'tcp'], "private network to port {$port}");
            }
        }
        // The Incus host's ingress, which brings visitors without IPv6: HTTP, and HTTPS on its own port.
        if (($s['ingress'] ?? null) !== null) {
            foreach ([80, Ingress::PROXY_PORT] as $port) {
                $steps[] = $ufw("ufw-ingress-{$port}", ['allow', 'from', $s['ingress'], 'to', 'any', 'port', (string) $port, 'proto', 'tcp'], "the host's ingress to port {$port}");
            }
        }
        // A database server: its engine's port, from each admitted server and nobody else.
        foreach ((array) ($s['database']['sources'] ?? []) as $i => $source) {
            $steps[] = $ufw("ufw-database-{$i}", ['allow', 'from', $source, 'to', 'any', 'port', (string) $s['database']['port'], 'proto', 'tcp'], "the database port from {$source}");
        }
        // Ports a service asked for and a person approved (Git over SSH, say). Containers never appear here: they bind 127.0.0.1.
        foreach ($s['extra'] ?? [] as $p) {
            $steps[] = $ufw("ufw-extra-{$p['port']}-{$p['proto']}", ['allow', "{$p['port']}/{$p['proto']}"], "extra port {$p['port']}/{$p['proto']} ({$p['label']}) approved for a service");
        }
        $steps[] = $ufw('ufw-enable', ['--force', 'enable'], 'firewall on');

        return ['name' => 'firewall', 'steps' => $steps];
    }
}
