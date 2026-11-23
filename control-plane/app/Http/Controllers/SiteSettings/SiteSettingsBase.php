<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Http\Controllers\Controller;
use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/** What every Settings action shares: finding the application in this team, who is acting, and where an operation leads. */
abstract class SiteSettingsBase extends Controller
{
    protected function row(int $site): object
    {
        $row = DB::table('sites')->where('organization_id', Org::id())->where('id', $site)->first();
        abort_if($row === null, 404);

        return $row;
    }

    protected function email(): string
    {
        return (string) auth()->user()->email;
    }

    protected function actor(): Actor
    {
        return Actor::user((string) auth()->id(), $this->email());
    }

    /** To the page of the operation just queued (a full page load: it is a different page). */
    protected function toOperation(int $id): Response
    {
        return Inertia::location("/operation?operation={$id}");
    }

    protected function saved(string $message): RedirectResponse
    {
        return back()->with('status', $message);
    }
}
