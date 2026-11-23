<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Servers\ServerStack;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;

/**
 * Queue workers that follow the queue: more while jobs wait, down to the
 * fewest (zero allowed) once it has been empty a while.
 *
 * Supervisor is configured with the most a server may run; scaling starts
 * and stops individual processes in that group. A stopped worker finishes
 * its job first, and the others carry on untouched, which a change of
 * numprocs would not allow: supervisor restarts the whole group for that.
 *
 * Checked once a minute, so a queue scaled to zero waits up to a minute for
 * its first worker.
 */
final class WorkerScaling
{
    /** Waiting jobs one worker is expected to keep up with. */
    public const JOBS_PER_WORKER = 10;

    /** How long the queue stays empty before workers go back to the fewest. */
    public const IDLE_MINUTES = 5;

    public function __construct(
        private readonly GatewayClient $gateway,
        private readonly Sites $sites,
        private readonly AuditLog $audit,
    ) {}

    /** Scale between $min and $max workers per server. Takes effect on the next deploy. */
    public function enable(stdClass $site, int $min, int $max): void
    {
        if ($min < 0 || $max < 1 || $min > $max || $max > 20) {
            throw new InvalidArgumentException('Fewest must be 0 or more, most between 1 and 20, and fewest no more than most.');
        }
        $now = max($min, min($max, (int) $site->queue_workers));
        DB::table('sites')->where('id', $site->id)->update([
            'queue_workers_min' => $min, 'queue_workers_max' => $max, 'queue_workers' => $now, 'queue_idle_since' => null, 'updated_at' => now(),
        ]);
        $this->sites->markPending($site);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.worker_scaling_set', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", metadata: ['min' => $min, 'max' => $max]);
    }

    /** Back to a fixed count. Takes effect on the next deploy. */
    public function disable(stdClass $site, int $workers): void
    {
        DB::table('sites')->where('id', $site->id)->update(['queue_workers_min' => null, 'queue_workers_max' => null, 'queue_idle_since' => null, 'updated_at' => now()]);
        $this->sites->setWorkers($site, $workers);
    }

    /**
     * How many workers each server should run.
     *
     * Grows as soon as jobs wait and never shrinks while any do, so a busy
     * queue does not flap; drops to the fewest only after IDLE_MINUTES of
     * an empty queue.
     */
    public static function target(int $current, int $min, int $max, int $depth, int $servers, ?CarbonInterface $idleSince, CarbonInterface $now): int
    {
        if ($depth > 0) {
            $wanted = (int) ceil($depth / (self::JOBS_PER_WORKER * max(1, $servers)));

            return max(max(1, $min), min($max, max($current, $wanted)));
        }
        if ($idleSince !== null && $idleSince->lte($now->copy()->subMinutes(self::IDLE_MINUTES))) {
            return $min;
        }

        return max($min, min($max, $current));
    }

    /**
     * One check for one site: measure, decide, apply.
     *
     * @return string|null what changed, or null if nothing did
     */
    public function tick(stdClass $site): ?string
    {
        $min = (int) $site->queue_workers_min;
        $max = (int) $site->queue_workers_max;
        $current = (int) $site->queue_workers;
        $depth = $this->depth($site);
        $idleSince = $depth > 0 ? null : ($site->queue_idle_since !== null ? \Illuminate\Support\Carbon::parse($site->queue_idle_since) : now());
        $nodes = Sites::nodes($site);
        $target = self::target($current, $min, $max, $depth, count($nodes), $idleSince, now());

        DB::table('sites')->where('id', $site->id)->update(['queue_idle_since' => $idleSince]);
        if ($target === $current) {
            return null;
        }
        foreach ($nodes as $node) {
            $this->gateway->run($site->organization_id, $node, 'exec', ['name' => 'sh', 'args' => ['-c', self::command($site->name, $target, $max)]],
                reason: "Scaling {$site->name}'s queue workers to {$target}");
        }
        DB::table('sites')->where('id', $site->id)->update(['queue_workers' => $target]);
        $this->audit->record($site->organization_id, Actor::system('control-plane'), 'site.workers_scaled', resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}", reason: $depth > 0 ? "{$depth} jobs waiting" : 'Queue empty for '.self::IDLE_MINUTES.' minutes',
            metadata: ['from' => $current, 'to' => $target, 'depth' => $depth, 'servers' => count($nodes)]);

        return "{$current} → {$target} workers per server, {$depth} waiting";
    }

    /**
     * Starts the first $running workers of the group and stops the rest.
     * Stops run detached: supervisor waits for each worker's job to finish,
     * which may be minutes.
     */
    public static function command(string $site, int $running, int $max): string
    {
        $name = fn (int $i) => sprintf('cm-%1$s-worker:cm-%1$s-worker_%2$02d', $site, $i);
        $start = array_map($name, $running > 0 ? range(0, $running - 1) : []);
        $stop = array_map($name, $running < $max ? range($running, $max - 1) : []);
        $sh = [];
        if ($start !== []) {
            $sh[] = 'supervisorctl start '.implode(' ', $start).' >/dev/null 2>&1';
        }
        if ($stop !== []) {
            $sh[] = 'setsid -f supervisorctl stop '.implode(' ', $stop).' >/dev/null 2>&1';
        }
        $sh[] = 'true';

        return implode('; ', $sh);
    }

    /**
     * Jobs waiting on the site's default queue, asked of the app on its
     * primary. Without --json if the app's Laravel does not know it.
     */
    public function depth(stdClass $site): int
    {
        $ask = fn (array $flags) => $this->gateway->run($site->organization_id, $site->node_id, 'exec', [
            'name' => 'runuser',
            'args' => ['-u', ServerStack::DEPLOY_USER, '--', ServerStack::phpBinary(Sites::phpVersion($site)), Sites::current($site).'/artisan', 'queue:monitor', 'default', ...$flags],
        ], reason: "Measuring {$site->name}'s queue");
        $result = $ask(['--json']);
        if (($result['status'] ?? null) !== 'succeeded') {
            $result = $ask([]);
        }
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException("Could not measure {$site->name}'s queue: ".($result['error'] ?? 'no detail'));
        }

        return self::parseDepth((string) ($result['detail']['output'] ?? ''));
    }

    /** queue:monitor's JSON, or its table on Laravel versions without --json. */
    public static function parseDepth(string $output): int
    {
        $json = json_decode(trim($output), true);
        if (is_array($json)) {
            return array_sum(array_map(fn ($q) => (int) ($q['size'] ?? 0), $json));
        }
        if (preg_match('/\]\s+default\s.*?\[(\d+)\]/', $output, $m)) {
            return (int) $m[1];
        }

        throw new RuntimeException('queue:monitor said nothing recognisable: '.substr(trim($output), 0, 200));
    }
}
