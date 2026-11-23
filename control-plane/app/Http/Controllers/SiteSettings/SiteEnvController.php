<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Audit\AuditLog;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Runtimes;
use App\Lib\Sites\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Environment variables: the user's own, and what Unharbored injects. */
final class SiteEnvController extends SiteSettingsBase
{
    /** The user's own variables, for editing. Reading them is audited like any secret. */
    public function reveal(int $site): JsonResponse
    {
        Gate::authorize('view.secrets');
        $row = $this->row($site);
        app(AuditLog::class)->record($row->organization_id, $this->actor(),
            'site.env_read', resourceType: 'site', resourceId: "{$row->node_id}/{$row->name}");

        return response()->json(['text' => app(Sites::class)->customEnv($row)]);
    }

    /** The injected values, in full. Audited the same way as reading the custom variables. */
    public function revealInjected(int $site): JsonResponse
    {
        Gate::authorize('view.secrets');
        $row = $this->row($site);
        app(AuditLog::class)->record($row->organization_id, $this->actor(),
            'site.env_read', resourceType: 'site', resourceId: "{$row->node_id}/{$row->name}", metadata: ['layer' => 'injected']);

        return response()->json(['values' => array_map(fn ($e) => $e['value'], app(Sites::class)->injected($row))]);
    }

    public function save(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $request->merge(['envText' => (string) $request->input('envText', '')]);
        $env = $request->validate(['envText' => 'required|string|max:65535'], [], ['envText' => 'envText'])['envText'];
        if (str_contains($env, '••••')) {
            throw ValidationException::withMessages(['envText' => 'That still has masked values in it. Reveal before editing.']);
        }
        app(Sites::class)->setEnv($this->row($site), $env, 'edited in the console by '.$this->email());

        return $this->saved('.env saved. It reaches the server on the next deploy.');
    }

    /** Puts the saved variables on the running application now, without a deploy. */
    public function apply(int $site): Response
    {
        Gate::authorize('deploy');
        $row = $this->row($site);
        if (! Runtimes::has($row, 'env-file') || $row->current_release === null) {
            throw ValidationException::withMessages(['envText' => 'This application needs a deploy to pick up its variables.']);
        }

        return $this->toOperation(app(OperationRunner::class)->queueEnvApply($row->id, 'console', $this->email()));
    }

    public function restore(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $version = $request->integer('version');
        try {
            app(Sites::class)->restoreEnvVersion($this->row($site), $version);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['envText' => $e->getMessage()]);
        }

        return $this->saved("Version {$version} of .env is back, as the newest version. It reaches the server on the next deploy.");
    }

    /** Drops one of the user's variables, so the injected value shows through again. */
    public function removeOverride(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $key = (string) $request->input('key');
        $row = $this->row($site);
        $sites = app(Sites::class);
        $mine = Sites::envKeys($sites->customEnv($row));
        if (! isset($mine[$key]) || ! in_array($key, $sites->overriddenKeys($row), true)) {
            throw ValidationException::withMessages(['envText' => "{$key} is not overriding anything."]);
        }
        $sites->setEnv($row, (string) preg_replace('/^'.preg_quote($key, '/').'=.*\R?/m', '', $sites->customEnv($row)),
            "removed the override of {$key} in the console by ".$this->email());

        return $this->saved("{$key} is no longer overridden. The injected value reaches the server on the next deploy.");
    }

    /** Takes out of the custom variables what only repeats a value Unharbored injects. */
    public function cleanCopies(int $site): RedirectResponse
    {
        Gate::authorize('edit');
        $removed = app(Sites::class)->removeEnvCopies($this->row($site));

        return $this->saved(count($removed).' repeated '.(count($removed) === 1 ? 'variable' : 'variables').' removed. What Unharbored injects reaches the server on the next deploy.');
    }
}
