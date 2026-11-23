<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Git\GitPush;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Push-to-deploy for a repository on any host that sends webhooks. The
 * address carries the application and its secret; the host-specific proof in
 * the headers, where there is one, is checked on top. A wrong secret and an
 * unknown application answer alike.
 */
final class GitWebhookController
{
    public function __construct(private readonly Sites $sites) {}

    public function push(Request $request, OperationRunner $runner, int $site, string $secret): JsonResponse
    {
        $row = DB::table('sites')->where('id', $site)->first();
        if ($row === null || ! hash_equals($this->sites->webhookSecret($row), $secret)) {
            return response()->json(['error' => 'not found'], 404);
        }

        $headers = [];
        foreach ($request->headers->all() as $name => $values) {
            $headers[strtolower((string) $name)] = (string) ($values[0] ?? '');
        }
        $push = GitPush::parse($headers, $request->getContent(), json_decode($request->getContent(), true) ?: [], $secret);
        if ($push === null) {
            return response()->json(['ignored' => 'not a push to a branch, or its signature was wrong']);
        }
        if (! $row->push_to_deploy || $push['branch'] !== $row->branch) {
            return response()->json(['ignored' => 'not the branch this application deploys']);
        }

        $id = $runner->queueDeploy((int) $row->id, 'push', $push['host'], $push['sha'] !== null ? substr($push['sha'], 0, 12) : null);

        return response()->json(['queued' => $row->name, 'operation' => $id], 202);
    }
}
