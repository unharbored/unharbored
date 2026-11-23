<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

/*
 * A heartbeat the status page can read.
 *
 * This is how the platform proves its scheduler works. A cron entry that was
 * never installed, or one pinned to a server that the autoscaler has since
 * replaced, produces an application that looks perfectly healthy while
 * nothing scheduled ever runs. Writing a timestamp somewhere visible turns
 * that silence into something you can see.
 *
 * withoutOverlapping matters once more than one node runs the scheduler:
 * every node fires the cron, and the lock elects one to actually do the work.
 */
Schedule::call(function (): void {
    $path = storage_path('app/scheduler-heartbeat.txt');

    @mkdir(dirname($path), 0o755, true);
    file_put_contents($path, now()->toIso8601String());
})
    ->name('deploy-heartbeat')
    // name() must come before withoutOverlapping(), which needs something to
    // key its lock on. Laravel throws rather than silently not locking.
    ->withoutOverlapping()
    ->everyMinute();
