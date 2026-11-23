<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\GitHub\GitHubApp;
use App\Http\Controllers\GitHubController;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * github:setup — print a one-time link that creates the organisation's
 * GitHub App through GitHub's manifest flow.
 */
final class GitHubSetup extends Command
{
    protected $signature = 'github:setup {organization}';

    protected $description = 'Create the organisation GitHub App: prints a one-time link to open in a browser';

    public function handle(GitHubApp $github): int
    {
        $organizationId = (string) $this->argument('organization');

        if (! DB::table('organizations')->where('id', $organizationId)->exists()) {
            $this->error("No organisation {$organizationId}.");

            return self::FAILURE;
        }

        if (($app = $github->find($organizationId)) !== null) {
            $this->warn("{$organizationId} already has {$app->slug}. Continuing replaces it.");
        }

        if (! str_starts_with((string) config('app.url'), 'https://')) {
            $this->error('APP_URL must be a publicly trusted https URL; GitHub delivers webhooks there.');

            return self::FAILURE;
        }

        $state = bin2hex(random_bytes(24));
        Cache::put(GitHubController::stateKey($state), $organizationId, GitHubController::STATE_TTL_SECONDS);

        $this->line('Open this in a browser signed in to GitHub. It works once, for 30 minutes:');
        $this->newLine();
        $this->line(rtrim((string) config('app.url'), '/').'/github/setup?state='.$state);

        return self::SUCCESS;
    }
}
