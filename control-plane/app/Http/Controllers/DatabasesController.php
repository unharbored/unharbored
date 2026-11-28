<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Cloud\DatabaseServers;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Present;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Providers;
use App\Lib\Servers\ServerArchiving;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Database clusters, shared by the whole team: DigitalOcean's managed ones, and on any other provider that gives us
 * servers, PostgreSQL or MySQL that Unharbored runs on a server of its own.
 */
final class DatabasesController extends Controller
{
    private const REGIONS = ['nyc3' => 'New York', 'sfo3' => 'San Francisco', 'tor1' => 'Toronto', 'lon1' => 'London', 'ams3' => 'Amsterdam'];

    public function index(): Response
    {
        Gate::authorize('view');

        return Inertia::render('Databases', [
            'clusters' => fn () => $this->clusters(),
            'archived' => fn () => $this->clusters(archived: true),
            'choices' => $this->choices(),
            'byo' => fn () => request()->session()->get('byo'),
        ]);
    }

    /**
     * Where a cluster can be made, by provider: its engines, regions and sizes. DigitalOcean's are managed by it; any
     * other connected provider's are a server we run.
     *
     * @return array<string, array<string, mixed>>
     */
    private function choices(): array
    {
        // DigitalOcean is always offered, as it was before other providers: an unconnected key fails when the create runs.
        $out = ['digitalocean' => ['label' => 'DigitalOcean', 'managed' => true, 'engines' => DatabaseClusters::ENGINES, 'regions' => self::REGIONS, 'region' => 'nyc3',
            'sizes' => array_map(fn ($s) => $s['label'].' · $'.$s['monthly'].'/mo', DatabaseClusters::SIZES), 'size' => 'db-s-1vcpu-1gb']];
        foreach (app(Providers::class)->connected(Org::id()) as $p) {
            if ($p->method() !== 'api' || $p->id() === 'digitalocean') {
                continue;
            }
            $c = $p->catalog();
            $out[$p->id()] = ['label' => $p->label(), 'managed' => false, 'engines' => DatabaseServers::ENGINES, 'regions' => $c->regions, 'region' => $c->defaultRegion,
                'sizes' => array_map(Catalog::sizeLabel(...), $c->sizes), 'size' => array_keys($c->sizes)[0] ?? ''];
        }

        // A machine they already have, with nothing else on it: nothing to size or place, and nothing billed by us.
        $out['custom'] = ['label' => 'Your own server', 'managed' => false, 'custom' => true, 'engines' => DatabaseServers::ENGINES, 'regions' => [], 'region' => '', 'sizes' => [], 'size' => ''];

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function clusters(bool $archived = false): array
    {
        return DB::table('resources')->where('organization_id', Org::id())->where('type', 'database_cluster')->where('state', '!=', 'deleted')
            ->when($archived, fn ($q) => $q->whereNotNull('archived_at'), fn ($q) => $q->whereNull('archived_at'))->orderBy('slug')->get()
            ->map(function ($r) {
                $a = json_decode((string) $r->attributes, true) ?: [];
                $selfRun = ($a['mode'] ?? null) === 'self_run';
                $catalog = $selfRun && app(Providers::class)->has((string) $r->provider) ? app(Providers::class)->get((string) $r->provider)->catalog() : null;
                $size = $catalog?->sizes[$a['size'] ?? ''] ?? null;
                $op = DB::table('operations')->where('organization_id', Org::id())->where('kind', 'like', 'database_%')->where('meta', 'like', '%"slug":"'.$r->slug.'"%')
                    ->orderByDesc('id')->first(['id', 'status']);

                return [
                    'id' => $r->id,
                    'slug' => $r->slug,
                    'state' => $r->state,
                    'provider' => $r->provider,
                    'selfRun' => $selfRun,
                    'ownServer' => ($a['own_server'] ?? false) === true,
                    'error' => $r->state === 'failed' ? ($r->last_error ?? null) : null,
                    'adopted' => ($a['adopted'] ?? false) === true,
                    'engine' => DatabaseClusters::ENGINES[$a['engine'] ?? ''] ?? ($a['engine'] ?? '?'),
                    'version' => $a['version'] ?? null,
                    'where' => trim(($a['region'] ?? '').' · '.($size['label'] ?? DatabaseClusters::SIZES[$a['size'] ?? '']['label'] ?? ($a['size'] ?? ''))),
                    'monthly' => $selfRun ? ($size['price'] ?? null) : (DatabaseClusters::SIZES[$a['size'] ?? '']['monthly'] ?? null),
                    'host' => $a['host'] ?? null,
                    'sites' => Projects::visibleSites()->where('database_cluster_id', $r->id)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
                    'operation' => $op ? ['id' => $op->id, 'status' => $op->status] : null,
                    'since' => $r->archived_at === null ? null : Present::ago($r->archived_at),
                ];
            })->all();
    }

    public function store(Request $request): HttpResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate([
            'name' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,30}$/'],
            'provider' => 'nullable|string', 'engine' => 'required|string', 'region' => 'required_unless:provider,custom|nullable|string', 'size' => 'required_unless:provider,custom|nullable|string',
            'address' => ['nullable', 'ip'],
        ]);
        $provider = (string) ($data['provider'] ?? 'digitalocean');
        if ($provider === 'custom') {
            try {
                $issued = app(DatabaseServers::class)->createOnOwnServer(Org::id(), $data['name'], $data['engine'], $data['address'] ?? null, (string) config('app.url'), (string) $request->user()->email);
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['name' => $e->getMessage()]);
            }

