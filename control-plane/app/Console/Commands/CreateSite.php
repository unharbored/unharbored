<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Sites\Sites;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * site:create — record a site and print the deploy key to add on GitHub.
 */
final class CreateSite extends Command
{
    protected $signature = 'site:create
        {node : Node id, e.g. web-1.org-1}
        {name : Directory name, lowercase letters, digits and dashes}
        {domain : Hostname the site answers on}
        {repository : git@host:owner/repo.git, ssh://git@host:port/owner/repo.git, or https://host/owner/repo.git for a public repository}
        {--branch=main : Branch to deploy}
        {--fingerprint= : The SSH host key fingerprint you expect (SHA256:…), for a host that is not GitHub}';

    protected $description = 'Create a site and mint its deploy key (a public https repository needs none)';

    public function handle(Sites $sites): int
    {
        $nodeId = (string) $this->argument('node');
        $node = DB::table('nodes')->where('node_id', $nodeId)->first();

        if ($node === null) {
            $this->error("No node {$nodeId}.");

            return self::FAILURE;
        }

        $hostKey = null;
        try {
            $repo = \App\Lib\Git\GitRepo::parse((string) $this->argument('repository'));
            if ($repo->viaSsh && ! $repo->isGitHub()) {
                $key = app(\App\Lib\Git\GitRemote::class)->hostKey($repo);
                $this->line("{$repo->host} shows SSH host key {$key['fingerprint']}");
                if ($this->option('fingerprint') && $this->option('fingerprint') !== $key['fingerprint']) {
                    $this->error("That is not the {$this->option('fingerprint')} you expected. Nothing was created.");

                    return self::FAILURE;
                }
                $hostKey = $key['line'];
            }
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        try {
            $site = $sites->create(
                organizationId: $node->organization_id,
                nodeId: $nodeId,
                name: (string) $this->argument('name'),
                domain: (string) $this->argument('domain'),
                repository: (string) $this->argument('repository'),
                branch: (string) $this->option('branch'),
                gitHostKey: $hostKey,
            );
            if (str_starts_with($site->repository, 'https://')) {
                $this->info('Public repository: cloned without a key.');
            }
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Site {$site->name} created on {$nodeId}.");
        if ($site->git_source === 'https') {
            $this->line("Then: php artisan site:deploy {$nodeId} {$site->name}");

            return self::SUCCESS;
        }
        $this->newLine();
        $this->line('Add this as a read-only deploy key on the repository');
        $this->line('(GitHub: Settings -> Deploy keys -> Add deploy key, leave write access off):');
        $this->newLine();
        $this->line($site->deploy_key_public);
        $this->newLine();
        $this->line("Then: php artisan site:deploy {$nodeId} {$site->name}");

        return self::SUCCESS;
    }
}
