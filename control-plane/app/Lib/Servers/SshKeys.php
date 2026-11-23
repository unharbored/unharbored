<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\PublicKeyLoader;
use phpseclib3\Crypt\RSA;
use Throwable;

/**
 * The organisation's SSH public keys, and which servers carry each.
 *
 * Public keys only. A private key pasted by mistake is refused, never stored.
 * The keys go to a server's login user, not to root and not to the app user,
 * and Unharbored never uses SSH to command a server (ADR 0001).
 */
final class SshKeys
{
    /** Shorter RSA keys are refused. */
    public const MIN_RSA_BITS = 3072;

    public function __construct(private readonly AuditLog $audit) {}

    /**
     * Checks a key and works out its type and fingerprint.
     *
     * @return array{public_key: string, type: string, fingerprint: string}
     */
    public static function inspect(string $text): array
    {
        $text = trim(str_replace("\r\n", "\n", $text));
        if (str_contains($text, 'PRIVATE KEY') || str_contains($text, "\n")) {
            throw new InvalidArgumentException(str_contains($text, 'PRIVATE KEY')
                ? 'That looks like a private key. Paste the public half, the file ending in .pub.'
                : 'Paste one key, on one line.');
        }
        if (! preg_match('/^(ssh-ed25519|ecdsa-sha2-nistp(?:256|384|521)|ssh-rsa)\s+([A-Za-z0-9+\/]+={0,3})(?:\s+(.*))?$/', $text, $m)) {
            throw new InvalidArgumentException('That is not a public key. It starts with ssh-ed25519, ecdsa-sha2-… or ssh-rsa.');
        }
        [, $type, $blob, $comment] = $m + [3 => ''];
        try {
            $key = PublicKeyLoader::load("{$type} {$blob}");
        } catch (Throwable) {
            throw new InvalidArgumentException('That public key is not valid. Copy the whole line from the .pub file.');
        }
        if ($key instanceof RSA\PublicKey && $key->getLength() < self::MIN_RSA_BITS) {
            throw new InvalidArgumentException('RSA keys must be at least '.self::MIN_RSA_BITS.' bits. Use an ed25519 key.');
        }
        if (! $key instanceof RSA\PublicKey && ! $key instanceof EC\PublicKey) {
            throw new InvalidArgumentException('That kind of key is not accepted. Use ed25519, ECDSA or RSA.');
        }
        $raw = base64_decode($blob, true);

        return [
            'public_key' => trim("{$type} {$blob} ".preg_replace('/[^\x20-\x7e]/', '', (string) $comment)),
            'type' => $type === 'ssh-ed25519' ? 'ed25519' : ($type === 'ssh-rsa' ? 'rsa' : 'ecdsa'),
            'fingerprint' => 'SHA256:'.rtrim(base64_encode(hash('sha256', (string) $raw, true)), '='),
        ];
    }

