<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Auth\Roles;
use App\Lib\Containers\Containers;
use App\Lib\Containers\Docker;
use App\Lib\Operations\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * A server's Containers view: Docker's version, the cm-svc containers and what they use, disk use by Docker, and layer
 * cleanup; for a server not yet prepared, the button that prepares it. What is shown is the last read, kept on the node;
 * a page load runs no command, the Refresh button does.
 */
final class ServerContainersController extends Controller
{
    public function show(Request $request): InertiaResponse
    {
        Gate::authorize('view');
        $node = (string) $request->query('node');

        return Inertia::render('Servers/Containers', ['node' => $node, 'view' => fn () => $this->view($node)]);
    }

    /** Queues the preparation. A small server needs the confirmation. */
    public function prepare(Request $request): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        try {
            $id = app(Containers::class)->prepare(Org::id(), (string) $request->input('node'), (string) $request->user()->email, (string) Roles::roleOf($request->user(), Org::id()), $request->boolean('acknowledgeSmall'), true, 'console');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['prepare' => $e->getMessage()]);
        }

        return Inertia::location("/operation?operation={$id}");
    }

    /** docker image prune -f and docker builder prune -f, nothing else. */
    public function cleanup(Request $request): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        try {
            $id = app(Containers::class)->cleanup(Org::id(), (string) $request->input('node'), (string) $request->user()->email, (string) Roles::roleOf($request->user(), Org::id()), true, 'console');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['cleanup' => $e->getMessage()]);
        }

        return Inertia::location("/operation?operation={$id}");
    }

    /** Reads what runs now (a few seconds), keeps it on the node. Never run by a page load. */
    public function refresh(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        try {
            app(Containers::class)->refresh(Org::id(), (string) $request->input('node'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['refresh' => $e->getMessage()]);
        } catch (\Throwable $e) {
            throw ValidationException::withMessages(['refresh' => 'The server did not answer: '.$e->getMessage()]);
        }

        return back();
    }

    /** @return array<string, mixed> */
    private function view(string $node): array
    {
        $row = DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $node)->first();
        if ($row === null) {
            return ['missing' => true];
        }
        $status = app(Containers::class)->status(Org::id(), $node);
        $m = DB::table('node_metrics')->where('node_id', $node)->orderByDesc('collected_at')->first();
        $services = DB::table('services')->where('organization_id', Org::id())->where('node_id', $node)->where('runtime', 'container')->get();
        $endpoints = DB::table('service_endpoints')->whereIn('service_id', $services->pluck('id'))->get()->groupBy('service_id');
        $byContainer = [];
        foreach ($services as $s) {
            $byContainer['cm-svc-'.$s->name] = [
                'id' => (int) $s->id, 'name' => $s->name, 'status' => $s->status, 'image' => (string) $s->image,
                'addresses' => ($endpoints[$s->id] ?? collect())->map(fn ($e) => '127.0.0.1:'.$e->port)->values()->all(),
            ];
        }
        $ops = DB::table('operations')->where('organization_id', Org::id())->where('node_id', $node)->whereIn('kind', ['server_containers', 'container_cleanup'])->orderByDesc('id')->limit(4)->get();
        $running = $ops->first(fn ($o) => in_array($o->status, ['queued', 'running'], true));
        $total = $m ? (int) $m->mem_total : null;

        return [
            'missing' => false,
            'node' => $node,
            'name' => strstr($node, '.', true) ?: $node,
            'status' => $status,
            'small' => $total !== null && $total < Docker::SMALL_BYTES,
            'memory' => $total === null ? null : round($total / 1024 ** 3, 1).' GB',
            'rows' => array_map(fn ($c) => $c + ['service' => $byContainer[$c['name']] ?? null], $status['containers']),
            'running' => $running === null ? null : ['id' => (int) $running->id, 'title' => $running->title],
            'operations' => $ops->map(fn ($o) => ['id' => (int) $o->id, 'title' => $o->title, 'badge' => Present::badge($o), 'ago' => Present::ago($o->created_at)])->all(),
        ];
    }
}
