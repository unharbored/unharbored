<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** metrics:prune — daily: server samples are kept two weeks, log lines one. */
final class PruneMetrics extends Command
{
    public const KEEP_DAYS = 14;

    public const LOG_DAYS = 7;

    protected $signature = 'metrics:prune';

    protected $description = 'Delete server metric samples older than two weeks and log lines older than one';

    public function handle(): int
    {
        $deleted = DB::table('node_metrics')->where('collected_at', '<', now()->subDays(self::KEEP_DAYS))->delete();
        $lines = DB::table('log_lines')->where('logged_at', '<', now()->subDays(self::LOG_DAYS))->delete();
        $this->line("{$deleted} samples and {$lines} log lines deleted");

        return self::SUCCESS;
    }
}
