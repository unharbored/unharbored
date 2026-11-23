<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Operations\OperationRunner;
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
