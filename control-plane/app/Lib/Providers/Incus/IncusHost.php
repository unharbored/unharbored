<?php

declare(strict_types=1);

namespace App\Lib\Providers\Incus;

use App\Lib\Audit\Actor;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Resources\ResourceManager;
use App\Lib\Servers\NodeRemoval;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * An Incus host Unharbored set up itself, kept like a database cluster: a resource the team creates and destroys,
 * which servers live in and nothing else runs on. The machine is a node with the role "incus-host": it runs our agent
 * and no server stack, applications or services.
 *
 * Installing runs the same setup script people paste (resources/scripts/incus-host.sh) through the agent, takes the
 * connection string it prints, and connects with it, so nobody pastes anything. Removing it is refused while any
 * server lives on it; the IPv4 ingress is part of the host and goes with it.
 */
final class IncusHost
{
    public const ROLE = 'incus-host';

    private const SCRIPT = '/usr/local/sbin/unharbored-incus-host';

    public function __construct(
        private readonly GatewayClient $gateway,
        private readonly IncusProvider $incus,
        private readonly CredentialStore $credentials,
    ) {}

    /** The organisation's managed host node, if it has one. */
    public static function node(string $organizationId): ?object
    {
        return DB::table('nodes')->where('organization_id', $organizationId)->where('role', self::ROLE)->first();
    }

    /** Whether the node is an Incus host or its ingress: machines nothing of the team's may be put on. */
    public static function reserved(?string $role): bool
    {
        return in_array($role, [self::ROLE, 'ingress'], true);
    }

    /**
     * Servers still living on the host, by name. The ingress is not one: it belongs to the host.
     *
     * @return list<string>
     */
    public static function servers(string $organizationId): array
    {
        return DB::table('resources')->where('organization_id', $organizationId)->where('provider', IncusProvider::PROVIDER)
            ->where('type', 'server')->where('state', '!=', 'deleted')->where('slug', '!=', Ingress::SLUG)
            ->orderBy('slug')->pluck('slug')->all();
    }

    /** Refuses a new host while the team already has an Incus connection: one host per organisation for now. */
    public function assertCanAdd(string $organizationId): void
    {
        if ($this->credentials->has($organizationId, IncusProvider::PROVIDER)) {
            throw new InvalidArgumentException('This team already has an Incus host. One host per team for now: remove the current one first.');
        }
    }

    /** Refuses removing the host while servers live on it. */
    public static function assertEmpty(string $organizationId): void
    {
        $servers = self::servers($organizationId);
        if ($servers !== []) {
            $n = count($servers);
            throw new InvalidArgumentException(($n === 1 ? '1 server still lives' : "{$n} servers still live").' on this Incus host ('.implode(', ', $servers).'). Destroy '.($n === 1 ? 'it' : 'them').' first.');
        }
    }

    /**
     * Sets Incus up on the node and connects to it. Safe to run again: the script checks before each change.
     *
     * @param  Closure(string, string): void  $log
     */
    public function install(string $organizationId, string $nodeId, Closure $log, string $actor): void
    {
        $result = $this->gateway->run($organizationId, $nodeId, 'apply_recipe', ['spec' => ['name' => 'incus-host', 'steps' => [
            ['id' => 'incus-host-script', 'type' => 'file', 'path' => self::SCRIPT, 'content_b64' => base64_encode((string) file_get_contents(resource_path('scripts/incus-host.sh'))), 'mode' => '0755'],
        ]]], timeoutSeconds: 60, reason: 'Install Incus');
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException((string) ($result['error'] ?? 'The setup script could not be written.'));
        }

        $result = $this->gateway->run($organizationId, $nodeId, 'exec', ['name' => 'bash', 'args' => [self::SCRIPT]],
            timeoutSeconds: 900, reason: 'Install Incus');
        $output = (string) ($result['detail']['output'] ?? '');
        $connection = null;
        foreach (preg_split('/\R/', (string) preg_replace('/\e\[[0-9;]*m/', '', $output)) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, IncusProvider::PREFIX)) {
                $connection = $line;
            } elseif (preg_match('/^[✓✗] (.+)$/u', $line, $m)) {
                $log('incus', $m[1]);
            }
        }
        if (($result['status'] ?? null) !== 'succeeded' || $connection === null) {
            throw new RuntimeException('Setting up Incus failed: '.((string) ($result['error'] ?? '') ?: 'the script printed no connection string').'.');
        }

        $this->incus->connect($organizationId, $connection, Actor::system($actor));
        DB::table('nodes')->where('node_id', $nodeId)->update(['role' => self::ROLE, 'updated_at' => now()]);
        $log('incus', 'connected: Unharbored can create servers on this host; the IPv4 ingress is being created');
    }

    /**
     * Everything Unharbored keeps on and about the host, in order: the ingress, our key in the host's trust list, the
     * connection. The machine itself is left to the caller (destroyed, or its agent removed). Refuses while servers
     * live on the host.
     *
     * @param  Closure(string, string): void  $log
     */
    public function teardown(string $organizationId, ?string $hostNodeId, Closure $log, string $actor): void
    {
        self::assertEmpty($organizationId);
        $system = Actor::system($actor);

        $ingress = app(ResourceManager::class)->find($organizationId, 'server', Ingress::SLUG);
        if ($ingress !== null && $ingress->externalId !== null && $ingress->state->value !== 'deleted') {
            app(ServerProvisioner::class)->destroy($ingress, $system);
            $log('ingress', 'the IPv4 ingress is destroyed');
        }
        if (($node = DB::table('nodes')->where('node_id', Ingress::nodeId($organizationId))->first()) !== null) {
            try {
                app(NodeRemoval::class)->revoke($node, 'destroyed with its Incus host');
            } catch (Throwable $e) {
                $log('ingress', 'its certificate revocation will apply at the next gateway sync: '.$e->getMessage());
            }
        }

        if ($this->credentials->has($organizationId, IncusProvider::PROVIDER)) {
            if ($hostNodeId !== null && $this->gateway->isConnected($hostNodeId)) {
                $secret = $this->credentials->secret($organizationId, IncusProvider::PROVIDER, 'Removing the Incus host', $system);
                $fingerprint = (string) openssl_x509_fingerprint((string) $secret['client_cert'], 'sha256');
                try {
                    $this->gateway->run($organizationId, $hostNodeId, 'exec', ['name' => 'incus', 'args' => ['config', 'trust', 'remove', $fingerprint]], timeoutSeconds: 30, reason: 'Remove the Incus host');
                    $log('incus', "Unharbored's key is removed from the host's trust list");
                } catch (Throwable $e) {
                    $log('incus', "Unharbored's key could not be removed from the host's trust list: {$e->getMessage()}");
                }
            }
            $this->credentials->forgetProvider($organizationId, IncusProvider::PROVIDER, $system);
            $log('incus', 'the connection is deleted');
        }
    }
}
