<?php

declare(strict_types=1);

namespace App\Lib\Containers;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Roles;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Present;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Docker on a server (docs/specs/templates.md section 5): preparing it, cleaning up layers, and the cached read behind
 * the Containers view. Preparing and cleaning up need `infrastructure` (Manager and up); reading needs only `view`.
 * Everything is scoped by the team's id: another team's server is "not found".
 *
 * Cleanup runs exactly two commands, `docker image prune -f` (dangling images) and `docker builder prune -f`. It never
 * touches volumes, containers, or an image any container uses.
 */
final class Containers
{
    /** A read newer than this is served again instead of running the commands once more. */
    private const READ_MIN_INTERVAL_SECONDS = 10;

    public const PRUNE_COMMANDS = [['image', 'prune', '-f'], ['builder', 'prune', '-f']];

    public function __construct(
        private readonly OperationRunner $runner,
        private readonly GatewayClient $gateway,
        private readonly AuditLog $audit,
    ) {}

    // ---- the gated actions -------------------------------------------------

    /**
     * Queues the preparation. A server with under about 1.5 GB of memory is refused unless the caller passes
     * $acknowledgeSmall: the daemon uses roughly 100 MB (an estimate), which is a real share of a small server.
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException with text for the person
     */
    public function prepare(string $organizationId, string $nodeId, string $actor, string $role, bool $acknowledgeSmall = false, bool $dispatch = true, string $trigger = 'cli'): int
    {
        $this->authorize($role);
        $node = $this->node($organizationId, $nodeId);
        $this->assertOnline($nodeId);

        $m = DB::table('node_metrics')->where('node_id', $nodeId)->orderByDesc('collected_at')->first();
        if ($m === null) {
            throw new InvalidArgumentException("The server {$nodeId} has not reported its memory yet, so whether Docker fits cannot be checked.");
        }
        if ((int) $m->mem_total < Docker::SMALL_BYTES && ! $acknowledgeSmall) {
            throw new InvalidArgumentException(sprintf('This server has %s of memory. Docker uses roughly 100 MB of it before any container runs (an estimate). Confirm that you accept this, or use a bigger server.', self::human((int) $m->mem_total)));
        }

        return $this->exclusive($organizationId, $nodeId, fn () => $this->runner->queueServerContainers($nodeId, (bool) $acknowledgeSmall && (int) $m->mem_total < Docker::SMALL_BYTES, $trigger, $actor, $dispatch));
    }

    /** @throws AuthorizationException @throws InvalidArgumentException */
    public function cleanup(string $organizationId, string $nodeId, string $actor, string $role, bool $dispatch = true, string $trigger = 'cli'): int
    {
        $this->authorize($role);
        $node = $this->node($organizationId, $nodeId);
        if ($node->containers_ready_at === null) {
            throw new InvalidArgumentException("The server {$nodeId} is not prepared for containers.");
        }
        $this->assertOnline($nodeId);

        return $this->exclusive($organizationId, $nodeId, fn () => $this->runner->queueContainerCleanup($nodeId, $trigger, $actor, $dispatch));
    }

    /**
     * Reads what runs and the disk Docker uses, stores it on the node and returns it. Called by the Refresh button and after
     * an operation, never by a page load. A read less than ten seconds old is returned as it is.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException
     */
    public function refresh(string $organizationId, string $nodeId): array
    {
        $node = $this->node($organizationId, $nodeId);
        if ($node->containers_ready_at === null) {
            throw new InvalidArgumentException("The server {$nodeId} is not prepared for containers.");
        }
        if ($node->containers_read_at !== null && now()->diffInSeconds($node->containers_read_at, true) < self::READ_MIN_INTERVAL_SECONDS) {
            return $this->status($organizationId, $nodeId);
        }
        $this->assertOnline($nodeId);
        $this->read($organizationId, $nodeId);

        return $this->status($organizationId, $nodeId);
    }

    /** What the Containers view shows: the last read, with its age. No command is run. @return array<string, mixed> */
    public function status(string $organizationId, string $nodeId): array
    {
        $node = $this->node($organizationId, $nodeId);
        $info = json_decode((string) $node->containers_info, true) ?: ['containers' => [], 'df' => []];

        return [
            'prepared' => $node->containers_ready_at !== null,
            'prepared_at' => $node->containers_ready_at,
            'docker_version' => $node->docker_version,
            'read_at' => $node->containers_read_at,
            'read_ago' => $node->containers_read_at ? Present::ago($node->containers_read_at) : null,
            'containers' => $info['containers'] ?? [],
            'df' => $info['df'] ?? [],
        ];
    }

    // ---- the operations' bodies ----------------------------------------------

