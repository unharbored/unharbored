<?php

namespace App\Http\Middleware;

use App\Lib\Auth\Org;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A signed-in person who belongs to no organisation (removed from the last one)
 * sees nothing: there is no organisation for them to act in.
 */
final class EnsureMember
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if ($user !== null && Org::mine($user->id) === []) {
            abort(403, 'You do not belong to a team. Ask an Owner to invite you.');
        }

        return $next($request);
    }
}
