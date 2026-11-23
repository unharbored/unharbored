<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Git\GitSources;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Where the code comes from and when a push deploys it. */
final class SiteSourceController extends SiteSettingsBase
{
    public function push(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $on = $request->boolean('on');
        app(Sites::class)->setGitSource($row, $row->git_source, $on);

        return $this->saved('Push to deploy '.($on ? 'turned on.' : 'turned off.'));
    }

    /** Switches cloning to the GitHub App, after proving it can read the repository. */
    public function useGitHubApp(int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        try {
            app(GitHubApp::class)->installationToken($row->organization_id, GitHubApp::fullName($row->repository));
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['source' => $e->getMessage()]);
        }
        app(Sites::class)->setGitSource($row, 'github_app', (bool) $row->push_to_deploy);

        return $this->saved('Now cloning through the GitHub App. The deploy key on GitHub can be removed.');
    }

    /** The repository's branches, default first, read the way the application clones it; an empty list when it cannot be read. */
    public function branches(int $site): JsonResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $list = $row->repository === null ? null : app(GitSources::class)->siteBranches($row, app(Sites::class)->deployKey($row));

        return response()->json(['branches' => $list ?? []]);
    }

    /** The folder of the repository the application is in. Takes effect on the next deploy. */
    public function root(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        try {
            app(Sites::class)->setRootDir($row, (string) $request->input('root', ''));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['root' => $e->getMessage()]);
        }

        return $this->saved('Saved. The next deploy builds and serves from this folder.');
    }

    /** Which branch is deployed. Checked against the repository when the repository can be read. */
    public function branch(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $branch = trim((string) $request->input('branch'));
        $existing = $row->repository === null ? null : app(GitSources::class)->siteBranches($row, app(Sites::class)->deployKey($row));
        if ($existing !== null && ! in_array($branch, $existing, true)) {
            throw ValidationException::withMessages(['branch' => "{$branch} is not a branch of this repository. It has: ".implode(', ', array_slice($existing, 0, 12)).'.']);
        }
        try {
            app(Sites::class)->setBranch($row, $branch);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['branch' => $e->getMessage()]);
        }

        return $this->saved("Deploying {$branch} from now on.".($existing === null ? ' The repository could not be read to check it, so make sure the branch exists.' : ''));
    }
}
