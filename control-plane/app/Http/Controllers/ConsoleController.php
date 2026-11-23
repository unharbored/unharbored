<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Console\Consoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * The Commands page, and the calls a console makes from the browser. What a
 * console shows arrives on its private channel, not here.
 */
final class ConsoleController extends Controller
{

    public function __construct(private readonly Consoles $consoles) {}

    public function show(Request $request): Response
    {
        \Illuminate\Support\Facades\Gate::authorize('console');
        $site = DB::table('sites')->where('organization_id', \App\Lib\Auth\Org::id())->where('id', (int) $request->query('site'))->first();
        abort_if($site === null, 404);

        $servers = [];
        $gatewayUp = true;
        try {
            $servers = $this->consoles->servers($site);
        } catch (\Throwable) {
            $gatewayUp = false;
        }

        return Inertia::render('SiteConsole', [
            'site' => ['id' => $site->id, 'name' => $site->name],
            'enabled' => Consoles::enabled(Org::id()),
            'servers' => $servers,
            'gatewayUp' => $gatewayUp,
            'sessions' => DB::table('console_sessions')->where('site_id', $site->id)->orderByDesc('opened_at')->limit(20)->get()->map(fn ($c) => [
                'id' => $c->id, 'as_user' => $c->as_user, 'opened_by_name' => $c->opened_by_name,
                'opened' => \Illuminate\Support\Carbon::parse($c->opened_at)->diffForHumans(),
                'open' => $c->closed_at === null, 'ended_because' => $c->ended_because,
            ])->all(),
            'socketUrl' => Consoles::socketUrl(),
        ]);
    }

    public function open(Request $request): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('console');
        $data = $request->validate([
            'site' => 'nullable|integer',
            'node' => 'required|string|max:255',
            'as_user' => 'required|in:deploy,root',
        ]);
        $site = null;
        if (isset($data['site'])) {
            $site = DB::table('sites')->where('organization_id', Org::id())->where('id', $data['site'])->first();
            abort_if($site === null, 404);
        }

        $user = $request->user();
        try {
            $id = $this->consoles->open(Org::id(), $site, $data['node'], $data['as_user'], Actor::user((string) $user->id, $user->email), (string) $user->name, $request->ip());
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['session' => $id]);
    }

    public function start(Request $request, string $session): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('console');
        $this->mine($request, $session);
        $data = $request->validate(['cols' => 'required|integer|min:10|max:500', 'rows' => 'required|integer|min:3|max:200']);

        try {
            $this->consoles->start($session, $data['cols'], $data['rows']);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['started' => true]);
    }

    public function input(Request $request, string $session): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('console');
        $this->mine($request, $session);
        $data = $request->validate(['data' => 'required|string|max:65536']);

        return $this->sent(fn () => $this->consoles->send($session, ['op' => 'input', 'data' => $data['data']]));
    }

    public function resize(Request $request, string $session): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('console');
        $this->mine($request, $session);
        $data = $request->validate(['cols' => 'required|integer|min:10|max:500', 'rows' => 'required|integer|min:3|max:200']);

        return $this->sent(fn () => $this->consoles->send($session, ['op' => 'resize'] + $data));
    }

    public function close(Request $request, string $session): JsonResponse
    {
        \Illuminate\Support\Facades\Gate::authorize('console');
        $this->mine($request, $session);
        $user = $request->user();
        $this->consoles->close($session, Org::id(), Actor::user((string) $user->id, $user->email));

        return response()->json(['closed' => true]);
    }

    /** A console is the opener's alone, even to others in the organisation. */
    private function mine(Request $request, string $session): void
    {
        abort_unless(Consoles::belongsTo($session, (string) $request->user()->id), 404);
    }

    private function sent(\Closure $send): JsonResponse
    {
        try {
            $send();
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 410);
        }

        return response()->json(['sent' => true]);
    }
}
