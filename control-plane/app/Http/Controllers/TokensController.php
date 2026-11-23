<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Api\ApiTokens;
use App\Lib\Auth\Org;
use App\Lib\Operations\Present;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** API tokens for the command line, the MCP server and the REST API. */
final class TokensController extends Controller
{
    public function index(Request $request): Response
    {
        Gate::authorize('tokens.read');

        return Inertia::render('Tokens', [
            // This console's address, for the login line.
            'base' => rtrim((string) config('app.url'), '/'),
            'tokens' => fn () => DB::table('api_tokens')->where('organization_id', Org::id())->whereNull('revoked_at')->orderByDesc('id')->get()
                ->map(fn ($t) => ['id' => $t->id, 'name' => $t->name, 'prefix' => $t->prefix, 'scope' => last(json_decode($t->scopes, true) ?: ['read']),
                    'by' => $t->created_by, 'created' => Present::ago($t->created_at), 'used' => $t->last_used_at ? Present::ago($t->last_used_at) : 'never'])->all(),
            // The token just made: shown once, on the next page only.
            'made' => $request->session()->get('made', ''),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('tokens.create');
        $data = $request->validate(['name' => 'nullable|string', 'scope' => 'required|string']);
        try {
            $token = app(ApiTokens::class)->issue(Org::id(), (string) ($data['name'] ?? ''), $data['scope'], (string) $request->user()->email)['token'];
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['name' => $e->getMessage()]);
        }

        return back()->with('made', $token);
    }

    public function revoke(Request $request, int $id): RedirectResponse
    {
        Gate::authorize('tokens.create');
        app(ApiTokens::class)->revoke(Org::id(), $id, (string) $request->user()->email);

        return back();
    }
}
