<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Agent\AgentRelease;
use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Console\Consoles;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Incus\IncusHost;
use App\Lib\Providers\Incus\IncusProvider;
use App\Lib\Providers\Incus\Ingress;
use App\Lib\Servers\NodeRemoval;
use App\Lib\Servers\ServerArchiving;
use App\Lib\Servers\ServerInstall;
use App\Lib\Servers\ServersPage;
use App\Lib\Servers\ServerStack;
use App\Lib\Servers\Underneath;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/** The Servers page, and creating, connecting, repairing and removing servers from it. */
final class ServersController extends Controller
{
    /** The tabs of a server's page. */
    private const TABS = ['overview', 'metrics', 'commands', 'access', 'settings'];

    /** The list: one row per server, pools grouped, and what needs attention. */
    public function index(Request $request, ServersPage $page): InertiaResponse
    {
        Gate::authorize('view');
        [$connected, $gatewayUp] = $page->connected();

        return Inertia::render('Servers/Index', [
            'gatewayUp' => $gatewayUp,
            'serving' => app(AgentRelease::class)->version(),
            'accountNote' => $page->accountNote(),
            'servers' => fn () => $page->rows($connected),
            'archived' => fn () => $page->rows($connected, archived: true),
            'pools' => fn () => $page->pools(),
            'provisioning' => fn () => $page->provisioning(),
            'disconnected' => fn () => $page->disconnected(),
            'archives' => fn () => $page->archives(),
            'catalogs' => fn () => $page->catalogs(),
            'newKeys' => fn () => $page->newKeys(),
            'byo' => fn () => $request->session()->get('byo'),
        ]);
    }

    /** One server: its overview, metrics, commands, access and settings. */
    public function show(Request $request, ServersPage $page, string $node): InertiaResponse
    {
        Gate::authorize('view');
        $this->mine($node);
        $chart = in_array($request->query('chart'), ['1h', '24h', '7d'], true) ? (string) $request->query('chart') : '1h';
        [$connected, $gatewayUp] = $page->connected();

        return Inertia::render('Servers/Show', [
            'tab' => in_array($request->query('tab'), self::TABS, true) ? (string) $request->query('tab') : 'overview',
            'chart' => $chart,
            'gatewayUp' => $gatewayUp,
            'phpVersions' => ServerStack::phpVersionsFor($node),
            'consoles' => ['enabled' => Consoles::enabled(Org::id()), 'socketUrl' => Consoles::socketUrl()],
            'node' => fn () => $page->nodes($chart, $connected, $node)[0],
            'underneath' => fn () => Underneath::of($node),
            'commands' => fn () => $page->commands($node),
        ]);
    }

    /** One autoscale pool: settings, servers, history and load. */
    public function pool(Request $request, ServersPage $page, int $site): InertiaResponse
    {
        Gate::authorize('view');
        $chart = in_array($request->query('chart'), ['1h', '24h', '7d'], true) ? (string) $request->query('chart') : '1h';
        [$connected, $gatewayUp] = $page->connected();
        abort_if($page->pool($site, $chart, $connected) === null, 404);

        return Inertia::render('Servers/Pool', [
            'tab' => in_array($request->query('tab'), ['overview', 'history', 'metrics'], true) ? (string) $request->query('tab') : 'overview',
            'chart' => $chart,
            'gatewayUp' => $gatewayUp,
            'pool' => fn () => $page->pool($site, $chart, $connected),
        ]);
    }

