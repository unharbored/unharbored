<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Auth\Roles;
use App\Lib\Operations\Present;
use App\Lib\Services\ServiceBackups;
use App\Lib\Services\Services;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * One template service: where it is, its addresses, Start/Stop/Restart, its generated credentials
 * (revealed on request, audited), its backups and updates, its recent operations and removal with the
 * data question. A revealed value goes to the browser once, in the answer to the reveal, and is never
 * part of the page's data.
 */
final class ServiceController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('view');
        $id = (int) $request->query('service');

        return Inertia::render('Service', ['service' => $id, 'view' => fn () => $this->view($id)]);
    }

    /** @return array<string, mixed> */
    private function view(int $service): array
    {
        $found = app(Services::class)->describe(Org::id(), null, $service)[0] ?? null;
        if ($found === null) {
            return ['missing' => true];
        }
        $def = null;
        try {
            $def = app(Services::class)->definition($found['template']);
        } catch (\Throwable) {
        }
        $row = DB::table('services')->where('organization_id', Org::id())->where('id', $service)->first();
        $u = app(Services::class)->updateAvailable($row);
        $update = $u === null ? null : [
            'version' => $u['version'], 'from' => $u['from'], 'allowed' => $u['allowed'], 'refused' => $u['refused'], 'confirm' => $u['confirm'], 'changes' => $u['changes'],
            'ports' => $u['reapprove']['ports'], 'acknowledge' => $u['reapprove']['acknowledge'],
            'inputs' => array_map(fn ($i) => ['key' => $i['key'], 'label' => $i['label'], 'secret' => $i['type'] === 'secret', 'default' => $i['default'] ?? null, 'options' => $i['options'] ?? []], $u['new_inputs']),
        ];
        $backups = array_map(fn ($b) => ServiceBackups::describe($b) + ['ago' => Present::ago($b->created_at), 'size' => ServiceBackups::size_label((int) $b->bytes)], app(ServiceBackups::class)->list(Org::id(), $service));
        $ops = DB::table('operations')->where('organization_id', Org::id())->where('meta->service_id', $service)->orderByDesc('id')->limit(8)->get()
            ->map(fn ($o) => ['id' => (int) $o->id, 'title' => $o->title, 'badge' => Present::badge($o), 'by' => Present::startedBy($o), 'ago' => Present::ago($o->created_at)])->all();

        return $found + [
            'missing' => false,
            'template_name' => $def?->name ?? $found['template'],
            'secrets' => array_column($def?->secrets ?? [], 'key'),
            'update_detail' => $update,
            'backups' => $backups,
            'backup_choice' => ServiceBackups::choice($row),
            'busy' => $found['operation'] !== null || in_array($found['status'], ['installing', 'removing'], true),
            'operations' => $ops,
        ];
    }

    private function mine(int $service): object
    {
        return DB::table('services')->where('organization_id', Org::id())->where('id', $service)->first() ?? abort(404);
    }

    /** The operation a button queued, followed on its own page. */
    private function toOperation(int $id): SymfonyResponse
    {
        return Inertia::location("/operation?operation={$id}");
    }

    private function role(Request $request): string
    {
        return (string) Roles::roleOf($request->user(), Org::id());
    }

    public function act(Request $request, int $service): SymfonyResponse
    {
        Gate::authorize('deploy');
        $row = $this->mine($service);
        try {
            $id = app(Services::class)->action(Org::id(), $row->node_id, $row->name, (string) $request->input('action'), (string) $request->user()->email, $this->role($request), true, 'console');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['action' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    public function backUp(Request $request, int $service): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $row = $this->mine($service);
        try {
            $id = app(Services::class)->backup(Org::id(), $row->node_id, $row->name, 'manual', (string) $request->user()->email, $this->role($request), true, 'console');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['backup' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    /** Updates to the newer version. The approvals are the person's ticks; the service refuses what they have not given. */
    public function update(Request $request, int $service): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $row = $this->mine($service);
        $inputs = array_filter((array) $request->input('inputs', []), fn ($v) => is_scalar($v) && trim((string) $v) !== '');
        try {
            $id = app(Services::class)->update(Org::id(), $row->node_id, $row->name, $inputs, (string) $request->user()->email, $this->role($request), $request->boolean('confirmed'), $request->boolean('ports'), $request->boolean('ack'), true, 'console');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['update' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    public function restore(Request $request, int $service): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $row = $this->mine($service);
        try {
            $id = app(Services::class)->restore(Org::id(), $row->node_id, $row->name, (int) $request->input('backup'), (string) $request->input('typed'), (string) $request->user()->email, $this->role($request), true, 'console');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['restore' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    /** One generated secret, audited as service.secret_revealed. */
    public function reveal(Request $request, int $service): JsonResponse
    {
        Gate::authorize('view.secrets');
        $this->mine($service);
        try {
            $value = app(Services::class)->reveal(Org::id(), $service, (string) $request->input('key'), Actor::user((string) $request->user()->id, $request->user()->email), $this->role($request));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['reveal' => $e->getMessage()]);
        }

        return response()->json(['value' => $value])->header('Cache-Control', 'no-store');
    }

    /** Removes the service. The data question must be answered: keep or delete, and deleting needs the name typed. */
    public function remove(Request $request, int $service): SymfonyResponse
    {
        Gate::authorize('infrastructure');
        $row = $this->mine($service);
        $choice = (string) $request->input('choice');
        if (! in_array($choice, ['keep', 'delete'], true)) {
            throw ValidationException::withMessages(['dataChoice' => 'Choose what happens to the data: keep it, or delete it.']);
        }
        try {
            $id = app(Services::class)->remove(Org::id(), $row->node_id, $row->name, $choice === 'delete', (string) $request->user()->email, $this->role($request), true, 'console');
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['dataChoice' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }
}
