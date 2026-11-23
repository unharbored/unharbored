<?php

declare(strict_types=1);

namespace Tests\Feature\GitHub;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Http\Controllers\GitHubController;
use App\Lib\Operations\RunOperation;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * The GitHub App replaces long-lived deploy keys with hour-long tokens and
 * lets pushes trigger deploys. The lines held here: nobody can attach their
 * own app through the callback, an unsigned webhook does nothing, a push
 * deploys only the sites that asked for it, and a token never outlives the
 * clone or lands anywhere but a private file.
 */
final class GitHubAppTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    private string $privateKey;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'app.url' => 'https://cp.example.com', 'control_plane.gateway_token' => 'test-token']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        $this->http = new HttpFactory();
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->forgetInstance(GitHubApp::class);

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        $this->privateKey = $pem;

        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function github(): GitHubApp
    {
        return new GitHubApp($this->http, $this->app->make(Vault::class), new AuditLog());
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog());
    }

    private function connectApp(): void
    {
        $this->http->fake(['api.github.com/app-manifests/*' => $this->http->response([
            'id' => 4242, 'slug' => 'cloud-manager-org-1-ab12', 'pem' => $this->privateKey,
            'webhook_secret' => 'hook-secret', 'html_url' => 'https://github.com/apps/x', 'owner' => ['login' => 'acme-dev'],
        ], 201)]);
        $this->github()->completeSetup('org-1', 'code123');
    }

    private function site(string $branch = 'main', bool $push = true): stdClass
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:Acme/Demo.git', $branch);

        return $this->sites()->setGitSource($site, 'github_app', $push);
    }

    private function signed(array $payload, string $event = 'push', string $secret = 'hook-secret'): \Illuminate\Testing\TestResponse
    {
        $body = (string) json_encode($payload);

        return $this->call('POST', '/api/github/webhook/org-1', [], [], [], [
            'HTTP_X_GITHUB_EVENT' => $event,
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, $secret),
            'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    // ------------------------------------------------------------------ setup

    public function test_the_manifest_asks_for_read_only_contents_and_pull_requests(): void
    {
        $manifest = $this->github()->manifest('org-1', 'https://cp.example.com');

        $this->assertSame(['contents' => 'read', 'metadata' => 'read', 'pull_requests' => 'read'], $manifest['default_permissions']);
        $this->assertSame(['push', 'pull_request'], $manifest['default_events']);
        $this->assertFalse($manifest['public']);
        $this->assertSame('https://cp.example.com/api/github/webhook/org-1', $manifest['hook_attributes']['url']);
        $this->assertSame('https://cp.example.com/github/callback', $manifest['redirect_url']);
    }

    public function test_setup_stores_the_secrets_in_the_vault_not_the_table(): void
    {
        $this->connectApp();

        $row = (array) DB::table('github_apps')->where('organization_id', 'org-1')->first();
        $this->assertSame(4242, (int) $row['app_id']);
        foreach ($row as $value) {
            $this->assertStringNotContainsString('PRIVATE KEY', (string) $value);
            $this->assertStringNotContainsString('hook-secret', (string) $value);
        }
        $this->assertSame($this->privateKey, $this->app->make(Vault::class)->get('org-1', 'github:app_private_key'));
    }

    public function test_the_code_exchange_sends_no_body(): void
    {
        // GitHub answers 422 to a body of "[]" on this endpoint.
        $this->connectApp();

        $this->http->assertSent(fn (Request $r) => str_contains($r->url(), '/app-manifests/code123/conversions')
            && $r->method() === 'POST' && $r->body() === '');
    }

    public function test_a_refused_code_reports_what_github_said(): void
    {
        $this->http->fake(['api.github.com/*' => $this->http->response(['message' => 'Validation Failed'], 422)]);

        $this->expectExceptionMessage('(422): Validation Failed');

        $this->github()->completeSetup('org-1', 'code123');
    }

    public function test_the_callback_refuses_a_state_it_did_not_issue(): void
    {
        // Otherwise anyone could attach an app they control to the
        // organisation by sending its code here.
        $this->http->fake();

        $this->get('/github/callback?code=abc&state=forged')->assertStatus(410);
        $this->http->assertNothingSent();
        $this->assertNull($this->github()->find('org-1'));
    }

    public function test_a_state_works_exactly_once(): void
    {
        $this->artisan('github:setup', ['organization' => 'org-1'])->assertSuccessful();
        $state = $this->stateFromCache();
        $this->http->fake(['api.github.com/app-manifests/*' => $this->http->response([
            'id' => 1, 'slug' => 's', 'pem' => $this->privateKey, 'webhook_secret' => 'w', 'html_url' => 'h', 'owner' => ['login' => 'o'],
        ], 201)]);

        $this->get("/github/setup?state={$state}")->assertOk()->assertSee('https://github.com/settings/apps/new', false);
        $this->get("/github/callback?code=abc&state={$state}")->assertOk()->assertSee('/installations/new', false);
        $this->get("/github/callback?code=abc&state={$state}")->assertStatus(410);
    }

    private function stateFromCache(): string
    {
        // github:setup prints the state; recover it by issuing one directly.
        $state = 'known-state';
        Cache::put(GitHubController::stateKey($state), 'org-1', 60);

        return $state;
    }

    // ----------------------------------------------------------------- tokens

    public function test_installation_tokens_are_scoped_to_one_repository_read_only(): void
    {
        $this->connectApp();
        $this->http->fake([
            'api.github.com/repos/Acme/Demo/installation' => $this->http->response(['id' => 77, 'account' => ['login' => 'acme-dev']]),
            'api.github.com/app/installations/77/access_tokens' => $this->http->response(['token' => 'ghs_x', 'expires_at' => '2026-11-06T00:00:00Z'], 201),
        ]);

        $this->assertSame('ghs_x', $this->github()->installationToken('org-1', 'Acme/Demo'));

        $this->http->assertSent(fn (Request $r) => str_ends_with($r->url(), '/access_tokens')
            && $r['repositories'] === ['Demo'] && $r['permissions'] === ['contents' => 'read']);
    }

    public function test_the_app_jwt_is_signed_with_its_private_key(): void
    {
        $this->connectApp();
        $this->http->fake([
            'api.github.com/repos/*' => $this->http->response(['id' => 77, 'account' => ['login' => 'acme-dev']]),
            'api.github.com/app/*' => $this->http->response(['token' => 't'], 201),
        ]);

        $this->github()->installationToken('org-1', 'Acme/Demo');

        $this->http->assertSent(function (Request $r) {
            [$h, $p, $sig] = explode('.', substr($r->header('Authorization')[0], strlen('Bearer ')));
            $decode = fn ($s) => base64_decode(strtr($s, '-_', '+/'));
            $public = openssl_pkey_get_details(openssl_pkey_get_private($this->privateKey))['key'];

            return openssl_verify("{$h}.{$p}", $decode($sig), $public, OPENSSL_ALGO_SHA256) === 1
                && json_decode($decode($p), true)['iss'] === '4242';
        });
    }

    public function test_an_uninstalled_repository_fails_with_a_clear_message(): void
    {
        $this->connectApp();
        $this->http->fake(['api.github.com/repos/*' => $this->http->response(['message' => 'Not Found'], 404)]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not installed on Acme/Demo');

        $this->github()->installationToken('org-1', 'Acme/Demo');
    }

    // ---------------------------------------------------------------- webhook

    public function test_an_unsigned_or_wrongly_signed_delivery_does_nothing(): void
    {
        $this->connectApp();
        $this->site();
        Queue::fake();

        $this->signed(['ref' => 'refs/heads/main', 'repository' => ['full_name' => 'Acme/Demo']], secret: 'wrong')->assertStatus(401);
        $this->postJson('/api/github/webhook/org-1', ['ref' => 'refs/heads/main'])->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_a_push_queues_a_deploy_of_matching_sites_only(): void
    {
        $this->connectApp();
        $this->site('main', push: true);
        $other = $this->sites()->create('org-1', 'web-1.org-1', 'other', 'other.example.com', 'git@github.com:Acme/Demo.git', 'develop');
        $this->sites()->setGitSource($other, 'github_app', true);
        $off = $this->sites()->create('org-1', 'web-1.org-1', 'manual', 'manual.example.com', 'git@github.com:Acme/Demo.git', 'main');
        $this->sites()->setGitSource($off, 'github_app', false);
        Queue::fake();

        $sha = str_repeat('ab12', 10);
        $this->signed(['ref' => 'refs/heads/main', 'after' => $sha, 'repository' => ['full_name' => 'acme/demo'], 'pusher' => ['name' => 'matt']])
            ->assertStatus(202)->assertJson(['queued' => ['demo']]);

        Queue::assertPushed(RunOperation::class, 1);
        $operation = DB::table('operations')->sole();
        $this->assertSame(['deploy', 'push', 'matt', 'ab12ab12ab12'], [$operation->kind, $operation->trigger, $operation->actor, $operation->commit]);
        Queue::assertPushed(RunOperation::class, fn ($job) => $job->operationId === (int) $operation->id);
    }

    public function test_deleting_a_branch_deploys_nothing(): void
    {
        $this->connectApp();
        $this->site();
        Queue::fake();

        $this->signed(['ref' => 'refs/heads/main', 'deleted' => true, 'repository' => ['full_name' => 'Acme/Demo']])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_operations_on_one_node_are_serialised(): void
    {
        // Two quick pushes, or a push during a stack install, must not drive
        // the same agent at once.
        $site = $this->site();
        Queue::fake();
        $id = app(\App\Lib\Operations\OperationRunner::class)->queueDeploy((int) $site->id, 'console', 'me');

        $this->assertSame('node-web-1.org-1', (new RunOperation($id))->middleware()[0]->key);
    }

    // ------------------------------------------------------------------ clone

    public function test_the_token_clone_never_puts_the_token_in_arguments_and_deletes_it(): void
    {
        $site = $this->site();
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gw.test', 's', new HttpFactory(), new AuditLog()), new AuditLog(), $this->github());

        $steps = $deployer->fetchRecipe($site, '/home/deploy/demo/releases/r1', 'ghs_secret_token')['steps'];
        $ids = array_column($steps, 'id');
        $byId = array_column($steps, null, 'id');

        $this->assertStringNotContainsString('ghs_secret_token', json_encode(array_column($steps, 'args')));
        $this->assertTrue($byId['token']['sensitive']);
        $this->assertSame('0600', $byId['token']['mode']);
        $this->assertSame(['--', 'https://github.com/Acme/Demo.git', '/home/deploy/demo/releases/r1'], array_slice($byId['clone']['args'], -3));
        $this->assertGreaterThan(array_search('clone', $ids, true), array_search('forget-token', $ids, true));
        $this->assertSame('absent', $byId['forget-token']['type']);
    }

    public function test_a_failed_clone_still_removes_the_token(): void
    {
        $this->connectApp();
        $site = $this->site();

        $this->http->fake([
            'api.github.com/repos/*' => $this->http->response(['id' => 77, 'account' => ['login' => 'acme-dev']]),
            'api.github.com/app/*' => $this->http->response(['token' => 'ghs_x'], 201),
            'gw.test/*' => $this->http->sequence()
                ->push(['status' => 'succeeded', 'outcome_known' => true])
                ->push(['status' => 'failed', 'error' => 'recipe step "clone" failed', 'outcome_known' => true])
                ->push(['status' => 'succeeded', 'outcome_known' => true]),
        ]);
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gw.test', 's', $this->http, new AuditLog()), new AuditLog(), $this->github());

        try {
            $deployer->deploy($site, now: '20261105T190000Z');
            $this->fail('Should have failed.');
        } catch (RuntimeException) {
        }

        $this->http->assertSent(fn (Request $r) => str_contains($r->url(), 'gw.test')
            && ($r['args']['spec']['name'] ?? null) === 'forget-token');
    }

    public function test_a_push_github_failed_to_deliver_is_sent_again_once(): void
    {
        $this->connectApp();
        $at = fn (int $ago) => gmdate('Y-m-d\\TH:i:s\\Z', time() - $ago);
        $retried = [];
        $this->http->fake(function (Request $r) use ($at, &$retried) {
            if (str_contains($r->url(), '/attempts')) {
                $retried[] = $r->url();

                return $this->http->response([], 202);
            }

            return $this->http->response([
                ['id' => 1, 'guid' => 'lost', 'event' => 'push', 'delivered_at' => $at(600), 'status_code' => 502, 'status' => 'failed to connect to host'],
                ['id' => 2, 'guid' => 'fine', 'event' => 'push', 'delivered_at' => $at(600), 'status_code' => 202, 'status' => 'OK'],
                ['id' => 3, 'guid' => 'healed', 'event' => 'push', 'delivered_at' => $at(900), 'status_code' => 502, 'status' => 'failed'],
                ['id' => 4, 'guid' => 'healed', 'event' => 'push', 'delivered_at' => $at(500), 'status_code' => 202, 'status' => 'OK'],
                ['id' => 5, 'guid' => 'hopeless', 'event' => 'push', 'delivered_at' => $at(900), 'status_code' => 500, 'status' => 'x'],
                ['id' => 6, 'guid' => 'hopeless', 'event' => 'push', 'delivered_at' => $at(800), 'status_code' => 500, 'status' => 'x'],
                ['id' => 7, 'guid' => 'hopeless', 'event' => 'push', 'delivered_at' => $at(700), 'status_code' => 500, 'status' => 'x'],
                ['id' => 8, 'guid' => 'ancient', 'event' => 'push', 'delivered_at' => $at(4 * 86400), 'status_code' => 502, 'status' => 'x'],
                ['id' => 9, 'guid' => 'install', 'event' => 'installation', 'delivered_at' => $at(600), 'status_code' => 500, 'status' => 'x'],
            ]);
        });

        $done = $this->github()->redeliverFailed('org-1');

        $this->assertSame(['https://api.github.com/app/hook/deliveries/1/attempts'], $retried, 'Only the push with no successful attempt, still in the window, and not given up on.');
        $this->assertCount(1, $done);
        $this->assertDatabaseHas('audit_records', ['action' => 'github.webhook_redelivered']);
    }

    // ------------------------------------------------------- pull requests

    /** @return array<string, mixed> */
    private function pr(string $action, int $number = 7, string $head = 'feature/login', string $base = 'main', string $headRepo = 'Acme/Demo'): array
    {
        return ['action' => $action, 'number' => $number, 'sender' => ['login' => 'matt'], 'repository' => ['full_name' => 'Acme/Demo'],
            'pull_request' => ['number' => $number, 'head' => ['ref' => $head, 'repo' => ['full_name' => $headRepo]], 'base' => ['ref' => $base, 'repo' => ['full_name' => 'Acme/Demo']]]];
    }

    private function previewServer(): void
    {
        config(['control_plane.preview_domain' => 'preview.example.com']);
        DB::table('nodes')->insert(['node_id' => 'preview-1.org-1', 'organization_id' => 'org-1', 'role' => 'preview', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_pull_request_into_the_chosen_branch_gets_a_preview_until_it_closes(): void
    {
        $this->connectApp();
        $this->previewServer();
        $site = $this->site();
        \App\Lib\Sites\Previews::setPullRequestBase($site, 'main');
        Queue::fake();

        $this->signed($this->pr('opened'), 'pull_request')->assertStatus(202)->assertJson(['previews' => ['demo-feature-login']]);
        $preview = DB::table('sites')->where('name', 'demo-feature-login')->first();
        $this->assertSame([7, 1, 1, null, 'feature/login'], [(int) $preview->pr_number, (int) $preview->pr_created, (int) $preview->push_to_deploy, $preview->preview_idle_days, $preview->branch]);
        $this->assertSame(['preview_create', 'pull_request', 'matt'], [DB::table('operations')->value('kind'), DB::table('operations')->value('trigger'), DB::table('operations')->value('actor')]);

        $this->signed($this->pr('opened'), 'pull_request')->assertOk()->assertJson(['previews' => []]);
        $this->assertSame(1, DB::table('sites')->whereNotNull('parent_site_id')->count(), 'A redelivery makes no second preview.');

        $sha = str_repeat('cd34', 10);
        $this->signed(['ref' => 'refs/heads/feature/login', 'after' => $sha, 'repository' => ['full_name' => 'acme/demo'], 'pusher' => ['name' => 'matt']])
            ->assertStatus(202)->assertJson(['queued' => ['demo-feature-login']]);

        $this->signed($this->pr('closed'), 'pull_request')->assertStatus(202)->assertJson(['previews' => ['demo-feature-login (deleted)']]);
        $this->assertSame('preview_destroy', DB::table('operations')->orderByDesc('id')->value('kind'));
    }

    public function test_pull_requests_into_other_branches_from_forks_or_with_previews_off_get_none(): void
    {
        $this->connectApp();
        $this->previewServer();
        $site = $this->site();
        Queue::fake();
        $this->signed($this->pr('opened'), 'pull_request')->assertOk()->assertJson(['previews' => []]);

        \App\Lib\Sites\Previews::setPullRequestBase($site, 'main');
        $this->signed($this->pr('opened', base: 'develop'), 'pull_request')->assertOk()->assertJson(['previews' => []]);
        $this->signed($this->pr('opened', headRepo: 'mallory/demo'), 'pull_request')->assertOk()->assertJson(['action' => 'ignored: from a fork']);
        Queue::assertNothingPushed();
    }

    public function test_a_branch_preview_is_taken_over_by_its_pull_request_and_outlives_it(): void
    {
        $this->connectApp();
        $this->previewServer();
        $site = $this->site();
        \App\Lib\Sites\Previews::setPullRequestBase($site, 'main');
        Queue::fake();
        $branch = $this->app->make(\App\Lib\Sites\Previews::class)->create($site, 'feature/login', false, 5);

        $this->signed($this->pr('opened'), 'pull_request')->assertStatus(202)->assertJson(['previews' => ['demo-feature-login (now PR #7)']]);
        $this->assertSame(1, DB::table('sites')->whereNotNull('parent_site_id')->count());
        $this->assertSame([7, 1], [(int) DB::table('sites')->find($branch->id)->pr_number, (int) DB::table('sites')->find($branch->id)->push_to_deploy]);

        $this->signed($this->pr('closed'), 'pull_request')->assertStatus(202)->assertJson(['previews' => ['demo-feature-login (kept: it was a branch preview first)']]);
        $this->assertNull(DB::table('sites')->find($branch->id)->pr_number);
        Queue::assertNothingPushed();
    }

    public function test_without_a_preview_server_a_pull_request_says_why(): void
    {
        $this->connectApp();
        \App\Lib\Sites\Previews::setPullRequestBase($this->site(), 'main');
        Queue::fake();
        $this->signed($this->pr('opened'), 'pull_request')->assertStatus(202)->assertJson(['previews' => ['demo: no preview, There is no preview server yet.']]);
    }

    public function test_only_installations_on_approved_accounts_are_listed_or_used(): void
    {
        $this->connectApp();
        config(['control_plane.github_allowed_accounts' => 'Acme-Org']);
        $this->http->fake(function (Request $r) {
            $url = $r->url();

            return match (true) {
                preg_match('#/app/installations(\?|$)#', $url) === 1 => $this->http->response([
                    ['id' => 1, 'account' => ['login' => 'acme-dev']], ['id' => 2, 'account' => ['login' => 'acme-org']], ['id' => 3, 'account' => ['login' => 'stranger']]]),
                str_contains($url, '/access_tokens') => $this->http->response(['token' => 'tok-'.preg_replace('/\D/', '', $url)], 201),
                str_contains($url, '/installation/repositories') => $this->http->response(['repositories' => [
                    ['full_name' => ($r->hasHeader('Authorization') ? $r->header('Authorization')[0] : '').'/repo', 'pushed_at' => null]]]),
                str_contains($url, '/repos/Stranger/Repo/installation') => $this->http->response(['id' => 3, 'account' => ['login' => 'stranger']]),
                default => $this->http->response([], 404),
            };
        });

        $names = array_column($this->github()->repositories('org-1'), 'full_name');
        $this->assertSame(['Bearer tok-1/repo', 'Bearer tok-2/repo'], $this->sortedByToken($names), 'The owner and the approved organisation, not the stranger.');

        $this->assertTrue($this->github()->trusts('org-1', 'ACME-DEV'));
        $this->assertTrue($this->github()->trusts('org-1', 'acme-org'));
        $this->assertFalse($this->github()->trusts('org-1', 'stranger'));
        $this->assertFalse($this->github()->trusts('org-1', ''));

        $this->expectExceptionMessage('not an account you have approved');
        $this->github()->installationToken('org-1', 'Stranger/Repo');
    }

    /** @param list<string> $names */
    private function sortedByToken(array $names): array
    {
        sort($names);

        return $names;
    }

    public function test_the_owner_installing_the_app_approves_that_account_and_nobody_elses_install_counts(): void
    {
        $this->connectApp();
        $install = fn (string $action, string $account, string $sender) => $this->signed(['action' => $action, 'installation' => ['id' => 5, 'account' => ['login' => $account]], 'sender' => ['login' => $sender]], 'installation');

        $install('created', 'Acme-Org', 'Acme-Dev')->assertOk()->assertJson(['installation' => 'approved']);
        $this->assertTrue($this->github()->trusts('org-1', 'acme-org'));
        $this->assertDatabaseHas('audit_records', ['action' => 'github.account_approved']);

        $install('created', 'stranger', 'someone-else')->assertOk()->assertJson(['installation' => 'not approved']);
        $this->assertFalse($this->github()->trusts('org-1', 'stranger'));
        $this->assertDatabaseHas('audit_records', ['action' => 'github.installation_not_approved']);

        $install('created', 'acme-org', 'acme-dev');
        $this->assertSame(['Acme-Org'], json_decode((string) DB::table('github_apps')->value('accounts'), true), 'Approving twice records it once.');

        $install('deleted', 'acme-org', 'acme-dev')->assertJson(['installation' => 'removed']);
        $this->assertFalse($this->github()->trusts('org-1', 'acme-org'));
        $this->assertTrue($this->github()->trusts('org-1', 'acme-dev'), 'The owner always stays.');
    }

    public function test_an_unsigned_installation_event_approves_nothing(): void
    {
        $this->connectApp();

        $this->signed(['action' => 'created', 'installation' => ['account' => ['login' => 'evil']], 'sender' => ['login' => 'acme-dev']], 'installation', 'wrong-secret')->assertStatus(401);
        $this->assertFalse($this->github()->trusts('org-1', 'evil'));
    }

    public function test_one_organisations_approvals_are_not_anothers(): void
    {
        $this->connectApp();
        DB::table('organizations')->insert(['id' => 'org-2', 'name' => 'Two', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('github_apps')->insert(['organization_id' => 'org-2', 'app_id' => 9, 'slug' => 's2', 'owner' => 'other-owner', 'html_url' => 'h', 'created_at' => now(), 'updated_at' => now()]);
        $this->github()->installationEvent('org-1', ['action' => 'created', 'installation' => ['account' => ['login' => 'acme-org']], 'sender' => ['login' => 'acme-dev']]);

        $this->assertTrue($this->github()->trusts('org-1', 'acme-org'));
        $this->assertFalse($this->github()->trusts('org-2', 'acme-org'));
        $this->assertTrue($this->github()->trusts('org-2', 'other-owner'));
    }

    // --------------------------------------------------- several accounts

    public function test_accounts_lists_every_installation_and_whether_it_is_used(): void
    {
        $this->connectApp();
        $this->http->fake(['api.github.com/app/installations*' => $this->http->response([
            ['id' => 1, 'account' => ['login' => 'acme-dev', 'type' => 'User']],
            ['id' => 2, 'account' => ['login' => 'acme-org', 'type' => 'Organization']],
        ])]);

        $list = $this->github()->accounts('org-1');

        $this->assertSame('https://github.com/apps/cloud-manager-org-1-ab12/installations/new', $list['install_url']);
        $this->assertSame([['acme-dev', true, true], ['acme-org', false, false]], array_map(fn ($a) => [$a['login'], $a['approved'], $a['owner']], $list['accounts']));
    }

    public function test_an_account_can_be_approved_and_removed_and_each_is_audited(): void
    {
        $this->connectApp();
        $by = \App\Lib\Audit\Actor::user('1', 'a@example.com');

        $this->github()->approveAccount('org-1', 'acme-org', $by);
        $this->github()->approveAccount('org-1', 'ACME-org', $by);
        $this->assertTrue($this->github()->trusts('org-1', 'acme-org'));
        $this->assertSame(1, DB::table('audit_records')->where('action', 'github.account_approved')->count());

        $this->github()->unapproveAccount('org-1', 'acme-org', $by);
        $this->assertFalse($this->github()->trusts('org-1', 'acme-org'));
        $this->assertTrue($this->github()->trusts('org-1', 'acme-dev'), 'the app owner stays');
        $this->assertSame(1, DB::table('audit_records')->where('action', 'github.account_removed')->count());
    }

    public function test_the_account_page_lists_github_accounts_and_approves_one(): void
    {
        $this->connectApp();
        $this->actingAs(\App\Models\User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $this->http->fake(['api.github.com/app/installations*' => $this->http->response([
            ['id' => 1, 'account' => ['login' => 'acme-dev', 'type' => 'User']],
            ['id' => 2, 'account' => ['login' => 'acme-org', 'type' => 'Organization']],
        ])]);

        $accounts = fn () => collect($this->get('/account?tab=github')->assertOk()->inertiaProps('github.accounts'))->keyBy('login');
        $this->assertFalse($accounts()['acme-org']['approved'], 'offered with "Use its repositories"');

        $this->post('/account/github/accounts', ['login' => 'acme-org'])->assertRedirect();
        $this->assertTrue($this->github()->trusts('org-1', 'acme-org'));
        $this->assertTrue($accounts()['acme-org']['approved'], 'now offered with "Stop using"');
        // Asked in the console's own dialog, not the browser's: the Vue component calls it.
        $component = file_get_contents(resource_path('js/Components/Account/GitHub.vue'));
        $this->assertStringContainsString('confirm(', $component);
        $this->assertStringNotContainsString('window.confirm', $component);
    }
}
