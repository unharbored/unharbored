<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Lib\Api\ApiTokens;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * The API does what the console does, for whoever holds a token, and no
 * more than the token's scope allows.
 */
final class ApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gatewayCalls = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            $this->gatewayCalls[] = $r['args'] ?? [];

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => "Migration table found.\n__CM_EXIT=0\n"]]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));

        $sites = $this->app->make(Sites::class);
        $site = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['current_release' => 'r1']);
        $sites->setEnvValues($sites->find('web-1.org-1', 'shop'), ['STRIPE_SECRET' => 'sk_live_x']);
    }

    private function token(string $scope): string
    {
        return $this->app->make(ApiTokens::class)->issue('org-1', "test {$scope}", $scope, 'me')['token'];
    }

    public function test_no_token_or_a_revoked_one_gets_nothing(): void
    {
        $this->getJson('/api/v1/sites')->assertUnauthorized();
        $token = $this->token('read');
        $this->withToken($token)->getJson('/api/v1/sites')->assertOk()->assertJsonPath('sites.0.name', 'shop');

        $this->app->make(ApiTokens::class)->revoke('org-1', (int) DB::table('api_tokens')->value('id'), 'me');
        $this->withToken($token)->getJson('/api/v1/sites')->assertUnauthorized();
        $this->assertSame(64, strlen((string) DB::table('api_tokens')->value('hash')), 'Only a hash is kept.');
        $this->assertFalse(DB::table('api_tokens')->where('hash', $token)->exists());
    }

    public function test_a_deploy_and_a_rollback_are_operations_followed_by_their_log(): void
    {
        $this->withToken($this->token('read'))->postJson('/api/v1/sites/shop/deploy')->assertForbidden();

        $deploy = $this->withToken($token = $this->token('deploy'))->postJson('/api/v1/sites/shop/deploy')->assertStatus(202)->json();
        $op = DB::table('operations')->find($deploy['operation']);
        $this->assertSame(['deploy', 'api', 'token test deploy'], [$op->kind, $op->trigger, $op->actor]);

        DB::table('operations')->where('id', $op->id)->update(['log' => "line one\nline two\n"]);
        $first = $this->withToken($token)->getJson("/api/v1/operations/{$op->id}")->assertOk()->json();
        $this->assertSame("line one\nline two\n", $first['log']);
        DB::table('operations')->where('id', $op->id)->update(['log' => "line one\nline two\nline three\n"]);
        $this->assertSame("line three\n", $this->withToken($token)->getJson("/api/v1/operations/{$op->id}?after={$first['next']}")->json('log'));

        $this->withToken($token)->postJson('/api/v1/sites/shop/rollback')->assertStatus(202);
        $this->assertSame(1, DB::table('operations')->where('kind', 'rollback')->count());
    }

    public function test_env_shows_names_to_read_and_contents_to_manage_only(): void
    {
        $read = $this->withToken($this->token('read'))->getJson('/api/v1/sites/shop/env')->assertOk();
        $this->assertContains('STRIPE_SECRET', $read->json('keys'));
        $this->assertStringNotContainsString('sk_live_x', $read->getContent());
        $this->withToken($this->token('deploy'))->getJson('/api/v1/sites/shop/env?reveal=1')->assertForbidden();

        $manage = $this->token('manage');
        $this->assertStringContainsString('STRIPE_SECRET=sk_live_x', $this->withToken($manage)->getJson('/api/v1/sites/shop/env?reveal=1')->json('contents'));
        $this->assertTrue(DB::table('audit_records')->where('action', 'site.env_read')->where('actor_type', 'api_token')->exists());

        $this->withToken($manage)->patchJson('/api/v1/sites/shop/env', ['values' => ['APP_DEBUG' => 'false', 'STRIPE_SECRET' => null]])->assertOk();
        $env = $this->app->make(Sites::class)->env($this->app->make(Sites::class)->find('web-1.org-1', 'shop'));
        $this->assertStringContainsString("APP_DEBUG=false\n", $env);
        $this->assertStringNotContainsString('STRIPE_SECRET', $env);
        $this->withToken($manage)->patchJson('/api/v1/sites/shop/env', ['values' => ['bad key' => 'x']])->assertStatus(422);
    }

    public function test_artisan_runs_on_the_primary_and_refuses_what_never_ends(): void
    {
        $this->withToken($this->token('deploy'))->postJson('/api/v1/sites/shop/artisan', ['args' => ['migrate:status']])->assertForbidden();
        $manage = $this->token('manage');

        $this->withToken($manage)->postJson('/api/v1/sites/shop/artisan', ['args' => ['migrate:status']])
            ->assertOk()->assertJson(['exit_code' => 0, 'output' => "Migration table found.\n"]);
        $this->assertSame(['-u', 'deploy', '--', 'sh', '-c'], array_slice($this->gatewayCalls[0]['args'], 0, 5));
        $this->assertSame('migrate:status', last($this->gatewayCalls[0]['args']));

        $this->withToken($manage)->postJson('/api/v1/sites/shop/artisan', ['args' => ['tinker']])->assertStatus(422);
    }

    public function test_another_organisations_site_does_not_exist_for_this_token(): void
    {
        DB::table('sites')->update(['organization_id' => 'org-2']);
        $this->withToken($this->token('manage'))->getJson('/api/v1/sites/shop')->assertNotFound();
    }

    public function test_projects_are_listed_for_the_organisation_and_filter_the_sites(): void
    {
        $actor = \App\Lib\Audit\Actor::user('1', 'a@example.com');
        \App\Lib\Auth\Projects::create('org-1', 'Client A', $actor);
        \App\Lib\Auth\Projects::create('org-2', 'Secret', $actor);
        $sites = $this->app->make(Sites::class);
        $sites->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git', projectId: (int) DB::table('projects')->where('organization_id', 'org-1')->where('slug', 'client-a')->value('id'));

        // A read token (the most a Viewer's token can be) lists them, with counts.
        $read = $this->token('read');
        $this->withToken($read)->getJson('/api/v1/projects')->assertOk()
            ->assertJsonCount(2, 'projects')
            ->assertJsonPath('projects.0.slug', 'default')->assertJsonPath('projects.0.applications', 1)
            ->assertJsonPath('projects.1.slug', 'client-a')->assertJsonPath('projects.1.applications', 1)
            ->assertJsonMissing(['slug' => 'secret']);

        $this->withToken($read)->getJson('/api/v1/sites?project=client-a')->assertOk()
            ->assertJsonCount(1, 'sites')->assertJsonPath('sites.0.name', 'blog')->assertJsonPath('sites.0.project.slug', 'client-a');
        $this->withToken($read)->getJson('/api/v1/sites?project=default')->assertOk()
            ->assertJsonCount(1, 'sites')->assertJsonPath('sites.0.name', 'shop');
        $this->withToken($read)->getJson('/api/v1/sites')->assertOk()->assertJsonCount(2, 'sites');

        // Unknown, or another organisation's, is a 404 in the API's error shape; a site outside the filter is still reachable by name.
        $this->withToken($read)->getJson('/api/v1/sites?project=nope')->assertNotFound()->assertJsonPath('error', 'No project nope.');
        $this->withToken($read)->getJson('/api/v1/sites?project=secret')->assertNotFound();
        $this->withToken($read)->getJson('/api/v1/sites/blog')->assertOk();
    }
}
