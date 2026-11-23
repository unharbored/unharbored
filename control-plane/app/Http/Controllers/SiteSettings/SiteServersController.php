<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Auth\Org;
use App\Lib\Cloud\AutoscalePools;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Providers;
use App\Lib\Scaling\ServerScaler;
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
        if ($row->database_cluster_id === null) {
            // SQLite lives on one server; migrations run on the primary only.
            throw ValidationException::withMessages(['server' => 'Attach a managed database first: SQLite cannot be shared between servers.']);
        }
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

        return $this->toOperation(app(OperationRunner::class)->queueServerAdd($row->id, $node, 'console', $this->email()));
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

        return $this->toOperation(app(OperationRunner::class)->queueServerRemove($row->id, $node, 'console', $this->email()));
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
