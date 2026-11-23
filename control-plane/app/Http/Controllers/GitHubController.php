<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\GitHub\GitHubApp;
use App\Lib\Operations\OperationRunner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The GitHub App's three public endpoints: the start and end of the manifest
 * flow, and the webhook.
 *
 * Setup is not behind a login (there are no logins yet). It is guarded by a
 * one-time state that only github:setup can mint, so nobody else can attach
 * an app of their own to an organisation through the callback.
 */
final class GitHubController
{
    public const STATE_TTL_SECONDS = 1800;

    public function __construct(private readonly GitHubApp $github) {}

    /** Hands the browser to GitHub with the manifest filled in. */
    public function setup(Request $request): Response
    {
        $state = (string) $request->query('state', '');
        $organizationId = Cache::get(self::stateKey($state));

        if (! is_string($organizationId)) {
            return response('This setup link has expired or was already used. Run github:setup again.', 410);
        }

        $manifest = e((string) json_encode($this->github->manifest($organizationId, (string) config('app.url'))));
        $action = 'https://github.com/settings/apps/new?state='.urlencode($state);

        return response(<<<HTML
<!doctype html><meta charset="utf-8"><title>Create GitHub App</title>
<form method="post" action="{$action}">
<input type="hidden" name="manifest" value="{$manifest}">
<p>Creating the Unharbored GitHub App for <b>{$organizationId}</b>.</p>
<button type="submit">Continue to GitHub</button>
</form>
<script>document.forms[0].submit()</script>
HTML);
    }

    /** GitHub redirects here with a single-use code once the app exists. */
    public function callback(Request $request): Response
    {
        // pull, not get: a state works once.
        $organizationId = Cache::pull(self::stateKey((string) $request->query('state', '')));
        $code = (string) $request->query('code', '');

        if (! is_string($organizationId) || ! preg_match('/^[A-Za-z0-9_-]{1,100}$/', $code)) {
            return response('This setup link has expired or was already used. Run github:setup again.', 410);
        }

        try {
            $app = $this->github->completeSetup($organizationId, $code);
        } catch (Throwable $e) {
            return response('GitHub setup failed: '.e($e->getMessage()), 502);
        }

        $install = e("https://github.com/apps/{$app->slug}/installations/new");

        return response(<<<HTML
<!doctype html><meta charset="utf-8"><title>GitHub App created</title>
<p>Created <b>{$app->slug}</b> for {$organizationId}.</p>
<p>Next, <a href="{$install}">install it on the repositories you deploy</a>. Anything you install it on is approved automatically.</p>
<p>To use repositories in a GitHub organisation as well as your own, make the app public first (GitHub, Settings, Developer settings, GitHub Apps, this app, Advanced, Make public), or GitHub will only offer your own account. Only installs made by you are used.</p>
HTML);
    }

    public function webhook(Request $request, OperationRunner $runner, string $organization): JsonResponse
    {
        $body = $request->getContent();

        if (! $this->github->verifySignature($organization, $body, (string) $request->header('X-Hub-Signature-256', ''))) {
            return response()->json(['error' => 'bad signature'], 401);
        }

        $event = (string) $request->header('X-GitHub-Event', '');
        $payload = json_decode($body, true) ?: [];

        if ($event === 'installation') {
            return response()->json(['installation' => $this->github->installationEvent($organization, $payload)]);
        }
        if ($event === 'pull_request') {
            $result = app(\App\Lib\Sites\Previews::class)->pullRequest($organization, $payload);

            return response()->json($result, $result['previews'] === [] ? 200 : 202);
        }
        if ($event !== 'push') {
            return response()->json(['ignored' => $event]);
        }

        // A push that deletes the branch has nothing to deploy.
        if (($payload['deleted'] ?? false) || ! str_starts_with((string) ($payload['ref'] ?? ''), 'refs/heads/')) {
            return response()->json(['ignored' => 'not a branch update']);
        }

        $branch = substr((string) $payload['ref'], strlen('refs/heads/'));
        $repository = strtolower((string) ($payload['repository']['full_name'] ?? ''));

        $sites = DB::table('sites')
            ->where('organization_id', $organization)
            ->where('push_to_deploy', true)
            ->where('branch', $branch)
            ->get()
            ->filter(function ($site) use ($repository) {
                if ($site->repository === null) {
                    return false; // an image application has no repository
                }
                try {
                    return strtolower(GitHubApp::fullName($site->repository)) === $repository;
                } catch (Throwable) {
                    return false;
                }
            });

        // Deploys take longer than GitHub waits for a response, so they run
        // on the queue as operations, like any other deploy.
        foreach ($sites as $site) {
            $sha = (string) ($payload['after'] ?? '');
            $runner->queueDeploy((int) $site->id, 'push', (string) ($payload['pusher']['name'] ?? 'github'), preg_match('/^[0-9a-f]{40}$/', $sha) ? substr($sha, 0, 12) : null);
        }

        return response()->json(['queued' => $sites->pluck('name')->values()], 202);
    }

    public static function stateKey(string $state): string
    {
        return 'github-setup:'.hash('sha256', $state);
    }
}
