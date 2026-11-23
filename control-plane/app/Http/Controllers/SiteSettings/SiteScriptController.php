<?php

declare(strict_types=1);

namespace App\Http\Controllers\SiteSettings;

use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

/** The server setup script, run as root on every server that joins an application. */
final class SiteScriptController extends SiteSettingsBase
{
    /** One saved version's text, to put back in the editor. */
    public function version(int $site, int $version): JsonResponse
    {
        Gate::authorize('view');

        return response()->json(['text' => Sites::serverScriptVersion($this->row($site), $version) ?? '']);
    }

    /** Keeps a new version of the script, and queues running it on every server when asked. */
    public function store(Request $request, int $site): Response
    {
        Gate::authorize('edit');
        $row = $this->row($site);
        try {
            app(Sites::class)->saveServerScript($row, (string) $request->input('text', ''), $this->email());
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['script' => $e->getMessage()]);
        }
        if ($request->boolean('run')) {
            return $this->toOperation(app(OperationRunner::class)->queueServerScript((int) $row->id, 'console', $this->email()));
        }

        return $this->saved('Script saved. It runs on a server the first time it joins this application.');
    }
}
