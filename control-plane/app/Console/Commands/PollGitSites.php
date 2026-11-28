<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Git\GitRemote;
use App\Lib\Git\GitRepo;
use App\Lib\Operations\OperationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every five minutes: an application cloned from a public repository it does
 * not own cannot receive that repository's webhooks, so its branch is looked
 * at instead, and a new commit is deployed. The first look only records where
 * the branch is: what was deployed is already the live release.
 */
final class PollGitSites extends Command
{
    protected $signature = 'sites:poll-git';

    protected $description = 'Deploy applications from public repositories when their branch has a new commit';

    public function handle(GitRemote $remote, OperationRunner $runner): int
    {
        $failed = false;
        foreach (DB::table('sites')->whereNull('archived_at')->where('git_source', 'https')->where('push_to_deploy', true)->whereNotNull('current_release')->whereNull('parent_site_id')->get() as $site) {
            try {
                $sha = $remote->publicRefs(GitRepo::parse($site->repository))['shas'][$site->branch] ?? null;
                if ($sha === null) {
                    $this->warn("{$site->name}: {$site->branch} is not visible in {$site->repository}");

                    continue;
                }
                if ($site->git_seen_sha !== null && $site->git_seen_sha !== $sha) {
                    $this->line("{$site->name}: ".substr($sha, 0, 12).' is new, operation '.$runner->queueDeploy((int) $site->id, 'poll', 'git', substr($sha, 0, 12)));
                }
                if ($site->git_seen_sha !== $sha) {
                    DB::table('sites')->where('id', $site->id)->update(['git_seen_sha' => $sha, 'updated_at' => now()]);
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$site->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
