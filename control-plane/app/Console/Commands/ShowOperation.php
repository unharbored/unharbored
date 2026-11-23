<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Prints an operation's status and log; with --wait, once it has finished. */
final class ShowOperation extends Command
{
    protected $signature = 'operation:show {id? : Defaults to the newest} {--wait : Wait until it succeeds or fails} {--tail=0 : Only the last N log lines}';

    protected $description = 'Show an operation and its log';

    public function handle(): int
    {
        $id = $this->argument('id') ?? DB::table('operations')->max('id');
        $deadline = time() + 1800;
        do {
            $op = DB::table('operations')->find($id);
            if ($op === null) {
                $this->error("No operation {$id}.");

                return self::FAILURE;
            }
            $done = in_array($op->status, ['succeeded', 'failed'], true);
            if (! $done && $this->option('wait')) {
                sleep(3);
            }
        } while (! $done && $this->option('wait') && time() < $deadline);

        $this->line("{$op->id} {$op->kind} {$op->status} · {$op->title}");
        $lines = array_values(array_filter(explode("\n", (string) $op->log)));
        $tail = (int) $this->option('tail');
        foreach ($tail > 0 ? array_slice($lines, -$tail) : $lines as $line) {
            $this->line($line);
        }

        return $op->status === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
