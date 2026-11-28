<?php

declare(strict_types=1);

namespace App\Lib\Servers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CloudInit;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Operations\OperationRunner;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Bring your own server: a one-time install command for a machine the
 * platform did not create.
 *
 * issue() records a link valid for fifteen minutes and starts an operation
 * that follows the server from the command being run to the stack being
 * installed. script() serves the link once. The enrollment token is minted
 * only then, so nothing usable sits in the database waiting to be fetched.
 */
final class ServerInstall
{
    public const TTL_MINUTES = 15;

    public function __construct(
        private readonly EnrollmentService $enrollment,
        private readonly CloudInit $cloudInit,
        private readonly OperationRunner $runner,
        private readonly AuditLog $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $meta  what the operation that follows the install is for, when it is not the web stack (a database server)
     * @return array{command: string, operation: int, expires_at: string}
     */
    public function issue(string $organizationId, string $name, string $baseUrl, string $actor, array $meta = []): array
    {
        if (! preg_match('/^[a-z0-9][a-z0-9-]{0,30}$/', $name)) {
            throw new InvalidArgumentException('Name a server with lowercase letters, digits and dashes.');
        }
        $nodeId = "{$name}.{$organizationId}";
        if (DB::table('nodes')->where('node_id', $nodeId)->exists()
            || DB::table('resources')->where('organization_id', $organizationId)->where('slug', $name)->exists()) {
            throw new InvalidArgumentException("There is already a server called {$name}.");
        }

        $secret = bin2hex(random_bytes(24));
        $expires = now()->addMinutes(self::TTL_MINUTES);
        $installId = DB::table('server_installs')->insertGetId([
            'organization_id' => $organizationId,
            'node_id' => $nodeId,
            'secret_hash' => hash('sha256', $secret),
            'expires_at' => $expires,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $operation = $this->runner->queueAdopt($organizationId, $nodeId, $installId, 'console', $actor, $meta);
        DB::table('server_installs')->where('id', $installId)->update(['operation_id' => $operation]);

        $this->audit->record($organizationId, Actor::system('control-plane'), 'server.install_link_issued',
            resourceType: 'node', resourceId: $nodeId, metadata: ['expires_at' => $expires->toIso8601String()]);

        return [
            'command' => sprintf('curl -fsSL %s/install/%s | sudo bash', rtrim($baseUrl, '/'), $secret),
            'operation' => $operation,
            'expires_at' => $expires->toIso8601String(),
        ];
    }

    /**
     * The install script for a link, once. Null for a link that is unknown,
     * expired or already used, which the route answers identically.
     */
    public function script(string $secret, string $fetchedBy): ?string
    {
        if (! preg_match('/^[0-9a-f]{48}$/', $secret)) {
            return null;
        }
        $install = DB::table('server_installs')->where('secret_hash', hash('sha256', $secret))->first();
        if ($install === null || $install->fetched_at !== null || now()->greaterThan($install->expires_at)) {
            return null;
        }

        // Claim it atomically: a second fetch racing this one gets nothing.
        $claimed = DB::table('server_installs')->where('id', $install->id)->whereNull('fetched_at')
            ->update(['fetched_at' => now(), 'fetched_by' => $fetchedBy, 'updated_at' => now()]);
        if ($claimed !== 1) {
            return null;
        }

        $token = $this->enrollment->issueToken($install->organization_id, $install->node_id, self::TTL_MINUTES * 60);
        $ca = $this->enrollment->serverTrustFor($install->organization_id);

        $this->audit->record($install->organization_id, Actor::system('control-plane'), 'server.install_script_fetched',
            resourceType: 'node', resourceId: $install->node_id, ipAddress: $fetchedBy);

        return $this->cloudInit->installScript($install->node_id, $token, $ca);
    }
}