    /**
     * check, install, configure, verify. Idempotent: running it on a prepared server changes nothing it need not.
     *
     * @param  Closure(array<string, mixed>, int): void  $apply
     */
    public function runPrepare(object $operation, Closure $stage, Closure $log, Closure $apply): void
    {
        $nodeId = (string) $operation->node_id;
        $meta = json_decode((string) $operation->meta, true) ?: [];

        $stage('check');
        $this->assertOnline($nodeId);
        $apply(Docker::check(), 120);
        $log('check', 'Ubuntu or Debian, no conflicting Docker package');

        $stage('install');
        $apply(Docker::install(), 1200);
        $log('install', 'Docker Engine from download.docker.com, key fingerprint '.Docker::KEY_FINGERPRINT.' verified');

        $stage('configure');
        $apply(Docker::configure(), 300);
        $log('configure', 'live-restore on, json-file logs rotate at 10 MB x 3, no TCP socket, Docker\'s own firewall rules left on');

        $stage('verify');
        $apply(Docker::verify(), 120);
        $report = $this->read($operation->organization_id, $nodeId, markReady: true);
        $log('verify', 'Docker '.($report['version'] ?? 'version unknown').' is running');

        $this->audit->record($operation->organization_id, Actor::user((string) $operation->actor, (string) $operation->actor), 'server.containers_prepared',
            resourceType: 'node', resourceId: $nodeId, metadata: ['docker_version' => $report['version'], 'small_server_acknowledged' => (bool) ($meta['acknowledged_small'] ?? false)]);
    }

    /** The two prune commands and nothing else. */
    public function runCleanup(object $operation, Closure $stage, Closure $log): void
    {
        $nodeId = (string) $operation->node_id;
        $stage('prune');
        $reclaimed = [];
        foreach (self::PRUNE_COMMANDS as $args) {
            $out = $this->exec($operation->organization_id, $nodeId, 'docker', $args, 'docker '.implode(' ', $args));
            preg_match('/Total reclaimed space:\s*(.+)/', $out, $m);
            $reclaimed[] = trim($m[1] ?? '0B');
            $log('prune', 'docker '.implode(' ', $args).': reclaimed '.end($reclaimed));
        }
        $this->read($operation->organization_id, $nodeId);
        $this->audit->record($operation->organization_id, Actor::user((string) $operation->actor, (string) $operation->actor), 'server.containers_cleaned',
            resourceType: 'node', resourceId: $nodeId, metadata: ['images_reclaimed' => $reclaimed[0], 'build_cache_reclaimed' => $reclaimed[1]]);
    }

    // ---- pieces ------------------------------------------------------------

    /** @return array{version: ?string, containers: list<array<string, mixed>>, df: list<array<string, string>>} */
    private function read(string $organizationId, string $nodeId, bool $markReady = false): array
    {
        $out = $this->exec($organizationId, $nodeId, 'sh', ['-c', Docker::readScript()], 'Read what Docker runs', 60);
        $report = Docker::parseReport($out);
        $update = ['containers_info' => json_encode(['containers' => $report['containers'], 'df' => $report['df']]), 'containers_read_at' => now(), 'updated_at' => now()];
        if ($report['version'] !== null) {
            $update['docker_version'] = $report['version'];
        }
        if ($markReady) {
            $update['containers_ready_at'] = now();
            $update['docker_version'] ??= 'unknown';
        }
        DB::table('nodes')->where('node_id', $nodeId)->update($update);

        return $report;
    }

    /** @param list<string> $args */
    private function exec(string $organizationId, string $nodeId, string $name, array $args, string $reason, int $timeout = 300): string
    {
        $result = $this->gateway->run($organizationId, $nodeId, 'exec', ['name' => $name, 'args' => $args], timeoutSeconds: $timeout, waitSeconds: $timeout + 30, reason: $reason);
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException((string) ($result['error'] ?? "{$reason} failed."));
        }

        return (string) ($result['detail']['output'] ?? '');
    }

    private function assertOnline(string $nodeId): void
    {
        try {
            $online = $this->gateway->isConnected($nodeId);
        } catch (Throwable) {
            $online = false;
        }
        if (! $online) {
            throw new InvalidArgumentException("The server {$nodeId} is not online. Wait for it to reconnect.");
        }
    }

    private function node(string $organizationId, string $nodeId): object
    {
        return DB::table('nodes')->where('organization_id', $organizationId)->where('node_id', $nodeId)->first()
            ?? throw new InvalidArgumentException("There is no server {$nodeId} in this team.");
    }

    private function authorize(string $role): void
    {
        if (! Roles::can($role, 'infrastructure')) {
            throw new AuthorizationException('Your role cannot do this; it needs the infrastructure permission.');
        }
    }

    /** One preparation or cleanup per server at a time. */
    private function exclusive(string $organizationId, string $nodeId, Closure $do): mixed
    {
        $busy = new InvalidArgumentException("Another container operation is already running on {$nodeId}. Wait for it to finish.");
        try {
            return Cache::lock("containers-operation:{$organizationId}:{$nodeId}", 15)->block(5, function () use ($organizationId, $nodeId, $do, $busy) {
                if (DB::table('operations')->where('organization_id', $organizationId)->where('node_id', $nodeId)->whereIn('kind', ['server_containers', 'container_cleanup'])->whereIn('status', ['queued', 'running'])->exists()) {
                    throw $busy;
                }

                return $do();
            });
        } catch (LockTimeoutException) {
            throw $busy;
        }
    }

    private static function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : round($bytes / 1024 ** 2).' MB';
    }
}
