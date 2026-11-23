<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Lib\Api\ApiTokens;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Bearer token for /api/v1, with the scope a route needs: api.token:deploy. */
final class ApiToken
{
    public function __construct(private readonly ApiTokens $tokens) {}

    public function handle(Request $request, Closure $next, string $scope = 'read'): Response
    {
        $token = $this->tokens->find((string) $request->bearerToken());
        if ($token === null) {
            return response()->json(['error' => 'A valid API token is needed: Authorization: Bearer cm_…'], 401);
        }
        if (! ApiTokens::allows($token, $scope)) {
            return response()->json(['error' => "This token cannot {$scope}. Make one with the {$scope} scope."], 403);
        }
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
