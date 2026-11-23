<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The team's projects: create, rename, delete (only when empty). The Default
 * project stays. Gated by the same permission as creating an application.
 */
final class ProjectsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('create');
        try {
            Projects::create(Org::id(), (string) $request->input('name'), $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['newName' => $this->message($e)]);
        }

        return back();
    }

    public function update(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('create');
        try {
            Projects::rename(Org::id(), $id, (string) $request->input('name'), $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['editName' => $this->message($e)]);
        }

        return back();
    }

    public function destroy(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('create');
        try {
            Projects::delete(Org::id(), $id, $this->actor($request));
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['delete' => $this->message($e)]);
        }

        return back();
    }

    private function actor(Request $request): Actor
    {
        return Actor::user((string) $request->user()->id, (string) $request->user()->email);
    }

    private function message(\InvalidArgumentException $e): string
    {
        return (string) preg_replace('/^\w+: /', '', $e->getMessage());
    }
}
