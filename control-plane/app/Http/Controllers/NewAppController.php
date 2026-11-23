<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Git\AppDetector;
use App\Lib\Git\GitSources;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Providers;
use App\Lib\Providers\Shapes;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Runtimes;
use App\Lib\Sites\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A new application in three steps: where the code is, how it runs (single server or
 * autoscaling, fixed once made) and with what data, then a review with the cost.
 * The page keeps the answers; these endpoints look things up and check them.
 */
final class NewAppController extends Controller
{
    /** Keys Unharbored sets itself: left out of what .env.example suggests. */
    private const MANAGED = '/^(APP_(NAME|KEY|URL|ENV|DEBUG)|DB_\w+|REDIS_\w+|CACHE_\w+|SESSION_\w+|QUEUE_CONNECTION|FILESYSTEM_DISK|AWS_\w+|LOG_\w+|BROADCAST_CONNECTION|VITE_\w+)$/';

    public function show(): Response
    {
        Gate::authorize('create');

        return Inertia::render('NewApp', ['options' => $this->options('digitalocean')]);
    }

    /** Repositories, and the chosen provider's servers, clusters, regions, sizes and shapes. */
    /** @param  bool  $withRepositories  false when only the provider changed: the page already has the repositories, and listing them waits on GitHub. */
    public function options(string $provider, bool $withRepositories = true): array
    {
        $ready = DB::table('github_apps')->where('organization_id', Org::id())->exists();
        $repos = [];
        $error = null;
        if ($ready && $withRepositories) {
            try {
                $repos = Cache::remember('new-app:repos:'.Org::id(), 60, fn () => app(GitHubApp::class)->repositories(Org::id()));
            } catch (\Throwable $e) {
                $error = 'GitHub would not list repositories: '.$e->getMessage();
            }
        }

        $connected = app(Providers::class)->connected(Org::id());
        $chosen = $connected[$provider] ?? reset($connected);
        $d = Shapes::describe($chosen);

        return [
            'appReady' => $ready, 'repos' => $repos, 'error' => $error,
            'owners' => collect($repos)->map(fn ($r) => explode('/', $r['full_name'])[0])->unique()->sort()->values()->all(),
            'providers' => array_map(fn ($p) => ['id' => $p->id(), 'label' => $p->label()], array_values($connected)),
            'provider' => $d['id'], 'method' => $d['method'], 'shapes' => $d['shapes'], 'supplies' => $d['supplies'],
            'servers' => NewApplications::servers(Org::id(), $d['id']),
            'clusters' => $clusters = NewApplications::clusters(Org::id(), $d['id']),
            'hasDatabase' => collect($clusters)->contains(fn ($c) => in_array($c['engine'], ['pg', 'mysql'], true)),
            'clusterSizes' => array_map(fn ($c) => "{$c['label']} · ~\${$c['monthly']}/mo", DatabaseClusters::SIZES),
            'projects' => count($projects = Projects::listing(Org::id())) > 1 ? $projects : [],
            'currentProject' => Projects::current(),
            'runtimes' => Runtimes::choices(), 'presets' => Runtimes::PRESETS,
            'regions' => $d['regions'],
            'sizes' => array_map(Catalog::sizeLabel(...), $d['sizes']),
        ];
    }

    public function optionsFor(Request $request): JsonResponse
    {
        Gate::authorize('create');

        $options = $this->options((string) $request->query('provider', 'digitalocean'), $request->query('repositories') !== '0');

        return response()->json($request->query('repositories') === '0' ? array_diff_key($options, array_flip(['repos', 'owners', 'error'])) : $options);
    }

    /** A provider's starting region and sizes, for when the choice changes. */
    public function defaults(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $catalog = app(Providers::class)->get((string) $request->query('provider'))->catalog();
        $sizes = array_keys($catalog->sizes);

        return response()->json(['region' => $catalog->defaultRegion, 'size' => $sizes[1] ?? $sizes[0] ?? '', 'poolSize' => $sizes[2] ?? $sizes[0] ?? '']);
    }

    /** The sizes a region offers, labelled and priced, for the size list; all of them when the provider cannot say. */
    public function sizes(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $provider = app(Providers::class)->get((string) $request->query('provider'));
        $all = $provider->catalog()->sizes;
        $offered = method_exists($provider, 'availableSizes') ? $provider->availableSizes(Org::id(), (string) $request->query('region')) : null;
        $sizes = $offered === null ? $all : array_intersect_key($all, array_flip($offered));

        return response()->json(['sizes' => array_map(Catalog::sizeLabel(...), $sizes)]);
    }

