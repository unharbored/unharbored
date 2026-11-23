<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Cloud\DatabaseServers;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Providers;
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
            'choices' => $this->choices(),
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

        return $out;
    }

    /** @return list<array<string, mixed>> */
    private function clusters(): array
    {
        return DB::table('resources')->where('organization_id', Org::id())->where('type', 'database_cluster')->where('state', '!=', 'deleted')->orderBy('slug')->get()
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
                    'engine' => DatabaseClusters::ENGINES[$a['engine'] ?? ''] ?? ($a['engine'] ?? '?'),
                    'version' => $a['version'] ?? null,
                    'where' => trim(($a['region'] ?? '').' · '.($size['label'] ?? DatabaseClusters::SIZES[$a['size'] ?? '']['label'] ?? ($a['size'] ?? ''))),
                    'monthly' => $selfRun ? ($size['price'] ?? null) : (DatabaseClusters::SIZES[$a['size'] ?? '']['monthly'] ?? null),
                    'host' => $a['host'] ?? null,
                    'sites' => Projects::visibleSites()->where('database_cluster_id', $r->id)->orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])->all(),
                    'operation' => $op ? ['id' => $op->id, 'status' => $op->status] : null,
                ];
            })->all();
    }

    public function store(Request $request): HttpResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate([
            'name' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,30}$/'],
            'provider' => 'nullable|string', 'engine' => 'required|string', 'region' => 'required|string', 'size' => 'required|string',
        ]);
        $provider = (string) ($data['provider'] ?? 'digitalocean');
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
}
