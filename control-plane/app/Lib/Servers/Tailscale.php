<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use Illuminate\Support\Facades\DB;

/**
 * Putting a server on the organisation's tailnet (docs/specs/tailscale.md).
 *
 * We install Tailscale, join it with the organisation's tagged auth key, and
 * (through the firewall) can close SSH and the web ports to everything but the
 * tailnet. The tailnet itself, its people and its access rules stay in
 * Tailscale's own admin console.
 *
 * The auth key reaches the server in a root-only file that exists for the one
 * `tailscale up` and is removed straight after, even when that fails. It is
 * never an argument, so it appears in no process list or operation log.
 */
final class Tailscale
{
    /** The provider name the key is stored under in the vault. */
    public const PROVIDER = 'tailscale';

    public const KEY_FILE = '/root/.cloud-manager-tailscale-key';

    /** Where Tailscale's admin console makes keys; shown beside the key form. */
    public const KEYS_URL = 'https://login.tailscale.com/admin/settings/keys';

    /** An auth key, not an OAuth client secret (tskey-client-…), which this version does not take. */
    public static function validKey(string $key): bool
    {
        return preg_match('/^tskey-auth-[A-Za-z0-9_-]{8,200}$/', $key) === 1;
    }

    /** A hostname Tailscale accepts: lower-case letters, digits and hyphens. */
    public static function hostname(string $nodeId): string
    {
        $name = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower(strstr($nodeId, '.', true) ?: $nodeId)), '-');

        return substr($name !== '' ? $name : 'server', 0, 63);
    }

    /** @return array{address: string, name: string, joined_at: string}|null */
    public static function info(string $nodeId): ?array
    {
        return json_decode((string) DB::table('nodes')->where('node_id', $nodeId)->value('tailnet'), true) ?: null;
    }

    /** @param  array{address: string, name: string, joined_at: string}|null  $info */
    public static function save(string $nodeId, ?array $info): void
    {
        DB::table('nodes')->where('node_id', $nodeId)->update(['tailnet' => $info === null ? null : json_encode($info), 'updated_at' => now()]);
    }

    /**
     * Reads `tailscale status --json`.
     *
     * @return array{online: bool, address: ?string, name: ?string}
     */
    public static function parseStatus(string $json): array
    {
        $status = json_decode($json, true);
        $self = is_array($status) ? ($status['Self'] ?? []) : [];
        $ips = array_values(array_filter($self['TailscaleIPs'] ?? [], fn ($ip) => is_string($ip) && ! str_contains($ip, ':')));

        return [
            'online' => is_array($status) && ($status['BackendState'] ?? '') === 'Running' && ($self['Online'] ?? true) !== false && $ips !== [],
            'address' => $ips[0] ?? null,
            'name' => isset($self['DNSName']) ? rtrim((string) $self['DNSName'], '.') : null,
        ];
    }

    /**
     * Install (once), then join. The package repository is the one for the release the server runs (22.04, 24.04 or 26.04).
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function joinRecipe(string $hostname, #[\SensitiveParameter] string $authKey): array
    {
        $keyring = '/usr/share/keyrings/tailscale-archive-keyring.gpg';
        $installed = ['sh', '-c', 'command -v tailscale >/dev/null 2>&1'];

        return ['name' => 'tailscale-join', 'steps' => [
            ['id' => 'ts-curl', 'type' => 'package', 'name' => 'curl'],
            // The repository is named for the release the server runs, read from the server itself.
            ['id' => 'ts-keyring', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', ". /etc/os-release && curl -fsSL https://pkgs.tailscale.com/stable/ubuntu/\$VERSION_CODENAME.noarmor.gpg -o {$keyring}"],
                'unless' => ['test', '-s', $keyring], 'description' => "Tailscale's package signing key"],
            ['id' => 'ts-source', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', ". /etc/os-release && echo \"deb [signed-by={$keyring}] https://pkgs.tailscale.com/stable/ubuntu \$VERSION_CODENAME main\" > /etc/apt/sources.list.d/tailscale.list"],
                'unless' => ['test', '-s', '/etc/apt/sources.list.d/tailscale.list'], 'description' => "Tailscale's package source"],
            ['id' => 'ts-update', 'type' => 'command', 'name' => 'apt-get', 'args' => ['update', '-qq'], 'unless' => $installed, 'description' => 'refresh the package lists'],
            ['id' => 'ts-package', 'type' => 'package', 'name' => 'tailscale'],
            ['id' => 'ts-service', 'type' => 'service', 'name' => 'tailscaled', 'state' => 'running'],
            ['id' => 'ts-key', 'type' => 'file', 'path' => self::KEY_FILE, 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode($authKey."\n")],
            ['id' => 'ts-up', 'type' => 'command', 'name' => 'tailscale', 'args' => ['up', '--authkey=file:'.self::KEY_FILE, '--hostname='.$hostname, '--ssh=false', '--reset'], 'description' => 'join the tailnet'],
            ['id' => 'ts-key-gone', 'type' => 'absent', 'path' => self::KEY_FILE],
        ]];
    }

    /** Removes the key file, run after a join whether or not it worked. */
    public static function cleanupRecipe(): array
    {
        return ['name' => 'tailscale-cleanup', 'steps' => [['id' => 'ts-key-gone', 'type' => 'absent', 'path' => self::KEY_FILE]]];
    }

    public static function leaveRecipe(): array
    {
        return ['name' => 'tailscale-leave', 'steps' => [
            ['id' => 'ts-logout', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'command -v tailscale >/dev/null 2>&1 && tailscale logout || true'], 'description' => 'leave the tailnet'],
        ]];
    }
}
