<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Git\GitRemote;
use App\Lib\Git\GitSources;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** While an application's creation waits for its deploy key, the key is shown on the pages people come back to. */
final class DeployKeyAwaitingTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: object, 1: int} */
    private function creating(string $stage): array
    {
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->actingAs(User::factory()->create());
        $site = (new Sites($this->app->make(Vault::class), new AuditLog))->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@gitlab.com:acme/shop.git');
        $id = DB::table('operations')->insertGetId([
            'organization_id' => 'org-1', 'kind' => 'app_create', 'site_id' => $site->id, 'title' => 'Create shop', 'trigger' => 'console', 'actor' => 'dev@example.com', 'status' => 'running',
            'stages' => json_encode([['name' => $stage, 'status' => 'running', 'started_at' => now()->toIso8601String(), 'finished_at' => null]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return [$site, $id];
    }

    public function test_site_list_and_operation_show_the_key_while_creation_waits_for_it(): void
    {
        [$site, $op] = $this->creating('access');
        $public = trim((string) DB::table('sites')->where('id', $site->id)->value('deploy_key_public'));

        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $p) => $p->where('view.awaitingKey.key', $public)
            ->where('view.awaitingKey.host', 'gitlab.com')->where('view.awaitingKey.operationId', $op));
        $this->get('/sites')->assertInertia(fn (Assert $p) => $p->where('sites.0.awaitingKey', true));
        $this->get('/operation?operation='.$op)->assertInertia(fn (Assert $p) => $p->where('view.awaitingKey.key', $public));
    }

    public function test_nothing_is_shown_once_creation_is_past_the_access_step(): void
    {
        [$site] = $this->creating('deploy');

        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $p) => $p->where('view.awaitingKey', null));
    }

    /** A host whose repository has these branches (git's reference advertisement), reached with any key. */
    private function repositoryWith(array $branches): void
    {
        $pkt = fn (string $line) => sprintf('%04x', strlen($line) + 4).$line;
        $body = '';
        foreach ($branches as $i => $name) {
            $body .= $pkt(str_repeat('a', 40)." refs/heads/{$name}".($i === 0 ? "\0symref=HEAD:refs/heads/{$name}" : '')."\n");
        }
        $this->app->instance(GitRemote::class, new GitRemote(new HttpFactory, fn () => ['93.184.216.34'], null, null, fn () => $body.'0000'));
        $this->app->forgetInstance(GitSources::class);
    }

    public function test_the_branches_are_listed_default_first_for_the_dropdown(): void
    {
        [$site] = $this->creating('access');
        $this->repositoryWith(['master', 'develop']);

        $this->postJson("/site/settings/{$site->id}/branches")->assertOk()->assertExactJson(['branches' => ['master', 'develop']]);
    }

    public function test_the_branch_can_be_changed_to_one_the_repository_has(): void
    {
        [$site] = $this->creating('access');
        $this->repositoryWith(['master', 'develop']);

        $this->post("/site/settings/{$site->id}/branch", ['branch' => 'master'])->assertSessionHasNoErrors();
        $this->assertSame('master', DB::table('sites')->where('id', $site->id)->value('branch'));
    }

    public function test_a_branch_the_repository_does_not_have_is_refused_with_the_ones_it_has(): void
    {
        [$site] = $this->creating('access');
        $this->repositoryWith(['master', 'develop']);

        $this->post("/site/settings/{$site->id}/branch", ['branch' => 'main'])->assertSessionHasErrors(['branch' => 'main is not a branch of this repository. It has: master, develop.']);
        $this->assertSame('main', DB::table('sites')->where('id', $site->id)->value('branch'));
    }

    public function test_the_application_folder_can_be_changed_and_emptied_from_the_repository_tab(): void
    {
        [$site] = $this->creating('access');
        DB::table('sites')->where('id', $site->id)->update(['root_dir' => 'public']);

        $this->get("/site/settings?site={$site->id}&tab=source")->assertInertia(fn (Assert $p) => $p->where('data.root', 'public'));
        $this->post("/site/settings/{$site->id}/root", ['root' => ''])->assertSessionHasNoErrors();
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('root_dir'));
    }

    public function test_the_whole_log_is_available_as_text_and_the_page_says_how_much_it_shows(): void
    {
        [, $op] = $this->creating('access');
        $log = collect(range(1, 500))->map(fn ($i) => sprintf('01:00:00  build    run     line %d', $i))->implode("\n");
        DB::table('operations')->where('id', $op)->update(['log' => $log, 'error' => 'composer failed in /x']);

        $text = $this->get("/operation/log?operation={$op}")->assertOk()->assertHeader('Content-Type', 'text/plain; charset=utf-8')->getContent();
        $this->assertStringContainsString('line 1', $text);
        $this->assertStringContainsString('line 500', $text);
        $this->assertStringContainsString('Error: composer failed in /x', $text);
        $this->get("/operation?operation={$op}")->assertInertia(fn (Assert $p) => $p->where('view.lineCount', 500)->has('view.lines', 400));
    }

    public function test_composer_credentials_are_kept_in_the_vault_shown_without_secrets_and_sent_with_the_setup(): void
    {
        [$site] = $this->creating('deploy');
        $auth = '{"gitlab-token": {"gitlab.com": "glpat-secret-value"}}';

        DB::table('sites')->where('id', $site->id)->update(['git_host_key' => 'gitlab.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']);
        $this->post("/site/settings/{$site->id}/composer-auth", ['auth' => $auth])->assertSessionHasNoErrors();
        $row = DB::table('sites')->find($site->id);
        $sites = $this->app->make(Sites::class);
        $steps = collect($sites->setupRecipe($row)['steps'])->keyBy('id');
        $this->assertSame('/home/deploy/shop/composer/auth.json', $steps['composer-auth']['path']);
        $this->assertSame('0600', $steps['composer-auth']['mode']);
        $this->assertSame(json_decode($auth, true), json_decode(base64_decode($steps['composer-auth']['content_b64']), true));
        $this->get("/site/settings?site={$site->id}&tab=build")->assertInertia(fn (Assert $p) => $p->where('data.composerAuth', [['type' => 'gitlab-token', 'host' => 'gitlab.com', 'username' => '']]));
        $this->assertStringNotContainsString('glpat-secret-value', $this->get("/site/settings?site={$site->id}&tab=build")->getContent());

        $args = $this->app->make(\App\Lib\Sites\Deployer::class)->buildRecipe('/r', composerHome: Sites::composerHome($row))['steps'][0]['args'];
        $this->assertSame(['-u', 'deploy', '--', 'env', 'COMPOSER_IGNORE_PLATFORM_REQS=php+', 'COMPOSER_HOME=/home/deploy/shop/composer'], array_slice($args, 0, 6));
        $this->assertStringNotContainsString('glpat', implode(' ', $args));

        $this->post("/site/settings/{$site->id}/composer-auth", ['auth' => ''])->assertSessionHasNoErrors();
        $this->assertSame('absent', collect($sites->setupRecipe(DB::table('sites')->find($site->id))['steps'])->firstWhere('id', 'composer-auth')['type']);
    }

    public function test_one_composer_host_can_be_added_replaced_and_removed_leaving_the_others(): void
    {
        [$site] = $this->creating('deploy');
        DB::table('sites')->where('id', $site->id)->update(['git_host_key' => 'gitlab.com ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']);
        $url = "/site/settings/{$site->id}";
        $sites = $this->app->make(Sites::class);
        $stored = fn () => json_decode(base64_decode(collect($sites->setupRecipe(DB::table('sites')->find($site->id))['steps'])->firstWhere('id', 'composer-auth')['content_b64']), true);

        $this->post("{$url}/composer-host", ['type' => 'http-basic', 'host' => 'Nova.Laravel.com', 'username' => 'me@x.com', 'secret' => 'key-1'])->assertSessionHasNoErrors();
        $this->post("{$url}/composer-host", ['type' => 'github-oauth', 'host' => 'github.com', 'secret' => 'ghp_token'])->assertSessionHasNoErrors();
        $this->assertSame(['username' => 'me@x.com', 'password' => 'key-1'], $stored()['http-basic']['nova.laravel.com']);
        $this->assertSame('ghp_token', $stored()['github-oauth']['github.com']);

        $this->post("{$url}/composer-host", ['type' => 'http-basic', 'host' => 'nova.laravel.com', 'username' => 'new@x.com', 'secret' => 'key-2'])->assertSessionHasNoErrors();
        $this->assertSame(['username' => 'new@x.com', 'password' => 'key-2'], $stored()['http-basic']['nova.laravel.com']);
        $this->assertSame('ghp_token', $stored()['github-oauth']['github.com']);

        $page = $this->get("/site/settings?site={$site->id}&tab=build");
        $page->assertInertia(fn (Assert $p) => $p->where('data.composerAuth', [
            ['type' => 'http-basic', 'host' => 'nova.laravel.com', 'username' => 'new@x.com'],
            ['type' => 'github-oauth', 'host' => 'github.com', 'username' => ''],
        ]));
        $this->assertStringNotContainsString('key-2', $page->getContent());

        $this->post("{$url}/composer-host-remove", ['type' => 'http-basic', 'host' => 'nova.laravel.com'])->assertSessionHasNoErrors();
        $this->assertSame(['github-oauth' => ['github.com' => 'ghp_token']], $stored());

        $this->post("{$url}/composer-host", ['type' => 'http-basic', 'host' => 'https://x.com/a', 'username' => 'u', 'secret' => 'p'])->assertSessionHasErrors('composerAuth');
        $this->post("{$url}/composer-host-remove", ['type' => 'github-oauth', 'host' => 'github.com']);
        $this->assertFalse($sites->hasComposerAuth(DB::table('sites')->find($site->id)));
    }

    public function test_something_that_is_not_an_auth_json_is_refused(): void
    {
        [$site] = $this->creating('deploy');

        foreach (['not json', '[]', '{"made-up": {"x": "y"}}', '{"gitlab-token": "x"}'] as $bad) {
            $this->post("/site/settings/{$site->id}/composer-auth", ['auth' => $bad])->assertSessionHasErrors('composerAuth');
        }
        $this->assertFalse($this->app->make(Sites::class)->hasComposerAuth(DB::table('sites')->find($site->id)));
    }

    public function test_an_application_on_one_server_can_be_moved_to_mysql_or_postgresql_on_that_server(): void
    {
        Queue::fake();
        [$site] = $this->creating('deploy');

        $this->get("/site/settings?site={$site->id}&tab=database")->assertInertia(fn (Assert $p) => $p->where('data.localDatabases', [['service' => 'pgsql', 'label' => 'PostgreSQL'], ['service' => 'mysql', 'label' => 'MySQL']]));
        $response = $this->post("/site/settings/{$site->id}/database/local", ['service' => 'mysql']);

        $op = DB::table('operations')->where('kind', 'local_service')->first();
        $response->assertRedirect("/operation?operation={$op->id}");
        $this->assertSame([$site->id, 'Set up shop on MySQL'], [$op->site_id, $op->title]);
        $this->assertSame('mysql', json_decode($op->meta, true)['service']);
    }

    public function test_the_choice_must_be_a_database_and_the_application_on_one_server(): void
    {
        Queue::fake();
        [$site] = $this->creating('deploy');

        $this->post("/site/settings/{$site->id}/database/local", ['service' => 'valkey'])->assertSessionHasErrors('local');
        DB::table('sites')->where('id', $site->id)->update(['compute' => 'autoscale']);
        $this->post("/site/settings/{$site->id}/database/local", ['service' => 'mysql'])->assertSessionHasErrors('local');
        $this->assertSame(0, DB::table('operations')->where('kind', 'local_service')->count());
    }
}
