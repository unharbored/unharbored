<?php

declare(strict_types=1);

namespace App\Lib\Operations;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;

/**
 * Runs one operation on the queue.
 *
 * Operations on the same site, or the same node, never overlap: two quick
 * pushes deploy one after the other, and a stack install does not race a
 * deploy on that server.
 */
final class RunOperation implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    /**
     * Waiting for another operation on the same node releases the job, and
     * a release counts as an attempt. So attempts are unlimited within an
     * hour, while a real exception still fails it at once. (run() records
     * failures itself and does not throw, so this is belt and braces.)
     */
    public int $maxExceptions = 1;

    public function retryUntil(): \DateTimeInterface
    {
        return now()->addHour();
    }

    public function __construct(public readonly int $operationId) {}

    /** @return list<object> */
    public function middleware(): array
    {
        $operation = DB::table('operations')->where('id', $this->operationId)->first(['site_id', 'node_id']);
        $key = $operation?->node_id !== null ? "node-{$operation->node_id}" : "operation-{$this->operationId}";

        return [(new WithoutOverlapping($key))->releaseAfter(10)->expireAfter(1500)];
    }

    public function handle(OperationRunner $runner): void
    {
        // Waiting on something outside the platform: check again shortly,
        // with the worker free for everything else in between.
        if ($runner->run($this->operationId) === 'waiting') {
            $this->release(10);
        }
    }
}