    /** Creates a server at a connected provider, with the SSH keys switched on in the form. */
    public function provision(Request $request, ServersPage $page): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate([
            'name' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,30}$/'],
            'region' => 'required|string', 'size' => 'required|string',
            'keys' => 'array', 'keys.*' => 'integer', 'chosen' => 'boolean', 'provider' => 'nullable|string',
            'role' => 'nullable|in:'.IncusHost::ROLE,
        ]);
        $name = $data['name'];
        if ($name === Ingress::SLUG) {
            throw ValidationException::withMessages(['name' => 'That name is kept for the IPv4 ingress on an Incus host. Pick another.']);
        }
        $provider = $data['provider'] ?? 'digitalocean';
        $catalog = $page->catalogs()[$provider] ?? null;
        if ($catalog === null) {
            throw ValidationException::withMessages(['name' => 'Pick a provider that is connected.']);
        }
        if (! array_key_exists($data['region'], $catalog['regions']) || ! array_key_exists($data['size'], $catalog['sizes'])) {
            throw ValidationException::withMessages(['name' => 'Pick a region and size from the list.']);
        }
        if (DB::table('resources')->where('organization_id', Org::id())->where('slug', $name)->exists()
            || DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $name.'.'.Org::id())->exists()) {
            throw ValidationException::withMessages(['name' => "There is already a server called {$name}."]);
        }
        $chosen = (bool) ($data['chosen'] ?? false);
        $role = $data['role'] ?? null;
        if ($role !== null) {
            if ($provider === IncusProvider::PROVIDER) {
                throw ValidationException::withMessages(['name' => 'An Incus host is a machine of its own: pick another provider.']);
            }
            try {
                app(IncusHost::class)->assertCanAdd(Org::id());
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages(['name' => $e->getMessage()]);
            }
        }

        $id = app(OperationRunner::class)->queueProvision(Org::id(), $name, ['provider' => $provider, 'region' => $data['region'], 'size' => $data['size']] + ($role !== null ? ['role' => $role] : []), 'console', (string) $request->user()->email,
            $chosen ? array_map('intval', $data['keys'] ?? []) : null);

        return $this->toOperation($id);
    }

    /** A one-time install command for a server the platform did not create; shown on the page once. */
    public function own(Request $request): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate(['byoName' => ['required', 'regex:/^[a-z0-9][a-z0-9-]{0,30}$/']]);
        try {
            $issued = app(ServerInstall::class)->issue(Org::id(), $data['byoName'], (string) config('app.url'), (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['byoName' => $e->getMessage()]);
        }

        return back()->with('byo', $issued);
    }

    /** Stops managing a server: its agent goes if reachable and its certificate is revoked. */
    public function disconnect(Request $request, string $node): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        try {
            $r = app(NodeRemoval::class)->remove($node, (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remove' => $e->getMessage()]);
        }

        return redirect()->route('servers')->with('status', "Removed {$node}: certificate revoked".($r['uninstalled'] ? ', agent uninstalled' : '').'.'
            .($r['droplet'] ? " Droplet {$r['droplet']} still exists and is still billed; destroy it in DigitalOcean when you are done with it." : ''));
    }

    /** Stops managing a server and its applications; the machine, its provider and its agent are not touched. */
    public function archive(Request $request, string $node): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        try {
            app(ServerArchiving::class)->archiveServer(Org::id(), $node, Actor::user((string) $request->user()->id, (string) $request->user()->email));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['archive' => $e->getMessage()]);
        }

        return back()->with('status', 'Archived. It keeps running; Unharbored no longer manages it.');
    }

    public function restore(Request $request, string $node): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        app(ServerArchiving::class)->restoreServer(Org::id(), $node, Actor::user((string) $request->user()->id, (string) $request->user()->email));

        return back()->with('status', 'Restored. Unharbored manages it again.');
    }

    /** Clears a node record whose Droplet is already gone at the provider. */
    public function removeStale(Request $request, string $node): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        try {
            app(NodeRemoval::class)->removeStale($node, (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['remove' => $e->getMessage()]);
        }

        return redirect()->route('servers')->with('status', "Removed stale entry {$node}.");
    }

    /** Deletes the server and everything under it, as chosen on the review step; the server's name must be typed. */
    public function delete(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);
        $data = $request->validate(['confirm' => 'required|string', 'choices' => 'array', 'choices.*' => 'string']);
        $name = strstr($node, '.', true) ?: $node;
        if ($data['confirm'] !== $name) {
            throw ValidationException::withMessages(['delete' => "Type {$name} exactly to confirm."]);
        }
        try {
            $id = app(OperationRunner::class)->queueServerDelete($node, $data['choices'] ?? [], 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['delete' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    public function destroy(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        abort_unless(DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $node)->exists(), 404);
        try {
            $id = app(OperationRunner::class)->queueServerDestroy($node, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['destroy' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    public function destroyDroplet(Request $request, int $resource): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        abort_unless(DB::table('resources')->where('organization_id', Org::id())->where('id', $resource)->exists(), 404);
        try {
            $id = app(OperationRunner::class)->queueDropletDestroy($resource, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['destroy' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    public function deleteArchive(Request $request, int $archive): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        abort_unless(DB::table('site_archives')->where('organization_id', Org::id())->where('id', $archive)->exists(), 404);
        try {
            $id = app(OperationRunner::class)->queueArchiveDestroy($archive, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['destroy' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    /** Re-applies the base server software. */
    public function repair(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);

        try {
            return $this->toOperation(app(OperationRunner::class)->queueStack($node, 'console', (string) $request->user()->email));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['repair' => $e->getMessage()]);
        }
    }

    public function upgradeAgent(Request $request, string $node): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $this->mine($node);

        return $this->toOperation(app(OperationRunner::class)->queueAgentUpgrade($node, 'console', (string) $request->user()->email));
    }

    private function mine(string $node): void
    {
        abort_unless(DB::table('nodes')->where('organization_id', Org::id())->where('node_id', $node)->exists(), 404);
    }

    /** The operation's page is not an Inertia page yet, so the browser goes there with a full page load. */
    private function toOperation(int $id): SymfonyResponse
    {
        return Inertia::location("/operation?operation={$id}");
    }
}
