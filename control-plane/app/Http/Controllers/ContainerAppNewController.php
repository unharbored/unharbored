<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Containers\ContainerApps;
use App\Lib\Git\GitSources;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\NewApplications;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A new application from a Dockerfile in a repository, or from a Docker image, in two steps: the
 * form, then a review of what will be made. Both run as one container on a server prepared for
 * containers. The registry token stays in the browser, is never rendered, and is sent only to check
 * and create.
 */
final class ContainerAppNewController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('create');
        $kind = $request->query('kind') === 'image' ? 'image' : 'dockerfile';

        return Inertia::render('ContainerAppNew', ['kind' => $kind, 'options' => $this->options($kind)]);
    }

    /** Repositories from the GitHub App, the team's servers with whether Docker is ready on each, and projects. */
    private function options(string $kind): array
    {
        $ready = DB::table('github_apps')->where('organization_id', Org::id())->exists();
        $repos = [];
        $error = null;
        if ($kind === 'dockerfile' && $ready) {
            try {
                $repos = Cache::remember('new-app:repos:'.Org::id(), 60, fn () => app(GitHubApp::class)->repositories(Org::id()));
            } catch (\Throwable $e) {
                $error = 'GitHub would not list repositories: '.$e->getMessage();
            }
        }
        $servers = [];
        foreach (NewApplications::servers(Org::id()) as $s) {
            $node = DB::table('nodes')->where('node_id', $s['id'])->first();
            $mem = (int) DB::table('node_metrics')->where('node_id', $s['id'])->orderByDesc('collected_at')->value('mem_total');
            $servers[] = $s + ['prepared' => $node?->containers_ready_at !== null, 'memory' => $mem > 0 ? ContainerApps::human($mem) : 'memory not reported', 'small' => $mem > 0 && $mem < ContainerApps::BUILD_MEMORY_BYTES];
        }

        return ['appReady' => $ready, 'repos' => $repos, 'error' => $error, 'servers' => $servers,
            'projects' => count($projects = Projects::listing(Org::id())) > 1 ? $projects : [], 'currentProject' => Projects::current(),
            'canPrepare' => auth()->user()->can('infrastructure')];
    }

    /** After preparing a server in another tab: the servers again, without losing what was typed. */
    public function optionsFor(Request $request): JsonResponse
    {
        Gate::authorize('create');

        return response()->json($this->options($request->query('kind') === 'image' ? 'image' : 'dockerfile'));
    }

    public function inspect(Request $request): JsonResponse
    {
        Gate::authorize('create');
        try {
            return response()->json(app(GitSources::class)->inspect(Org::id(), (string) $request->input('input')));
        } catch (\InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
    }

    public function branches(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $repo = (string) $request->input('repo');
        $git = (array) $request->input('git', []);
        if ($repo === '') {
            return response()->json([]);
        }
        try {
            if (($git['url'] ?? '') !== '') {
                return response()->json(app(GitSources::class)->branches(Org::id(), (string) $git['url'], (string) ($git['source'] ?? '')));
            }

            return response()->json(app(GitHubApp::class)->branches(Org::id(), $repo));
        } catch (\Throwable) {
            return response()->json([]);
        }
    }

    /**
     * What the branch holds: the Dockerfile (its path, the port of its EXPOSE line) and a Compose file
     * beside it, which is noticed and never run. `found` is false when there is no Dockerfile at that path.
     */
    public function detection(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $repo = (string) $request->input('repo');
        $branch = (string) $request->input('branch');
        $git = (array) $request->input('git', []);
        if ($repo === '' || $branch === '') {
            return response()->json([]);
        }
        $url = ($git['url'] ?? '') !== '' ? (string) $git['url'] : $repo;
        $source = ($git['url'] ?? '') !== '' ? (string) ($git['source'] ?? '') : 'github_app';
        $file = trim((string) $request->input('dockerfile')) ?: 'Dockerfile';
        try {
            $found = Cache::remember('wizard-docker:'.sha1("{$url}|{$source}|{$branch}|{$file}"), 30, fn () => app(GitSources::class)->dockerfile(Org::id(), $url, $source, $branch, $file) ?? false);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()]);
        }

        return response()->json($found === false ? ['found' => false, 'file' => $file] : ['found' => true] + $found);
    }

    /** The answers checked exactly as creating will check them; nothing is written. */
    public function check(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $plan = $this->plan((array) $request->input('input', []));

        return response()->json(['kind' => $plan['kind'], 'name' => $plan['name'], 'node' => strstr($plan['node'], '.', true) ?: $plan['node'], 'domain' => $plan['domain'],
            'repository' => $plan['repository'], 'branch' => $plan['branch'], 'image' => $plan['image_ref'], 'digest' => $plan['digest'],
            'registry' => $plan['registry'], 'settings' => $plan['settings'], 'warnings' => $plan['warnings'], 'steps' => $plan['steps']]);
    }

    public function create(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $plan = $this->plan((array) $request->input('input', []));

        return response()->json(['operation' => app(ContainerApps::class)->start(Org::id(), $plan, (string) $request->user()->email)['operation']]);
    }

    /** @return array<string, mixed> */
    private function plan(array $input): array
    {
        try {
            return app(ContainerApps::class)->plan(Org::id(), $input);
        } catch (\InvalidArgumentException $e) {
            [$field, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, $e->getMessage());
            throw ValidationException::withMessages([$field => $message]);
        }
    }
}
