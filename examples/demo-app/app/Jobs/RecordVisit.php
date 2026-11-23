<?php

declare(strict_types=1);

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

/**
 * Records a page view, asynchronously.
 *
 * Exists so the platform has something to prove its queue workers with. If
 * supervisor is misconfigured, or workers were not restarted after a deploy
 * and are still running the previous release, the visit counter stops moving
 * while the page itself keeps working. That is exactly the kind of
 * half-broken deploy that is worth being able to see at a glance.
 */
final class RecordVisit implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $path,
        private readonly string $release,
    ) {}

    public function handle(): void
    {
        DB::table('visits')->insert([
            'path' => $this->path,
            // The release recorded here is the one the *worker* is running,
            // which is not necessarily the one that served the request.
            // A mismatch means queue:restart did not happen.
            'release' => $this->release,
            'created_at' => now(),
        ]);
    }
}
