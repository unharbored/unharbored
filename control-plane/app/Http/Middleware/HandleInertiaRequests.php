<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Auth\Roles;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\Present;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Middleware;

/**
 * What every console page needs besides its own data: the team and project
 * switchers, the gateway pill, the tabs this role may open, flash messages and
 * the permissions the page uses to show or hide controls. All closures, so a
 * partial reload that does not ask for them never runs the queries.
 */
final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            'shell' => fn () => $request->user() === null || Org::mine((int) $request->user()->id) === [] ? null : $this->shell($request),
            'can' => fn () => $request->user() === null ? [] : $this->can(),
            'operations' => fn () => $request->user() === null || Org::mine((int) $request->user()->id) === [] ? [] : $this->operations(),
            'flash' => fn () => $request->session()->get('status'),
            'flashError' => fn () => $request->session()->get('error'),
            'routeName' => fn () => $request->route()?->getName(),
            'theme' => fn () => in_array($request->cookie('theme'), ['light', 'dark'], true) ? $request->cookie('theme') : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function shell(Request $request): array
    {
        try {
            $gateway = app(GatewayClient::class)->health();
            $gatewayUp = (bool) $gateway['reachable'];
            $nodeCount = (int) ($gateway['body']['connected_nodes'] ?? 0);
        } catch (\Throwable) {
            $gatewayUp = false;
            $nodeCount = 0;
        }

        $org = Org::id();
        $mine = Org::mine((int) $request->user()->id);
        $names = DB::table('organizations')->whereIn('id', $mine)->pluck('name', 'id');

        return [
            'site' => $this->siteContext($request),
            'host' => $request->getHost(),
            'gateway' => ['up' => $gatewayUp, 'nodes' => $nodeCount],
            'team' => ['id' => $org, 'name' => (string) ($names[$org] ?? $org)],
            'teams' => array_map(fn ($id) => ['id' => $id, 'name' => (string) ($names[$id] ?? $id)], $mine),
            'projects' => Projects::listing($org),
            'project' => Projects::current(),
            'openAlerts' => DB::table('alerts')->where('organization_id', $org)->whereNull('resolved_at')->count(),
            'routes' => [
                'sites' => route('sites'), 'services' => route('services'), 'servers' => route('servers'),
                'databases' => route('databases'), 'logs' => route('logs'), 'alerts' => route('alerts'),
                'audit' => route('audit'), 'tokens' => route('tokens'), 'account' => route('account'),
                'logout' => route('logout'), 'theme' => route('theme'),
                'switchTeam' => route('organization.switch'), 'switchProject' => route('project.switch'),
            ],
        ];
    }

    /**
     * On an application's pages (a site, its settings, deployments, logs, or one of
     * its operations) the top bar names the application and environment and
     * switches between them; null elsewhere.
     *
     * @return array<string, mixed>|null
     */
    private function siteContext(Request $request): ?array
    {
        if (! $request->routeIs('site', 'site.settings', 'site.deployments', 'site.logs', 'site.console', 'operation')) {
            return null;
        }
        $org = Org::id();
        $siteId = (int) $request->query('site');
        if ($request->routeIs('operation')) {
            $siteId = (int) DB::table('operations')->where('organization_id', $org)->where('id', (int) $request->query('operation'))->value('site_id');
        }
        $current = $siteId > 0 ? DB::table('sites')->where('organization_id', $org)->find($siteId) : null;
        if ($current === null) {
            return null;
        }
        $app = $current->parent_site_id ? DB::table('sites')->where('organization_id', $org)->find($current->parent_site_id) : $current;
        $envName = fn ($s) => $s->parent_site_id === null ? 'production' : ($s->pr_number ? "PR #{$s->pr_number}" : $s->branch);
        $envs = collect([$app])->concat(DB::table('sites')->where('organization_id', $org)->where('parent_site_id', $app->id)->orderBy('id')->get());

        return [
            'id' => $current->id,
            'app' => ['id' => $app->id, 'name' => $app->name],
            'apps' => DB::table('sites')->where('organization_id', $org)->whereNull('archived_at')->whereNull('parent_site_id')->orderBy('name')->get(['id', 'name'])->all(),
            'env' => ['id' => $current->id, 'label' => $envName($current)],
            'envs' => $envs->map(fn ($e) => ['id' => $e->id, 'label' => $envName($e), 'preview' => (bool) $e->parent_site_id])->all(),
            'newApp' => route('apps.new'),
        ];
    }

    /**
     * What is running now, and what finished in the last ten minutes, so the top bar can take someone back to it after
     * they leave an operation's page. Nightly and other scheduled runs are left out: nobody is waiting on those.
     *
     * @return list<array{id: int, title: string, status: string, stage: ?string}>
     */
    private function operations(): array
    {
        return DB::table('operations')->where('organization_id', Org::id())->where('trigger', '!=', 'schedule')
            ->where(fn ($q) => $q->whereIn('status', ['queued', 'running'])->orWhere('finished_at', '>=', now()->subMinutes(10)))
            ->orderByDesc('id')->limit(6)->get()
            ->map(fn ($o) => ['id' => $o->id, 'title' => $o->title, 'status' => $o->status, 'stage' => Present::currentStage($o)])->all();
    }

    /** @return array<string, bool> one flag per permission, for the current team */
    private function can(): array
    {
        $flags = [];
        foreach (Roles::allPermissions() as $permission) {
            $flags[$permission] = Gate::allows($permission);
        }

        return $flags;
    }
}