    /** @return array<string, mixed> the saved key */
    public function add(string $organizationId, string $name, string $publicKey, bool $installOnNewServers, string $by): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 60) {
            throw new InvalidArgumentException('Give the key a name of up to 60 characters.');
        }
        $key = self::inspect($publicKey);
        if (DB::table('ssh_keys')->where('organization_id', $organizationId)->where('fingerprint', $key['fingerprint'])->exists()) {
            throw new InvalidArgumentException('That key is already here.');
        }
        $id = DB::table('ssh_keys')->insertGetId($key + ['organization_id' => $organizationId, 'name' => $name,
            'install_on_new_servers' => $installOnNewServers, 'created_by' => $by, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record($organizationId, Actor::system($by), 'ssh_key.added', resourceType: 'ssh_key', resourceId: (string) $id,
            metadata: ['name' => $name, 'fingerprint' => $key['fingerprint']]);

        return (array) DB::table('ssh_keys')->find($id);
    }

    public function setDefault(string $organizationId, int $id, bool $installOnNewServers): void
    {
        DB::table('ssh_keys')->where('organization_id', $organizationId)->where('id', $id)->update(['install_on_new_servers' => $installOnNewServers, 'updated_at' => now()]);
    }

    /**
     * Removes a key from the organisation. Every server that has it is asked to
     * drop it; the rows stay as "removing" until each server has, so an offline
     * server shows as pending rather than being forgotten.
     *
     * @return list<string> the servers that must now drop it
     */
    public function remove(string $organizationId, int $id, string $by): array
    {
        $key = DB::table('ssh_keys')->where('organization_id', $organizationId)->where('id', $id)->first() ?? throw new InvalidArgumentException('No such key.');
        $nodes = DB::table('server_ssh_keys')->where('ssh_key_id', $id)->pluck('node_id')->all();
        DB::table('server_ssh_keys')->where('ssh_key_id', $id)->update(['state' => 'removing', 'updated_at' => now()]);
        // The key row stays while any server still has it, since its text is how it is taken off.
        if ($nodes === []) {
            DB::table('ssh_keys')->where('id', $id)->delete();
        } else {
            DB::table('ssh_keys')->where('id', $id)->update(['install_on_new_servers' => false, 'name' => $key->name.' (removed)', 'updated_at' => now()]);
        }
        $this->audit->record($organizationId, Actor::system($by), 'ssh_key.removed', resourceType: 'ssh_key', resourceId: (string) $id,
            metadata: ['name' => $key->name, 'fingerprint' => $key->fingerprint, 'servers' => $nodes]);

        return $nodes;
    }

    /**
     * Asks for a key on one server, or for it to go. This records the wish;
     * an operation writes it.
     */
    public function setOnServer(string $organizationId, string $nodeId, int $id, bool $installed, string $by): void
    {
        DB::table('ssh_keys')->where('organization_id', $organizationId)->where('id', $id)->first() ?? throw new InvalidArgumentException('No such key.');
        $row = DB::table('server_ssh_keys')->where('node_id', $nodeId)->where('ssh_key_id', $id)->first();
        if ($installed) {
            if ($row === null) {
                DB::table('server_ssh_keys')->insert(['node_id' => $nodeId, 'ssh_key_id' => $id, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
            } elseif ($row->state === 'removing') {
                DB::table('server_ssh_keys')->where('id', $row->id)->update(['state' => 'pending', 'updated_at' => now()]);
            }
        } elseif ($row !== null) {
            DB::table('server_ssh_keys')->where('id', $row->id)->update(['state' => 'removing', 'updated_at' => now()]);
        }
        $this->audit->record($organizationId, Actor::system($by), $installed ? 'ssh_key.install_requested' : 'ssh_key.removal_requested',
            resourceType: 'node', resourceId: $nodeId, metadata: ['key' => $id]);
    }

    /**
     * The key lines a server should carry now: asked for, not asked to go.
     *
     * @return list<string>
     */
    public static function wanted(string $nodeId): array
    {
        return DB::table('server_ssh_keys')->join('ssh_keys', 'ssh_keys.id', '=', 'server_ssh_keys.ssh_key_id')
            ->where('server_ssh_keys.node_id', $nodeId)->whereIn('server_ssh_keys.state', ['pending', 'installed'])
            ->orderBy('ssh_keys.id')->pluck('ssh_keys.public_key')->all();
    }

    /**
     * The keys picked when a server was created: these, and not the
     * organisation's defaults. Ids that are not the organisation's are ignored.
     *
     * @param  list<int>  $ids
     */
    public static function choose(string $organizationId, string $nodeId, array $ids): void
    {
        foreach (DB::table('ssh_keys')->where('organization_id', $organizationId)->whereIn('id', $ids)->pluck('id') as $id) {
            DB::table('server_ssh_keys')->insertOrIgnore(['node_id' => $nodeId, 'ssh_key_id' => $id, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('nodes')->where('node_id', $nodeId)->update(['ssh_keys_chosen' => true]);
    }

    /** A server that has no login user yet gets the keys marked for new servers. */
    public static function addDefaultsTo(string $organizationId, string $nodeId): void
    {
        foreach (DB::table('ssh_keys')->where('organization_id', $organizationId)->where('install_on_new_servers', true)->pluck('id') as $id) {
            DB::table('server_ssh_keys')->insertOrIgnore(['node_id' => $nodeId, 'ssh_key_id' => $id, 'state' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** After a server's keys are written: what was asked for is installed, and what was asked to go is gone. */
    public static function applied(string $nodeId): void
    {
        DB::table('server_ssh_keys')->where('node_id', $nodeId)->where('state', 'pending')->update(['state' => 'installed', 'updated_at' => now()]);
        $removed = DB::table('server_ssh_keys')->where('node_id', $nodeId)->where('state', 'removing')->pluck('ssh_key_id');
        DB::table('server_ssh_keys')->where('node_id', $nodeId)->where('state', 'removing')->delete();
        // A key removed from the organisation is deleted for good once no server has it.
        foreach ($removed as $id) {
            if (! DB::table('server_ssh_keys')->where('ssh_key_id', $id)->exists()
                && str_ends_with((string) DB::table('ssh_keys')->where('id', $id)->value('name'), ' (removed)')) {
                DB::table('ssh_keys')->where('id', $id)->delete();
            }
        }
    }
}
