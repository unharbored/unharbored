<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Vault\Vault;
use Illuminate\Support\Facades\DB;

/**
 * The login user every server gets, for its owner: SSH by key, and sudo by a
 * password Unharbored generated and keeps in the vault.
 *
 * It is for people, not for Unharbored: the control plane never connects to
 * a server over SSH. The app runs as a different user (deploy) that nobody logs
 * in as. The password is set from a hash, so the plaintext never appears in a
 * recipe, a log or an audit line.
 */
final class AdminAccess
{
    /** The alphabet leaves out characters that read as one another. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyz23456789';

    public function __construct(private readonly Vault $vault, private readonly AuditLog $audit) {}

    /** The login user on a server: the name it was built with, else today's default. */
    public static function userFor(string $nodeId): string
    {
        return (string) (DB::table('nodes')->where('node_id', $nodeId)->value('admin_user') ?: ServerStack::ADMIN_USER);
    }

    /**
     * The password and its hash, made the first time they are needed.
     *
     * @return array{password: string, hash: string}
     */
    public function credentials(string $organizationId, string $nodeId): array
    {
        $json = $this->vault->get($organizationId, self::secret($nodeId));
        if ($json !== null && ($stored = json_decode($json, true)) && isset($stored['password'], $stored['hash'])) {
            return $stored;
        }

        return $this->make($organizationId, $nodeId);
    }

    /** Reading the password is audited, like revealing an application's variables. */
    public function reveal(string $organizationId, string $nodeId, Actor $actor): string
    {
        $this->audit->record($organizationId, $actor, 'node.admin_password_read', resourceType: 'node', resourceId: $nodeId);

        return $this->credentials($organizationId, $nodeId)['password'];
    }

    /** A new password. It reaches the server when the admin_access operation runs. */
    public function rotate(string $organizationId, string $nodeId, Actor $actor): void
    {
        $this->make($organizationId, $nodeId);
        $this->audit->record($organizationId, $actor, 'node.admin_password_rotated', resourceType: 'node', resourceId: $nodeId);
    }

    /**
     * What a server's recipe needs to create the user and set its keys. A
     * server with no login user yet also gets the keys marked for new servers.
     *
     * @return array{user: string, hash: string, keys: list<string>}
     */
    public function forStack(string $organizationId, string $nodeId): array
    {
        if (DB::table('nodes')->where('node_id', $nodeId)->whereNull('admin_user')->where('ssh_keys_chosen', false)->exists()) {
            SshKeys::addDefaultsTo($organizationId, $nodeId);
        }

        return ['user' => self::userFor($nodeId), 'hash' => $this->credentials($organizationId, $nodeId)['hash'], 'keys' => SshKeys::wanted($nodeId)];
    }

    /**
     * Why SSH cannot be locked down on this server yet, or null when it can.
     * Locking down removes root and password login, so the login user must
     * exist and hold a key that is already installed: otherwise nobody gets in.
     */
    public static function hardenBlock(string $nodeId): ?string
    {
        if (! DB::table('nodes')->where('node_id', $nodeId)->whereNotNull('admin_user')->exists()) {
            return 'The login user is not created yet. Install a key or Repair stack first.';
        }
        if (! DB::table('server_ssh_keys')->where('node_id', $nodeId)->where('state', 'installed')->exists()) {
            return 'No SSH key is installed for the login user, so you would be locked out. Install one first.';
        }

        return null;
    }

    /** After the recipe succeeded: remember the user's name, and that the keys are written. */
    public function applied(string $nodeId): void
    {
        DB::table('nodes')->where('node_id', $nodeId)->whereNull('admin_user')->update(['admin_user' => ServerStack::ADMIN_USER]);
        SshKeys::applied($nodeId);
    }

    /** @return array{password: string, hash: string} */
    private function make(string $organizationId, string $nodeId): array
    {
        $groups = [];
        for ($g = 0; $g < 5; $g++) {
            $word = '';
            for ($i = 0; $i < 5; $i++) {
                $word .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $word;
        }
        $password = implode('-', $groups);
        $creds = ['password' => $password, 'hash' => crypt($password, '$6$rounds=5000$'.bin2hex(random_bytes(8)).'$')];
        $this->vault->put($organizationId, self::secret($nodeId), (string) json_encode($creds));

        return $creds;
    }

    private static function secret(string $nodeId): string
    {
        return "node:{$nodeId}:admin";
    }
}
