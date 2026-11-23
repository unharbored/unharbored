<?php

declare(strict_types=1);

namespace App\Lib\GitHub;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use App\Lib\Vault\Vault;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;

/**
 * The organisation's GitHub App: how nodes fetch code without holding any
 * long-lived credential, and how pushes reach the control plane.
 *
 * The app is created through GitHub's manifest flow, so its private key and
 * webhook secret go from GitHub straight into the vault and are never copied
 * by hand. For each deploy the control plane mints an installation token
 * scoped to the one repository with read-only contents access; it expires
 * within the hour.
 */
final class GitHubApp
{
    private const API = 'https://api.github.com';

    public function __construct(
        private readonly HttpFactory $http,
        private readonly Vault $vault,
        private readonly AuditLog $audit,
    ) {}

    /**
     * What GitHub creates the app from. Read-only contents and pull requests, push and pull request events
     * only, private to the account that creates it.
     *
     * @return array<string, mixed>
     */
    public function manifest(string $organizationId, string $baseUrl): array
    {
        $base = rtrim($baseUrl, '/');

        return [
            // App names are global on GitHub, so a suffix avoids collisions.
            'name' => sprintf('cloud-manager %s %s', $organizationId, bin2hex(random_bytes(2))),
            'url' => $base,
            'hook_attributes' => ['url' => "{$base}/api/github/webhook/{$organizationId}", 'active' => true],
            'redirect_url' => "{$base}/github/callback",
            'public' => false,
            // Pull requests read only: enough to hear them open and close.
            'default_permissions' => ['contents' => 'read', 'metadata' => 'read', 'pull_requests' => 'read'],
            'default_events' => ['push', 'pull_request'],
        ];
    }

    /**
     * Exchanges the single-use code GitHub redirects back with for the app's
     * credentials, and stores them.
     */
    public function completeSetup(string $organizationId, string $code): stdClass
    {
        // No body at all. Laravel's post() sends "[]" for no data, and GitHub
        // rejects that with 422 even though the code is valid, which is how
        // the first real setup failed.
        $response = $this->http->accept('application/vnd.github+json')->timeout(20)
            ->send('POST', self::API."/app-manifests/{$code}/conversions");

        if (! $response->successful()) {
            throw new RuntimeException(sprintf('GitHub refused the manifest code (%d): %s', $response->status(), $response->json('message') ?? 'no detail'));
        }

        return $this->store($organizationId, $response->json());
    }

