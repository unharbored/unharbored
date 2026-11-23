<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\GitHub\GitHubApp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Pushes GitHub failed to deliver, sent again. Run every few minutes: GitHub
 * itself never retries, so without this a push during a blip never deploys.
 */
final class RedeliverWebhooks extends Command
{
    protected $signature = 'github:redeliver-failed';

    protected $description = 'Ask GitHub to resend push webhooks it failed to deliver';

    public function handle(GitHubApp $github): int
    {
        $failed = false;
        foreach (DB::table('github_apps')->pluck('organization_id') as $org) {
            try {
                foreach ($github->redeliverFailed((string) $org) as $line) {
                    $this->line("{$org}: {$line}");
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$org}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
