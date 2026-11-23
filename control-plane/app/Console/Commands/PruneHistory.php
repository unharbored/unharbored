<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * history:prune — daily: finished operations, resolved alerts and closed console
 * sessions older than 90 days. audit_records is never touched; it is the
 * record, and the database refuses to change it.
 *
 * Deleted in chunks so the SQLite write lock is never held for long.
 */
final class PruneHistory extends Command
{
    public const CHUNK = 1000;

    protected $signature = 'history:prune {--days=90 : Keep this many days} {--dry-run : Count what would go, delete nothing}';

    protected $description = 'Delete finished operations, resolved alerts and closed console sessions older than 90 days';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $cutoff = now()->subDays($days);
        $dry = (bool) $this->option('dry-run');

        // An operation still queued, running or waiting is never old enough to
        // go. Nothing reads an operation's id back from server_installs or
        // ssh_adoptions, and the operation page says "missing" for a gone one.
        $counts = [
            'operations' => $this->prune(DB::table('operations')->whereIn('status', ['succeeded', 'failed'])
                ->whereRaw('coalesce(finished_at, updated_at, created_at) < ?', [$cutoff->toDateTimeString()]), 'id', $dry),
            'alerts' => $this->prune(DB::table('alerts')->whereNotNull('resolved_at')->where('resolved_at', '<', $cutoff), 'id', $dry),
            'console sessions' => $this->prune(DB::table('console_sessions')->whereNotNull('closed_at')->where('closed_at', '<', $cutoff), 'id', $dry),
        ];

        $verb = $dry ? 'would delete' : 'deleted';
        $this->line($verb.' '.implode(', ', array_map(fn ($n, $what) => "{$n} {$what}", $counts, array_keys($counts))));

        return self::SUCCESS;
    }

    private function prune(Builder $rows, string $key, bool $dry): int
    {
        if ($dry) {
            return $rows->count();
        }
        $total = 0;
        do {
            $ids = (clone $rows)->limit(self::CHUNK)->pluck($key)->all();
            $total += $ids === [] ? 0 : DB::table($rows->from)->whereIn($key, $ids)->delete();
        } while (count($ids) === self::CHUNK);

        return $total;
    }
}