    /**
     * Stores the app GitHub created: secrets in the vault, the rest here.
     *
     * @param  array<string, mixed>  $app  GitHub's manifest conversion response.
     */
    public function store(string $organizationId, array $app): stdClass
    {
        $this->vault->put($organizationId, 'github:app_private_key', (string) $app['pem']);
        $this->vault->put($organizationId, 'github:webhook_secret', (string) $app['webhook_secret']);

        DB::table('github_apps')->updateOrInsert(
            ['organization_id' => $organizationId],
            [
                'app_id' => (int) $app['id'],
                'slug' => (string) $app['slug'],
                'owner' => (string) ($app['owner']['login'] ?? ''),
                'html_url' => (string) $app['html_url'],
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        $this->audit->record(
            organizationId: $organizationId,
            actor: Actor::system('control-plane'),
            action: 'github.app_created',
            resourceType: 'github_app',
            resourceId: (string) $app['id'],
            metadata: ['slug' => $app['slug'], 'owner' => $app['owner']['login'] ?? null],
        );

        return $this->find($organizationId);
    }

    /**
     * Whether an installation's account is one whose repositories we use: the
     * account that owns the app, one approved when its owner installed the app
     * there (see installationEvent), or one listed in GITHUB_ALLOWED_ACCOUNTS. A
     * public app (needed to install it on an organisation) can be installed by
     * anyone, and an installation that is not ours must never be listed or used.
     */
    public function trusts(string $organizationId, string $login): bool
    {
        $app = $this->find($organizationId);
        $manual = array_map(fn ($a) => strtolower(trim($a)), explode(',', (string) config('control_plane.github_allowed_accounts')));
        $approved = array_map('strtolower', (array) json_decode((string) ($app->accounts ?? ''), true));

        return $login !== '' && in_array(strtolower($login), array_filter([strtolower((string) ($app->owner ?? '')), ...$approved, ...$manual]), true);
    }

    /**
     * An install or uninstall of the app, as GitHub reports it. The account is
     * approved only when the installer is the app's own owner: that is the
     * person who made this app, so what they install it on is theirs. Anyone
     * else installing a public app changes nothing here.
     *
     * @param  array<string, mixed>  $payload
     * @return string what happened, for the response
     */
    public function installationEvent(string $organizationId, array $payload): string
    {
        $app = $this->find($organizationId);
        $account = (string) ($payload['installation']['account']['login'] ?? '');
        $sender = strtolower((string) ($payload['sender']['login'] ?? ''));
        if ($app === null || $account === '') {
            return 'ignored';
        }
        $accounts = array_values(array_filter((array) json_decode((string) ($app->accounts ?? ''), true), fn ($a) => is_string($a)));
        $has = in_array(strtolower($account), array_map('strtolower', $accounts), true);
        $action = (string) ($payload['action'] ?? '');

        if ($action === 'created' && $sender !== '' && $sender === strtolower((string) $app->owner)) {
            if (! $has) {
                $accounts[] = $account;
                DB::table('github_apps')->where('id', $app->id)->update(['accounts' => json_encode($accounts), 'updated_at' => now()]);
                $this->audit->record($organizationId, Actor::system('github'), 'github.account_approved', resourceType: 'github_app', resourceId: (string) $app->app_id, metadata: ['account' => $account]);
            }

            return 'approved';
        }
        if ($action === 'created') {
            $this->audit->record($organizationId, Actor::system('github'), 'github.installation_not_approved', outcome: Outcome::Denied, resourceType: 'github_app', resourceId: (string) $app->app_id,
                reason: "Installed on {$account} by someone other than the app's owner; its repositories are not used.", metadata: ['account' => $account, 'sender' => $sender]);

            return 'not approved';
        }
        if ($action === 'deleted' && $has) {
            DB::table('github_apps')->where('id', $app->id)->update(['accounts' => json_encode(array_values(array_filter($accounts, fn ($a) => strtolower($a) !== strtolower($account)))), 'updated_at' => now()]);

            return 'removed';
        }

        return 'ignored';
    }

    /**
     * Every account the app is installed on, and whether its repositories are used here.
     * An install made by someone other than the app's owner is listed but not used until
     * it is approved (approveAccount).
     *
     * @return array{install_url: string, accounts: list<array{login: string, type: string, approved: bool, manual: bool, owner: bool}>}
     */
    public function accounts(string $organizationId): array
    {
        $app = $this->find($organizationId);
        if ($app === null) {
            return ['install_url' => '', 'accounts' => []];
        }
        $out = [];
        foreach ($this->http->acceptJson()->withToken($this->jwt($organizationId))->timeout(20)->get(self::API.'/app/installations', ['per_page' => 100])->throw()->json() as $i) {
            $login = (string) ($i['account']['login'] ?? '');
            if ($login === '') {
                continue;
            }
            $out[] = ['login' => $login, 'type' => (string) ($i['account']['type'] ?? 'User'), 'approved' => $this->trusts($organizationId, $login),
                'manual' => in_array(strtolower($login), array_map(fn ($a) => strtolower(trim($a)), explode(',', (string) config('control_plane.github_allowed_accounts'))), true),
                'owner' => strtolower($login) === strtolower((string) $app->owner)];
        }

        return ['install_url' => "https://github.com/apps/{$app->slug}/installations/new", 'accounts' => $out];
    }

    /** Starts using an account's repositories: for an install that someone else made. */
    public function approveAccount(string $organizationId, string $login, Actor $by): void
    {
        $app = $this->find($organizationId) ?? throw new \InvalidArgumentException('The GitHub App is not set up.');
        $accounts = $this->approvedAccounts($app);
        if (! in_array(strtolower($login), array_map('strtolower', $accounts), true)) {
            $accounts[] = $login;
            DB::table('github_apps')->where('id', $app->id)->update(['accounts' => json_encode($accounts), 'updated_at' => now()]);
            $this->audit->record($organizationId, $by, 'github.account_approved', resourceType: 'github_app', resourceId: (string) $app->app_id, metadata: ['account' => $login]);
        }
    }

    /** Stops using an account's repositories. The installation stays on GitHub until it is removed there. */
    public function unapproveAccount(string $organizationId, string $login, Actor $by): void
    {
        $app = $this->find($organizationId) ?? throw new \InvalidArgumentException('The GitHub App is not set up.');
        $kept = array_values(array_filter($this->approvedAccounts($app), fn ($a) => strtolower($a) !== strtolower($login)));
        DB::table('github_apps')->where('id', $app->id)->update(['accounts' => json_encode($kept), 'updated_at' => now()]);
        $this->audit->record($organizationId, $by, 'github.account_removed', resourceType: 'github_app', resourceId: (string) $app->app_id, metadata: ['account' => $login]);
    }

    /** @return list<string> */
    private function approvedAccounts(stdClass $app): array
    {
        return array_values(array_filter((array) json_decode((string) ($app->accounts ?? ''), true), fn ($a) => is_string($a)));
    }

    public function find(string $organizationId): ?stdClass
    {
        return DB::table('github_apps')->where('organization_id', $organizationId)->first();
    }

    /**
     * A token that can read one repository's contents, valid for an hour.
     */
    public function installationToken(string $organizationId, string $fullName): string
    {
        $jwt = $this->jwt($organizationId);

        $installation = $this->http->acceptJson()->withToken($jwt)->timeout(20)
            ->get(self::API."/repos/{$fullName}/installation");
        if (! $installation->successful()) {
            throw new RuntimeException("The GitHub App is not installed on {$fullName}. Install it there, then try again.");
        }

        if (! $this->trusts($organizationId, (string) $installation->json('account.login'))) {
            throw new RuntimeException("The GitHub App is installed on {$installation->json('account.login')}, which is not an account you have approved. Add it to GITHUB_ALLOWED_ACCOUNTS.");
        }

        [, $repo] = explode('/', $fullName, 2);
        $token = $this->http->acceptJson()->withToken($jwt)->timeout(20)
            ->post(self::API."/app/installations/{$installation->json('id')}/access_tokens", [
                'repositories' => [$repo],
                'permissions' => ['contents' => 'read'],
            ]);
        if (! $token->successful()) {
            throw new RuntimeException("GitHub would not issue a token for {$fullName}: ".$token->status());
        }

        $this->audit->record(
            organizationId: $organizationId,
            actor: Actor::system('control-plane'),
            action: 'github.token_issued',
            resourceType: 'repository',
            resourceId: $fullName,
            metadata: ['expires_at' => $token->json('expires_at')],
        );

        return (string) $token->json('token');
    }

    /**
     * Every repository the app can read, across its installations, most
     * recently pushed first. Metadata only: the tokens asked for here can
     * read names, not code.
     *
     * @return list<array{full_name: string, pushed_at: ?string, default_branch: string, private: bool}>
     */
    public function repositories(string $organizationId): array
    {
        $jwt = $this->jwt($organizationId);
        $out = [];
        foreach ($this->http->acceptJson()->withToken($jwt)->timeout(20)->get(self::API.'/app/installations', ['per_page' => 100])->throw()->json() as $installation) {
            // A public app can be installed by anyone: only accounts approved here are ours.
            if (! $this->trusts($organizationId, (string) ($installation['account']['login'] ?? ''))) {
                continue;
            }
            $token = $this->http->acceptJson()->withToken($jwt)->timeout(20)
                ->post(self::API."/app/installations/{$installation['id']}/access_tokens", ['permissions' => ['metadata' => 'read']])->throw()->json('token');
            for ($page = 1; $page <= 10; $page++) {
                $repos = $this->http->acceptJson()->withToken((string) $token)->timeout(20)
                    ->get(self::API.'/installation/repositories', ['per_page' => 100, 'page' => $page])->throw()->json('repositories') ?? [];
                foreach ($repos as $r) {
                    $out[] = ['full_name' => (string) $r['full_name'], 'pushed_at' => $r['pushed_at'] ?? null,
                        'default_branch' => (string) ($r['default_branch'] ?? 'main'), 'private' => (bool) ($r['private'] ?? false)];
                }
                if (count($repos) < 100) {
                    break;
                }
            }
        }
        usort($out, fn ($a, $b) => strcmp((string) $b['pushed_at'], (string) $a['pushed_at']));

        return $out;
    }

    /** Whether the app is installed, on an account we trust, where it can read this repository. */
    public function canRead(string $organizationId, string $fullName): bool
    {
        try {
            $installation = $this->http->acceptJson()->withToken($this->jwt($organizationId))->timeout(20)->get(self::API."/repos/{$fullName}/installation");

            return $installation->successful() && $this->trusts($organizationId, (string) $installation->json('account.login'));
        } catch (\Throwable) {
            return false;
        }
    }

    /** @return list<string> The repository's branches, its default first. */
    public function branches(string $organizationId, string $fullName): array
    {
        $http = $this->http->acceptJson()->withToken($this->installationToken($organizationId, $fullName))->timeout(20);
        $default = (string) $http->get(self::API."/repos/{$fullName}")->throw()->json('default_branch');
        $names = array_column($http->get(self::API."/repos/{$fullName}/branches", ['per_page' => 100])->throw()->json() ?? [], 'name');

        return array_values(array_unique([$default, ...$names]));
    }

    /**
     * What a branch holds, read from its composer.json, package.json and
     * .env.example: enough to fill in a new application's settings.
     *
     * @return array{framework: ?string, php: ?string, frontend: ?string, env: array<string, string>}
     */
    public function detect(string $organizationId, string $fullName, string $branch, string $dir = ''): array
    {
        $read = $this->reader($organizationId, $fullName, $branch);

        return self::summarise($dir === '' ? $read : fn (string $file): ?string => $read("{$dir}/{$file}"));
    }

    /**
     * A way to read one file of a branch, with one token for all of them.
     *
     * @return callable(string): ?string
     */
    public function reader(string $organizationId, string $fullName, string $branch): callable
    {
        $http = $this->http->acceptJson()->withToken($this->installationToken($organizationId, $fullName))->timeout(20);

        return function (string $path) use ($http, $fullName, $branch): ?string {
            $response = $http->get(self::API."/repos/{$fullName}/contents/{$path}", ['ref' => $branch]);

            return $response->successful() ? base64_decode((string) $response->json('content'), false) ?: null : null;
        };
    }

    /**
     * Every file path on a branch, in one call. A very large repository is cut off by GitHub; what
     * came back is still returned.
     *
     * @return list<string>
     */
    public function paths(string $organizationId, string $fullName, string $branch): array
    {
        $response = $this->http->acceptJson()->withToken($this->installationToken($organizationId, $fullName))->timeout(20)
            ->get(self::API."/repos/{$fullName}/git/trees/".rawurlencode($branch), ['recursive' => 1]);

        return $this->blobPaths($response->successful() ? (array) $response->json('tree') : []);
    }

    /**
     * The same for a public repository, with no login. Null when GitHub will not say (its limit for
     * callers with no login is low).
     *
     * @return list<string>|null
     */
    public function publicPaths(string $fullName, string $branch): ?array
    {
        $response = $this->http->acceptJson()->timeout(15)->get(self::API."/repos/{$fullName}/git/trees/".rawurlencode($branch), ['recursive' => 1]);

        return $response->successful() ? $this->blobPaths((array) $response->json('tree')) : null;
    }

    /** @param list<array<string, mixed>> $tree @return list<string> */
    private function blobPaths(array $tree): array
    {
        return array_values(array_map(fn ($e) => (string) $e['path'], array_filter($tree, fn ($e) => ($e['type'] ?? null) === 'blob')));
    }

    /**
     * What a repository holds, from a way of reading its files: the same for a
     * GitHub App repository and for any public one.
     *
     * @param  callable(string): ?string  $read
     * @return array{framework: ?string, php: ?string, frontend: ?string, env: array<string, string>}
     */
    public static function summarise(callable $read): array
    {
        $composer = json_decode((string) $read('composer.json'), true) ?: [];
        $package = json_decode((string) $read('package.json'), true) ?: [];
        $require = $composer['require'] ?? [];
        $framework = match (true) {
            isset($require['laravel/framework']) => 'Laravel '.preg_replace('/^[^0-9]*/', '', (string) $require['laravel/framework']),
            isset($require['symfony/framework-bundle']) => 'Symfony',
            $composer !== [] => 'PHP',
            default => null,
        };
        $deps = ($package['dependencies'] ?? []) + ($package['devDependencies'] ?? []);
        $frontend = isset($package['scripts']['build']) ? (isset($deps['vite']) ? 'Vite' : 'npm').' · npm run build' : null;

        $env = [];
        foreach (preg_split('/\R/', (string) $read('.env.example')) as $line) {
            if (preg_match('/^([A-Z][A-Z0-9_]*)=(.*)$/', trim($line), $m)) {
                $env[$m[1]] = trim($m[2], "\"'");
            }
        }

        return ['framework' => $framework, 'php' => isset($require['php']) ? (string) $require['php'] : null, 'frontend' => $frontend, 'env' => $env];
    }

    /**
     * GitHub never retries a webhook it failed to deliver, so a push that
     * arrives while the console is briefly unreachable never deploys. This
     * reads the app's delivery log and asks GitHub to send again any push
     * none of whose attempts got through, up to three attempts each.
     * GitHub keeps deliveries redeliverable for three days.
     *
     * @return list<string> What was redelivered, for the log.
     */
    public function redeliverFailed(string $organizationId): array
    {
        $api = $this->http->acceptJson()->withToken($this->jwt($organizationId))->timeout(20);
        $response = $api->get(self::API.'/app/hook/deliveries', ['per_page' => 100]);
        if (! $response->successful()) {
            throw new RuntimeException('GitHub would not list webhook deliveries: '.$response->status());
        }

        $byGuid = [];
        foreach ($response->json() ?? [] as $d) {
            if (($d['event'] ?? null) === 'push' && strtotime((string) $d['delivered_at']) > time() - 3 * 86400 + 3600) {
                $byGuid[$d['guid']][] = $d;
            }
        }

        $done = [];
        foreach ($byGuid as $guid => $attempts) {
            $delivered = array_filter($attempts, fn ($d) => ($d['status_code'] ?? 0) >= 200 && ($d['status_code'] ?? 0) < 300);
            // A redelivery may still be on its way: GitHub lists it once tried.
            if ($delivered !== [] || count($attempts) >= 3) {
                continue;
            }
            usort($attempts, fn ($a, $b) => strcmp((string) $b['delivered_at'], (string) $a['delivered_at']));
            if (strtotime((string) $attempts[0]['delivered_at']) > time() - 60) {
                continue;
            }
            // No body at all: post() would send "[]", which GitHub refuses.
            $retry = $api->send('POST', self::API."/app/hook/deliveries/{$attempts[0]['id']}/attempts");
            $done[] = $retry->successful()
                ? "redelivered push {$guid} (attempt ".(count($attempts) + 1).", last failed: {$attempts[0]['status']})"
                : "could not redeliver push {$guid}: ".$retry->status();
            $this->audit->record($organizationId, Actor::system('control-plane'), 'github.webhook_redelivered',
                resourceType: 'webhook', resourceId: (string) $guid, metadata: ['ok' => $retry->successful()]);
        }

        return $done;
    }

    /**
     * Checks a webhook delivery came from GitHub, in constant time.
     */
    public function verifySignature(string $organizationId, string $body, string $signature): bool
    {
        $secret = $this->vault->get($organizationId, 'github:webhook_secret');
        if ($secret === null || ! str_starts_with($signature, 'sha256=')) {
            return false;
        }

        return hash_equals('sha256='.hash_hmac('sha256', $body, $secret), $signature);
    }

    /**
     * owner/repo from git@github.com:owner/repo.git.
     */
    public static function fullName(string $repository): string
    {
        if (! preg_match('#^git@github\.com:([A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?)(\.git)?$#', $repository, $m)) {
            throw new RuntimeException("Not a GitHub repository: {$repository}");
        }

        return $m[1];
    }

    /**
     * The app's own identity, signed with its private key. Ten minutes is
     * GitHub's maximum; the issue time is backdated for clock skew.
     */
    private function jwt(string $organizationId): string
    {
        $app = $this->find($organizationId) ?? throw new RuntimeException("No GitHub App is set up for {$organizationId}. Run github:setup.");
        $key = $this->vault->get($organizationId, 'github:app_private_key') ?? throw new RuntimeException('The GitHub App private key is missing from the vault.');

        $encode = static fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
        $unsigned = $encode(['alg' => 'RS256', 'typ' => 'JWT']).'.'.$encode(['iat' => time() - 60, 'exp' => time() + 540, 'iss' => (string) $app->app_id]);

        if (! openssl_sign($unsigned, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the GitHub App token.');
        }

        return $unsigned.'.'.rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');
    }
}
