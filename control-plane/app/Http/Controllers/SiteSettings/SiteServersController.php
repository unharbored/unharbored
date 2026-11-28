<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Auth\Org;
use App\Lib\Cloud\AutoscalePools;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Providers;
use App\Lib\Scaling\ServerScaler;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\SettingsPage;
use App\Lib\Sites\Sites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The servers an application runs on: adding and removing them, the load balancer, and autoscaling. */
final class SiteServersController extends SiteSettingsBase
{
    public function add(Request $request, int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        $node = (string) $request->input('server', '');
        $role = (string) $request->input('role', Sites::ROLE_WEB);
        if (! in_array($role, [Sites::ROLE_WEB, Sites::ROLE_WORKER], true)) {
            throw ValidationException::withMessages(['server' => 'Add it as a web server or a worker server.']);
        }
        if ($row->database_cluster_id === null) {
            // SQLite lives on one server; migrations run on the primary only.
            throw ValidationException::withMessages(['server' => 'Attach a managed database first: SQLite cannot be shared between servers, so a second server could not run the same application.']);
        }
        $this->requireSharedState($row);
        $ok = DB::table('nodes')->where('node_id', $node)->where('organization_id', $row->organization_id)->exists();
        if (! $ok || in_array($node, Sites::nodes($row), true)) {
            throw ValidationException::withMessages(['server' => 'Pick a server this site is not on yet.']);
        }
        // Two sites of one name on one server would share a directory.
        $clash = DB::table('sites')->where('organization_id', Org::id())->where('name', $row->name)->where('id', '!=', $row->id)->where('node_id', $node)->exists()
            || DB::table('site_nodes')->join('sites', 'sites.id', '=', 'site_nodes.site_id')->where('site_nodes.node_id', $node)
                ->where('sites.name', $row->name)->where('sites.id', '!=', $row->id)->exists();
        if ($clash) {
            throw ValidationException::withMessages(['server' => "That server already runs another site called {$row->name}."]);
        }

        return $this->toOperation(app(OperationRunner::class)->queueServerAdd($row->id, $node, 'console', $this->email(), $role));
    }

    /** Makes a server at the application's own provider and adds it, as a web or a worker server. */
    public function create(Request $request, int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        $role = (string) $request->input('role', Sites::ROLE_WEB);
        if (! in_array($role, [Sites::ROLE_WEB, Sites::ROLE_WORKER], true)) {
            throw ValidationException::withMessages(['server' => 'Add it as a web server or a worker server.']);
        }
        if ($row->database_cluster_id === null) {
            throw ValidationException::withMessages(['server' => 'Attach a managed database first: SQLite cannot be shared between servers, so a second server could not run the same application.']);
        }
        $this->requireSharedState($row);
        $choice = SettingsPage::newServerChoices($row) ?? throw ValidationException::withMessages(['server' => 'A server cannot be made at this application\'s provider from here. Connect it, or add a server you already have.']);
        $region = (string) $request->input('region', '');
        $size = (string) $request->input('size', '');
        if (! isset($choice['regions'][$region]) || ! isset($choice['sizes'][$size])) {
            throw ValidationException::withMessages(['server' => 'Pick a region and size from the lists.']);
        }
        $provider = app(Providers::class)->get((string) ($row->provider ?? 'digitalocean'));
        $offered = method_exists($provider, 'availableSizes') ? $provider->availableSizes(Org::id(), $region) : null;
        if ($offered !== null && ! in_array($size, $offered, true)) {
            throw ValidationException::withMessages(['server' => "{$choice['label']} does not offer {$size} in {$region}. Pick another size or region."]);
        }
        $base = rtrim(substr(preg_replace('/[^a-z0-9-]/', '', strtolower($row->name.'-'.$role)), 0, 28), '-');
        $server = ['slug' => NewApplications::freeSlug(Org::id(), $base), 'provider' => (string) ($row->provider ?? 'digitalocean'), 'region' => $region, 'size' => $size];

        return $this->toOperation(app(OperationRunner::class)->queueSiteServerCreate($row->id, $server, $role, $this->email()));
    }

