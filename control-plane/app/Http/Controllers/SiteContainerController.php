<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Containers\ContainerApps;
use App\Lib\Sites\Runtimes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The container of an application from a Dockerfile or a Docker image: what it runs, its settings, a registry login, and for an
 * image the Update flow (check, see the old and new digest, confirm). Settings take effect on the next deploy. Nothing secret is
 * shown: the registry token is write-only (and bootstrap/app.php keeps it out of flashed input).
 */
final class SiteContainerController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('view');
        $site = (int) $request->query('site');

        return Inertia::render('SiteContainer', [
            'view' => fn () => $this->view($site),
            // What the last "Check for an update" found; flashed once.
            'found' => fn () => $request->session()->get('found'),
        ]);
    }

    /** Saves the settings. They reach the container on the next deploy. */
    public function saveSettings(Request $request): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($request);
        $form = [
            'container_port' => $request->input('port', ''), 'health' => $request->input('health', ''), 'memory' => $request->input('memory', ''),
            'dockerfile' => $request->input('dockerfile', ''), 'context' => $request->input('context', ''), 'volumes' => $request->input('volumes', ''),
            'read_only' => (bool) $request->input('readOnly', false),
        ];
        try {
            app(ContainerApps::class)->updateSettings($row, $form, (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages($this->fieldError($e));
        }

        return back()->with('status', 'Saved. It reaches the container on the next deploy.');
    }

    /** Sets, changes or (with a blank user) removes the registry login. The token is write-only. */
    public function saveRegistry(Request $request): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($request);
        $user = (string) $request->input('registryUser', '');
        try {
            app(ContainerApps::class)->setRegistry($row, $user, (string) $request->input('registryToken', ''), (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages($this->fieldError($e));
        }

        return back()->with('status', $user === '' ? 'Registry login removed.' : 'Registry login saved.');
    }

    /** Pulls the tag again and shows the old and the new digest; nothing changes until it is confirmed. */
    public function checkUpdate(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        $row = $this->row($request);
        try {
            $found = app(ContainerApps::class)->checkUpdate($row, (string) $request->user()->email);
        } catch (\InvalidArgumentException|\RuntimeException $e) {
            throw ValidationException::withMessages(['update' => $e->getMessage()]);
        }

        return back()->with('found', $found);
    }

    /** Runs the digest that was shown, and only that one. */
    public function confirmUpdate(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        $row = $this->row($request);
        try {
            $id = app(ContainerApps::class)->update($row, (string) $request->input('digest', ''), (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['update' => $e->getMessage()]);
        }

        return redirect("/operation?operation={$id}");
    }

    private function row(Request $request): object
    {
        return DB::table('sites')->where('organization_id', Org::id())->where('id', (int) $request->input('site'))->whereIn('runtime', [Runtimes::DOCKERFILE, Runtimes::IMAGE])->first() ?? abort(404);
    }

    /** @return array<string, string> "field: message" becomes the field's own error. */
    private function fieldError(\InvalidArgumentException $e): array
    {
        [$field, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, $e->getMessage());

        return [$field => $message];
    }

    /** @return array<string, mixed> */
    private function view(int $site): array
    {
        $row = DB::table('sites')->where('organization_id', Org::id())->where('id', $site)->whereIn('runtime', [Runtimes::DOCKERFILE, Runtimes::IMAGE])->first();
        if ($row === null) {
            return ['missing' => true];
        }
        $s = ContainerApps::state($row);

        return ['missing' => false, 'id' => $row->id, 'name' => $row->name, 'kind' => $s['kind'], 'image' => $s['image_ref'], 'digest' => $s['digest'],
            'dockerfile' => $s['dockerfile'], 'context' => $s['context'], 'port' => $s['container_port'], 'health' => $s['health'], 'memory' => $s['memory'],
            'readOnly' => (bool) $s['read_only'], 'volumes' => $s['volumes'], 'hostPort' => $s['host_port'],
            'registry' => $s['registry'] === null ? null : ['server' => $s['registry']['server'], 'user' => $s['registry']['user']],
            'candidate' => $s['candidate'], 'kept' => array_map(fn ($i) => ['image' => $i['image'], 'commit' => $i['commit'] ?? '', 'at' => $i['at'] ?? ''], $s['images']),
            // The settings form's starting values: what is saved.
            'form' => ['port' => (string) $s['container_port'], 'health' => (string) $s['health'], 'memory' => (string) $s['memory'], 'dockerfile' => (string) $s['dockerfile'],
                'context' => (string) $s['context'], 'volumes' => implode("\n", $s['volumes']), 'readOnly' => (bool) $s['read_only']],
            'canEdit' => Gate::allows('edit'), 'canDeploy' => Gate::allows('deploy'), 'pending' => $row->pending_since !== null];
    }
}
