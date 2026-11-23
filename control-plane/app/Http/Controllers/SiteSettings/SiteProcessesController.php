<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Sites\Sites;
use App\Lib\Sites\WorkerScaling;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** Queue workers, Reverb and background processes. */
final class SiteProcessesController extends SiteSettingsBase
{
    public function workers(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $workers = $request->validate(['workers' => 'integer|min:0|max:20'], [], ['workers' => 'workers'])['workers'];
        app(Sites::class)->setWorkers($this->row($site), (int) $workers);

        return $this->saved('Workers saved. They change on the next deploy.');
    }

    public function scaling(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        try {
            app(WorkerScaling::class)->enable($this->row($site), $request->integer('min'), $request->integer('max'));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['workers' => $e->getMessage()]);
        }

        return $this->saved('Worker scaling saved. It starts on the next deploy.');
    }

    public function fixed(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        app(WorkerScaling::class)->disable($this->row($site), max(0, min(20, $request->integer('workers'))));

        return $this->saved('Workers saved as a fixed count. They change on the next deploy.');
    }

    public function reverb(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        $request->boolean('on') ? app(Sites::class)->enableReverb($row) : app(Sites::class)->disableReverb($row);

        return $this->saved('Reverb saved. It changes on the next deploy.');
    }

    public function addDaemon(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $data = $request->validate([
            'daemonName' => 'required|string|max:30',
            'daemonCommand' => 'required|string|max:500',
        ], [], ['daemonName' => 'daemonName', 'daemonCommand' => 'daemonCommand']);
        $port = (string) $request->input('daemonPort', '');
        $path = (string) $request->input('daemonPath', '');
        try {
            app(Sites::class)->setDaemon($this->row($site), $data['daemonName'], $data['daemonCommand'], 1, trim($port) === '' ? null : (int) $port, trim($path) === '' ? null : $path);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['daemonName' => $e->getMessage()]);
        }

        return $this->saved('Daemon saved. It starts on the next deploy.');
    }

    public function removeDaemon(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $name = (string) $request->input('name', '');
        app(Sites::class)->removeDaemon($this->row($site), $name);

        return $this->saved("Daemon {$name} removed. It stops on the next deploy.");
    }
}