            return back()->with('byo', $issued);
        }
        $choice = $this->choices()[$provider] ?? throw ValidationException::withMessages(['name' => 'Connect that provider first, under Account › Providers.']);
        if (! isset($choice['engines'][$data['engine']]) || ! isset($choice['sizes'][$data['size']]) || ! isset($choice['regions'][$data['region']])) {
            throw ValidationException::withMessages(['name' => 'Pick an engine, region and size from the lists.']);
        }
        if (DB::table('resources')->where('organization_id', Org::id())->where('type', 'database_cluster')->where('slug', $data['name'])->exists()) {
            throw ValidationException::withMessages(['name' => "There is already a cluster called {$data['name']}."]);
        }
        $id = app(OperationRunner::class)->queueDatabaseCreate(Org::id(), $data['name'], $data['engine'], $data['region'], $data['size'], 'console', (string) $request->user()->email, $provider);

        // The operation page may not be an Inertia page yet: a full visit works for both.
        return Inertia::location("/operation?operation={$id}");
    }

    /** The clusters in the connected DigitalOcean account that can be brought in. Asked when the person looks, not on every page. */
    public function discover(): JsonResponse
    {
        Gate::authorize('infrastructure');
        try {
            return response()->json(['clusters' => app(DatabaseClusters::class)->discover(Org::id())]);
        } catch (\Throwable $e) {
            return response()->json(['message' => 'DigitalOcean could not be asked: '.$e->getMessage()], 422);
        }
    }

    /** Brings one of them in. Nothing at DigitalOcean changes. */
    public function adopt(Request $request): HttpResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate(['cluster' => ['required', 'string', 'max:64']]);
        try {
            app(DatabaseClusters::class)->adopt(Org::id(), $data['cluster']);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['adopt' => $e->getMessage()]);
        }

        return back();
    }

    public function destroy(Request $request, int $cluster): HttpResponse
    {
        Gate::authorize('infrastructure');
        if (DB::table('sites')->where('organization_id', Org::id())->where('database_cluster_id', $cluster)->exists()) {
            throw ValidationException::withMessages(['destroy' => 'Detach every site from it first.']);
        }
        abort_unless(DB::table('resources')->where('organization_id', Org::id())->where('id', $cluster)->where('type', 'database_cluster')->exists(), 404);
        $id = app(OperationRunner::class)->queueDatabaseDestroy($cluster, 'console', (string) $request->user()->email);

        return Inertia::location("/operation?operation={$id}");
    }

    /** Stops managing the cluster (and, for one that is a server of its own, the server); nothing at the provider or on the machine changes. */
    public function archive(Request $request, int $cluster): HttpResponse
    {
        Gate::authorize('infrastructure');
        try {
            app(ServerArchiving::class)->archiveCluster(Org::id(), $cluster, Actor::user((string) $request->user()->id, (string) $request->user()->email));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['archive' => $e->getMessage()]);
        }

        return back()->with('status', 'Archived. It keeps running; Unharbored no longer manages it.');
    }

    public function restore(Request $request, int $cluster): HttpResponse
    {
        Gate::authorize('infrastructure');
        app(ServerArchiving::class)->restoreCluster(Org::id(), $cluster, Actor::user((string) $request->user()->id, (string) $request->user()->email));

        return back()->with('status', 'Restored. Unharbored manages it again.');
    }

    /** A new certificate, for a changed address or an old certificate. Own servers may give the address they have now. */
    public function reissue(Request $request, int $cluster): HttpResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate(['address' => ['nullable', 'ip']]);
        $row = DB::table('resources')->where('organization_id', Org::id())->where('id', $cluster)->where('type', 'database_cluster')->first() ?? abort(404);
        abort_unless((json_decode((string) $row->attributes, true)['mode'] ?? null) === 'self_run', 404);
        $id = app(OperationRunner::class)->queueDatabaseReissue($cluster, $data['address'] ?? null, 'console', (string) $request->user()->email);

        return Inertia::location("/operation?operation={$id}");
    }
}