    /** A cache, sessions or queue kept on one server's disk cannot be seen from another: the same check the autoscaler makes. */
    private function requireSharedState(\stdClass $row): void
    {
        if (($why = app(ServerScaler::class)->problem($row)) !== null) {
            throw ValidationException::withMessages(['server' => $why]);
        }
    }

    public function remove(Request $request, int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        $node = (string) $request->input('node', '');
        if ($node === $row->node_id || ! in_array($node, Sites::nodes($row), true)) {
            throw ValidationException::withMessages(['server' => 'Only an added server can be removed; the primary stays.']);
        }
        if (DB::table('site_nodes')->where('site_id', $row->id)->where('node_id', $node)->whereNotNull('autoscale_pool_id')->exists()) {
            throw ValidationException::withMessages(['server' => 'The autoscale pool owns that server. Lower its limits instead.']);
        }

        $destroy = $request->boolean('destroy');
        if ($destroy && ! SettingsPage::destroyable($row->organization_id, $node)) {
            throw ValidationException::withMessages(['server' => 'That server was not made by Unharbored, so it is only removed from the application, never destroyed.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueServerRemove($row->id, $node, 'console', $this->email(), $destroy));
    }

    public function balancer(int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        if ($row->load_balancer_id !== null) {
            throw ValidationException::withMessages(['server' => 'This site already has a load balancer.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueLoadBalancer($row->id, 'console', $this->email()));
    }

    public function pool(Request $request, int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        $min = $request->integer('min');
        $max = $request->integer('max');
        if ($min < 1 || $max < $min || $max > 10) {
            throw ValidationException::withMessages(['pool' => 'Between 1 and 10 servers, the least first.']);
        }
        if ($row->autoscale_pool_id !== null) {
            return $this->toOperation(app(OperationRunner::class)->queuePoolResize($row->id, $min, $max, 'console', $this->email()));
        }
        foreach (['load_balancer_id' => 'a load balancer', 'bucket_id' => 'uploads on Spaces', 'database_cluster_id' => 'a managed database'] as $column => $what) {
            if ($row->{$column} === null) {
                throw ValidationException::withMessages(['pool' => "Autoscaling needs {$what} first."]);
            }
        }
        if ($row->current_release === null) {
            throw ValidationException::withMessages(['pool' => 'Deploy once first: new servers start from the live release.']);
        }

        // The provider's smallest size; the scaler adds servers of it.
        $sizes = array_keys(app(Providers::class)->get((string) ($row->provider ?? 'digitalocean'))->catalog()->sizes);

        return $this->toOperation(app(OperationRunner::class)->queuePoolCreate($row->id, ['min' => $min, 'max' => $max, 'size' => $sizes[0] ?? 's-1vcpu-1gb', 'cpu' => 0.6],
            'console', $this->email()));
    }

    public function removePool(int $site): Response
    {
        Gate::authorize('infrastructure');
        $row = $this->row($site);
        if ($row->autoscale_pool_id === null) {
            throw ValidationException::withMessages(['pool' => 'This site does not autoscale.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queuePoolRemove($row->id, 'console', $this->email()));
    }

    /** Lets a stopped autoscaler carry on, once what stopped it is put right. */
    public function resume(int $site): RedirectResponse
    {
        Gate::authorize('edit');
        try {
            app(ServerScaler::class)->resume($this->row($site));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['pool' => $e->getMessage()]);
        }

        return back();
    }

    /** Saved here; the minutely reconcile applies it. */
    public function addWindow(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        try {
            app(AutoscalePools::class)->addSchedule($this->row($site), (string) $request->input('days', ''), $request->integer('from'), $request->integer('to'), $request->integer('min'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['window' => $e->getMessage()]);
        }

        return $this->saved('Window saved. It takes effect within a minute of opening.');
    }

    public function removeWindow(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        try {
            app(AutoscalePools::class)->removeSchedule($this->row($site), $request->integer('index'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['window' => $e->getMessage()]);
        }

        return $this->saved('Window removed.');
    }
}
