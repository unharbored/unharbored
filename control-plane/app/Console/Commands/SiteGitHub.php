<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Sites;
use Illuminate\Console\Command;
use Throwable;

/**
 * site:github — fetch a site's code through the GitHub App, and optionally
 * deploy on push.
 */
final class SiteGitHub extends Command
{
    protected $signature = 'site:github {node} {name}
        {--push-to-deploy : Deploy when the branch is pushed}
        {--deploy-key : Go back to the SSH deploy key}';

    protected $description = 'Switch a site to the GitHub App for cloning, optionally with push-to-deploy';

    public function handle(Sites $sites, GitHubApp $github): int
    {
        $site = $sites->find((string) $this->argument('node'), (string) $this->argument('name'));
        if ($site === null) {
            $this->error(sprintf('No site %s on %s.', $this->argument('name'), $this->argument('node')));

            return self::FAILURE;
        }

        if ($this->option('deploy-key')) {
            $sites->setGitSource($site, 'deploy_key', false);
            $this->info("{$site->name} clones with its deploy key again; push-to-deploy is off.");

            return self::SUCCESS;
        }

        // Prove the app can read the repository before relying on it, so a
        // missing installation shows up now rather than mid-deploy.
        try {
            $github->installationToken($site->organization_id, GitHubApp::fullName($site->repository));
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $push = (bool) $this->option('push-to-deploy');
        $sites->setGitSource($site, 'github_app', $push);

        $this->info("{$site->name} now clones through the GitHub App.");
        $this->line($push ? "Pushes to {$site->branch} deploy it." : 'Push-to-deploy is off; pass --push-to-deploy to turn it on.');
        $this->line('Its deploy key on GitHub is no longer used and can be removed.');

        return self::SUCCESS;
    }
}
