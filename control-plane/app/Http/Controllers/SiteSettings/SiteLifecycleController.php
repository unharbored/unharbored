<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Archiving;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** Deploying the pending changes, moving an application between projects, and deleting it. */
final class SiteLifecycleController extends SiteSettingsBase
{
    public function deploy(int $site): Response
    {
        Gate::authorize('deploy');

        return $this->toOperation(app(OperationRunner::class)->queueDeploy($this->row($site)->id, 'console', $this->email()));
    }

    /** Moves the application, and its previews with it, to another project. */
    public function moveProject(Request $request, int $site): RedirectResponse
    {
        Gate::authorize('create');
        try {
            Projects::move(Org::id(), $site, $request->integer('project'), $this->actor());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['moveProject' => $e->getMessage()]);
        }

        return $this->saved('Moved. Its previews moved with it.');
    }

    /** Stops managing the application; nothing on its server changes. */
    public function archive(int $site): RedirectResponse
    {
        Gate::authorize('create');
        app(Archiving::class)->archive(Org::id(), $site, $this->actor());

        return $this->saved('Archived. It keeps running on its server; Unharbored no longer manages it.');
    }

    public function restore(int $site): RedirectResponse
    {
        Gate::authorize('create');
        try {
            app(Archiving::class)->restore(Org::id(), $site, $this->actor());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['archived' => $e->getMessage()]);
        }

        return $this->saved('Restored. Unharbored manages it again.');
    }

    public function delete(Request $request, int $site): Response
    {
        Gate::authorize('create');
        abort_unless(DB::table('sites')->where('organization_id', Org::id())->where('id', $site)->whereNull('parent_site_id')->exists(), 404);

        try {
            return $this->toOperation(app(OperationRunner::class)->queueAppDelete($site, $request->boolean('deleteData'), 'console', $this->email()));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['delete' => $e->getMessage()]);
        }
    }
}