    /** How a repository typed in is reached (the GitHub App, no login, or a deploy key), and what it holds. */
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
     * The applications in the repository and which one this is: the chosen folder, else the only
     * application found, else none yet when there are several (the person picks). `dir` is null for
     * that last case, a folder inside the repository otherwise ('' for the top).
     *
     * @return array{apps: list<array<string, mixed>>, listed: bool, dir: ?string, app: ?array<string, mixed>}
     */
    private function resolve(string $repo, string $branch, array $git, string $rootDir, bool $rootSet): array
    {
        $url = ($git['url'] ?? '') !== '' ? (string) $git['url'] : $repo;
        $source = ($git['url'] ?? '') !== '' ? (string) ($git['source'] ?? '') : 'github_app';
        $sources = app(GitSources::class);
        $scan = Cache::remember('wizard-scan:'.sha1("{$url}|{$source}|{$branch}"), 60, fn () => $sources->scan(Org::id(), $url, $source, $branch));
        $apps = $scan['apps'];
        if ($rootSet) {
            $dir = Sites::cleanRootDir($rootDir);
            $app = collect($apps)->firstWhere('dir', $dir) ?? $sources->inspectDir(Org::id(), $url, $source, $branch, $dir);
        } else {
            $app = AppDetector::suggest($apps);
            $dir = $app['dir'] ?? (count(array_filter($apps, fn ($a) => $a['runtime'] !== null)) > 1 ? null : '');
        }

        return ['apps' => $apps, 'listed' => $scan['listed'], 'dir' => $dir, 'app' => $app];
    }

    public function apps(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $repo = (string) $request->input('repo');
        $branch = (string) $request->input('branch');
        if ($repo === '' || $branch === '') {
            return response()->json([]);
        }
        try {
            return response()->json($this->resolve($repo, $branch, (array) $request->input('git', []), (string) $request->input('rootDir'), $request->boolean('rootSet')));
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage(), 'apps' => [], 'listed' => false, 'dir' => '', 'app' => null]);
        }
    }

    /** What the branch holds, and the variables its .env.example suggests. */
    public function detected(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $repo = (string) $request->input('repo');
        $branch = (string) $request->input('branch');
        $git = (array) $request->input('git', []);
        if ($repo === '' || $branch === '') {
            return response()->json([]);
        }
        try {
            $dir = $this->resolve($repo, $branch, $git, (string) $request->input('rootDir'), $request->boolean('rootSet'))['dir'] ?? '';
            $found = ($git['url'] ?? '') !== ''
                ? app(GitSources::class)->detect(Org::id(), (string) $git['url'], (string) ($git['source'] ?? ''), $branch, $dir)
                : app(GitHubApp::class)->detect(Org::id(), $repo, $branch, $dir);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()]);
        }
        $found['env'] = array_filter($found['env'], fn ($k) => ! preg_match(self::MANAGED, $k), ARRAY_FILTER_USE_KEY);

        return response()->json($found);
    }

    /** The type and the folder must be decided, by detection or by the person, before moving on. */
    public function source(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $input = (array) $request->input('input', []);
        $errors = [];
        // The top of the repository is an empty folder, which Laravel turns into null on the way in, so the page says whether it chose.
        if (($input['root_dir'] ?? null) === null && ! ($input['root_chosen'] ?? false)) {
            $errors['root_dir'] = 'There is more than one application in this repository. Choose which one this is.';
        }
        if (($input['runtime'] ?? '') === '') {
            $errors['runtime'] = 'Nothing here says what this is. Choose a type.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return response()->json(['ok' => true]);
    }

    /** The wizard's answers checked and priced, for the review step. */
    private function plan(array $input): array
    {
        try {
            return app(NewApplications::class)->plan(Org::id(), $input);
        } catch (\InvalidArgumentException $e) {
            [$field, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, $e->getMessage());
            throw ValidationException::withMessages([$field => $message]);
        }
    }

    public function check(Request $request): JsonResponse
    {
        Gate::authorize('create');

        return response()->json($this->plan((array) $request->input('input', [])));
    }

    public function create(Request $request): JsonResponse
    {
        Gate::authorize('create');
        $plan = $this->plan((array) $request->input('input', []));

        return response()->json(['operation' => app(NewApplications::class)->start(Org::id(), $plan, (string) $request->user()->email)['operation']]);
    }
}
