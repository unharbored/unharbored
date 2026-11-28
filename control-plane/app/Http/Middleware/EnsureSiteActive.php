<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Lib\Servers\ServerArchiving;
use App\Lib\Sites\Archiving;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * An archived application, server or database cluster can still be read, but nothing that writes may name it: the one
 * guard for every console action, found by the `site`, `node` or `cluster` in the route (or the `site` in the request).
 * Restore is the only way through, plus the ways a server or cluster ends its life (delete, disconnect, destroy).
 */
final class EnsureSiteActive
{
    /** Routes that name an archived server or cluster and are let through. */
    private const ENDS_OR_RESTORES = ['servers.restore', 'servers.delete', 'servers.destroy', 'servers.disconnect', 'servers.stale', 'servers.sudo.reveal', 'databases.restore', 'databases.destroy'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe()) {
            if (! $request->routeIs('site.settings.restore')) {
                $site = $request->route('site') ?? $request->input('site');
                if (is_numeric($site)) {
                    Archiving::refuseIfArchived((int) $site);
                }
            }
            if (! $request->routeIs(...self::ENDS_OR_RESTORES)) {
                if (is_string($node = $request->route('node'))) {
                    ServerArchiving::refuseIfNodeArchived($node);
                }
                if (is_numeric($cluster = $request->route('cluster'))) {
                    ServerArchiving::refuseIfClusterArchived((int) $cluster);
                }
            }
        }

        return $next($request);
    }
}
