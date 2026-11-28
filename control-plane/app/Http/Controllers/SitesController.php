<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Alerts\Alerts;
use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Git\GitRemote;
use App\Lib\Git\GitRepo;
use App\Lib\Operations\Present;
use App\Lib\Providers\Providers;
use App\Lib\Sites\Runtimes;
use App\Lib\Sites\Sites;
use App\Lib\Vault\Vault;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** The Applications list: every site and what is live, the getting-started steps, recent activity, and "+ Site on a server". */
final class SitesController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('view');
        // ?project=<slug> sets the project filter (an unknown slug is ignored).
        if ($request->query->has('project')) {
            Projects::chooseSlug((string) $request->query('project'));
        }

        return Inertia::render('Sites', [
            'sites' => fn () => $this->sites(),
            'activity' => fn () => $this->activity(),
            'archived' => fn () => $this->archived(),
            'start' => fn () => $this->start(),
            'form' => fn () => $this->form(),
            'filter' => fn () => $this->filter(),
            // Whether the creation buttons are shown; the server refuses anyone else regardless.
            'canCreate' => Gate::allows('create'),
            'canAudit' => Gate::allows('audit.read'),
        ]);
    }

    /**
     * Records the site: its key, its .env, nothing on the server yet. The
     * first deploy builds it there. Takes a GitHub web URL as well as the
     * SSH form the platform clones with.
     */
    public function store(Request $request): SymfonyResponse
    {
        Gate::authorize('create');
        $server = (string) $request->input('server');
        $node = DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $server)->first();
        if ($node === null || \App\Lib\Providers\Incus\IncusHost::reserved($node->role ?? null)) {
            throw ValidationException::withMessages(['newServer' => 'Pick a server.']);
        }
        $repository = trim((string) $request->input('repository'));
        if (preg_match('#^https://github\.com/([A-Za-z0-9_.-]+)/([A-Za-z0-9_.-]+?)(?:\.git)?/?$#', $repository, $m)) {
            $repository = "git@github.com:{$m[1]}/{$m[2]}.git";
        }
        // A host that is not GitHub: its SSH host key is pinned now, from what it shows (the application's Source settings show it).
        $hostKey = null;
        try {
            $repo = GitRepo::parse($repository);
            if ($repo->viaSsh && ! $repo->isGitHub()) {
                $hostKey = app(GitRemote::class)->hostKey($repo)['line'];
            }
        } catch (\InvalidArgumentException) {
            // Create() says what is wrong with it.
        } catch (\RuntimeException $e) {
            throw ValidationException::withMessages(['newName' => $e->getMessage()]);
        }
        $name = strtolower(trim((string) $request->input('name')));
        $same = DB::table('sites')->where('organization_id', Org::id())->where('node_id', $server)->where('name', $name)->first();
        if ($same !== null) {
            // An archived application still lives on its server, so its name stays taken until it is restored and deleted.
            throw ValidationException::withMessages(['newName' => "{$server} already has a site called {$name}".($same->archived_at !== null ? ' (archived: it still runs there)' : '').'.']);
        }
        try {
            $site = app(Sites::class)->create(Org::id(), $server, $name, strtolower(trim((string) $request->input('domain'))), $repository, trim((string) $request->input('branch')) ?: 'main', $hostKey);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['newName' => $e->getMessage()]);
        }
        if ($request->boolean('app') && DB::table('github_apps')->where('organization_id', Org::id())->exists()) {
            app(Sites::class)->setGitSource($site, 'github_app', true);
        }

        // The settings page is not an Inertia page yet: a full visit, not an XHR redirect.
        return Inertia::location("/site/settings?site={$site->id}&tab=source");
    }

    /** What a new account still has to do before its first application, each step ticked when it is true. @return array<string, bool> */
    private function start(): array
    {
        $org = Org::id();
        $cloud = array_filter(app(Providers::class)->connected($org), fn ($p) => $p->method() !== 'ssh');
        $hasServer = DB::table('nodes')->where('organization_id', $org)->exists();

        return [
            'hasServer' => $hasServer || $cloud !== [],
            'hasCloud' => $cloud !== [],
            'hasGitHub' => DB::table('github_apps')->where('organization_id', $org)->exists(),
            'hasSites' => DB::table('sites')->where('organization_id', $org)->exists(),
            'hasAlerts' => app(Vault::class)->has($org, Alerts::WEBHOOK_SECRET) || DB::table('organizations')->where('id', $org)->value('alert_email') !== null,
            'hasTeam' => DB::table('memberships')->where('organization_id', $org)->count() > 1,
        ];
    }

    /** Servers a site can be put on, and whether the GitHub App can clone. @return array<string, mixed> */
    private function form(): array
    {
        return [
            'servers' => DB::table('nodes')->where('organization_id', Org::id())->whereNull('archived_at')
                ->whereNotIn('node_id', DB::table('site_nodes')->whereNotNull('autoscale_pool_id')->select('node_id'))
                ->where(fn ($q) => $q->whereNull('role')->orWhereNotIn('role', ['ingress', \App\Lib\Providers\Incus\IncusHost::ROLE]))
                ->orderBy('node_id')->pluck('node_id')->map(fn ($n) => ['id' => $n, 'name' => strstr($n, '.', true) ?: $n])->all(),
            'appReady' => DB::table('github_apps')->where('organization_id', Org::id())->exists(),
        ];
    }

    /** The project filter that is on (its name), or empty. @return array<string, string> */
    private function filter(): array
    {
        $id = Projects::current();

        return $id === null ? [] : ['name' => (string) DB::table('projects')->where('id', $id)->value('name')];
    }

    /** Applications Unharbored no longer manages (still running on their servers), for the collapsed Archived section. @return list<array<string, mixed>> */
    private function archived(): array
    {
        return Projects::sites(archived: true)->whereNull('sites.parent_site_id')->orderBy('name')->get()->map(fn ($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'url' => ((bool) $s->tls ? 'https://' : 'http://').$s->domain,
            'node' => strstr($s->node_id, '.', true) ?: $s->node_id,
            'since' => Present::ago($s->archived_at),
        ])->all();
    }

    /** Every site with what is live and how its last operation went. @return list<array<string, mixed>> */
    private function sites(): array
    {
        $rows = [];
        foreach (Projects::sites()->orderBy('name')->get() as $site) {
            $last = DB::table('operations')->where('organization_id', Org::id())->where('site_id', $site->id)->orderByDesc('id')->first();
            $live = DB::table('operations')->where('organization_id', Org::id())->where('site_id', $site->id)->where('release_id', $site->current_release)
                ->where('status', 'succeeded')->orderByDesc('id')->first();
            $running = $last !== null && in_array($last->status, ['queued', 'running'], true);

            $rows[] = [
                'id' => $site->id,
                'name' => $site->name,
                'url' => ((bool) $site->tls ? 'https://' : 'http://').$site->domain,
                'node' => strstr($site->node_id, '.', true) ?: $site->node_id,
                'commit' => Present::commit($live->commit ?? null) ?? Present::shortRelease($site->current_release),
                'badge' => $last === null ? null : Present::badge($last),
                'operationId' => $last->id ?? null,
                'detail' => $last === null ? 'never deployed'
                    : ($running ? (Present::currentStage($last) ?? 'queued').' · '.Present::duration($last->started_at ?? $last->created_at)
                    : Present::ago($last->finished_at).($last->started_at ? ' · '.Present::duration($last->started_at, $last->finished_at) : '')),
                'running' => $running,
                'awaitingKey' => Sites::awaitingDeployKey($site) !== null,
                'runtime' => Runtimes::runtime($site),
                'type' => match (Runtimes::runtime($site)) {
                    'php' => null, 'dockerfile' => 'Dockerfile', 'image' => 'Docker image', default => Runtimes::PRESETS[Runtimes::runtime($site)]['label'] ?? null,
                },
                'source' => Runtimes::runtime($site) === 'image' ? 'Docker image' : ($site->git_source === 'github_app' ? 'GitHub App' : 'Deploy key'),
                'push' => (bool) $site->push_to_deploy,
                'branch' => $site->branch,
            ];
        }

        return $rows;
    }

    /** The latest operations anywhere. @return list<array<string, mixed>> */
    private function activity(): array
    {
        return DB::table('operations')->where('organization_id', Org::id())
            ->when(Projects::current() !== null, fn ($q) => $q->whereIn('site_id', Projects::sites()->select('sites.id')))
            ->orderByDesc('id')->limit(6)->get()->map(fn ($op) => [
                'id' => $op->id,
                'title' => $op->title,
                'by' => Present::startedBy($op),
                'status' => $op->status,
                'commit' => Present::commit($op->commit),
                'ago' => Present::ago($op->created_at),
            ])->all();
    }
}
