<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Alerts\Alerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** alerts:check — every minute: open what started, clear what stopped, tell someone. */
final class CheckAlerts extends Command
{
    protected $signature = 'alerts:check';

    protected $description = 'Check servers and operations, and send alerts that opened or cleared';

    public function handle(Alerts $alerts): int
    {
        foreach (DB::table('organizations')->pluck('id') as $org) {
            foreach ($alerts->check((string) $org) as $line) {
                $this->line("{$org}: {$line}");
            }
        }

        return self::SUCCESS;
    }
}
