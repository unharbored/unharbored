<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Lib\Audit\AuditLog;
use App\Lib\Operations\Operations;
use App\Lib\Providers\Providers;
use App\Lib\Resources\ResourceManager;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The console's pages and components, signed in, against real records.
 */
final class ConsoleTest extends TestCase
{
    use RefreshDatabase;

    private object $site;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));

        $sites = new Sites($this->app->make(Vault::class), new AuditLog);
        $this->site = $sites->markTls($sites->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:acme/demo.git'));
        DB::table('sites')->where('id', $this->site->id)->update(['current_release' => '20261106T000712Z-main']);

        $ops = new Operations;
        $id = $ops->create('org-1', 'deploy', 'Deploy demo', 'push', 'acme-dev', ['setup', 'build'], (int) $this->site->id, 'web-1.org-1', '20261106T000712Z-main', '2f09d0d');
        $ops->start($id);
        $ops->stage($id, 'setup');
        $ops->succeed($id);
    }

    public function test_the_sites_page_renders_with_its_component(): void
    {
        $this->get('/sites')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Sites')
                ->where('sites.0.name', 'demo')
                ->where('sites.0.url', 'https://demo.example.com')
                ->where('sites.0.commit', '2f09d0d'));
    }

    public function test_the_theme_toggle_is_an_icon_with_an_accessible_label(): void
    {
        $layout = (string) file_get_contents(resource_path('js/Layouts/Console.vue'));

        $this->assertStringNotContainsString('[ Light ]', $layout);
        $this->assertStringContainsString('aria-label="Switch colour theme"', $layout);
        $this->assertStringContainsString('<svg', $layout);
        $this->assertStringContainsString('theme-to-light', $layout);
        $this->assertStringContainsString('theme-to-dark', $layout);
    }

    public function test_the_chosen_theme_is_kept_in_a_cookie_and_rendered_on_the_page(): void
    {
        $this->get('/sites')->assertOk()->assertDontSee('data-theme=', false);

        $this->from('/servers')->post('/theme', ['theme' => 'light'])
            ->assertRedirect('/servers')
            ->assertCookie('theme', 'light');

        $this->withCookie('theme', 'light')->get('/sites')->assertSee('data-theme="light"', false);
        $this->withCookie('theme', 'pink')->get('/sites')->assertDontSee('data-theme=', false);
        $this->post('/theme', ['theme' => 'pink'])->assertSessionHasErrors('theme');
    }

    public function test_the_sites_loader_reports_live_commit_and_last_operation(): void
    {
        $this->get('/sites')->assertInertia(fn (Assert $page) => $page
            ->component('Sites')
            ->has('sites', 1, fn (Assert $s) => $s->where('name', 'demo')->where('commit', '2f09d0d')->where('badge.label', 'Live')->etc())
            ->has('activity', 1, fn (Assert $a) => $a->where('by', 'Push · acme-dev')->etc()));
    }

    public function test_the_site_page_shows_releases_and_the_live_one(): void
    {
        $this->get('/site?site='.$this->site->id)->assertInertia(fn (Assert $page) => $page
            ->component('Site')
            ->where('view.env', 'production')
            ->where('view.canDeploy', true)
            ->where('view.live', '20261106T000712Z-main')
            ->where('view.servers', ['web-1'])
            ->where('view.releases.0.by', 'Push · acme-dev')
            ->where('view.releases.0.label', 'Live')
            ->where('shell.site.id', $this->site->id)
            ->etc());
    }

    public function test_the_deployments_tab_lists_every_deploy_and_offers_a_rollback_to_the_previous(): void
    {
        $ops = new Operations;
        $id = $ops->create('org-1', 'deploy', 'Deploy demo', 'cli', 'matt@example.com', ['setup', 'build'], (int) $this->site->id, 'web-1.org-1', '20261107T000000Z-main', 'abc1234def');
        $ops->start($id);
        $ops->stage($id, 'setup');
        $ops->succeed($id);
        $failed = $ops->create('org-1', 'deploy', 'Deploy demo', 'push', 'acme-dev', ['setup', 'build'], (int) $this->site->id, 'web-1.org-1', '20261108T000000Z-main', 'fff0000aaa');
        $ops->start($failed);
        $ops->stage($failed, 'build');
        $ops->fail($failed, 'npm run build failed');
        DB::table('sites')->where('id', $this->site->id)->update(['current_release' => '20261107T000000Z-main']);

        $rows = $this->get('/site/deployments?site='.$this->site->id)->assertOk()->inertiaProps('view.rows');
        $this->assertSame(3, $this->get('/site/deployments?site='.$this->site->id)->inertiaProps('view.total'));
        $this->assertSame(['fff0000', 'abc1234', '2f09d0d'], array_column($rows, 'commit'));
        $this->assertSame(['Failed at build', 'Live', 'Previous'], array_column($rows, 'label'));
        $this->assertSame(['Push · acme-dev', 'Command line', 'Push · acme-dev'], array_column($rows, 'by'));
        $this->assertSame([false, false, true], array_column($rows, 'rollback'));
    }

    public function test_the_deployments_tab_only_offers_a_rollback_when_the_live_release_is_the_newest(): void
    {
        $ops = new Operations;
        $id = $ops->create('org-1', 'deploy', 'Deploy demo', 'cli', 'matt@example.com', ['setup'], (int) $this->site->id, 'web-1.org-1', '20261107T000000Z-main', 'abc1234def');
        $ops->start($id);
        $ops->stage($id, 'setup');
        $ops->succeed($id);
        // The newer deploy is not the live release, as after a rollback.

        $rows = $this->get('/site/deployments?site='.$this->site->id)->inertiaProps('view.rows');
        $this->assertNotContains(true, array_column($rows, 'rollback'));
        $this->get('/site/deployments?site=9999')->assertInertia(fn (Assert $page) => $page->where('view.missing', true));
    }

    /** The text of every log line the Logs page returns for a URL. */
    private function logTexts(string $url): array
    {
        return array_column($this->get($url)->assertOk()->inertiaProps('lines.rows'), 'text');
    }

    public function test_an_applications_logs_tab_shows_only_its_own_lines(): void
    {
        $line = fn (string $node, string $source, string $text) => DB::table('log_lines')->insert(['organization_id' => 'org-1', 'node_id' => $node,
            'source' => $source, 'logged_at' => now()->subMinutes(2), 'text' => $text]);
        $line('web-1.org-1', 'laravel:demo', 'demo.ERROR: checkout failed');
        $line('web-1.org-1', 'worker:demo', 'demo worker: job processed');
        $line('web-1.org-1', 'nginx.error', 'nginx: upstream timed out');
        $line('web-1.org-1', 'laravel:other', 'other.ERROR: someone else');
        $line('web-2.org-1', 'laravel:demo', 'demo.ERROR: on another server');
        $line('web-2.org-1', 'nginx.error', 'nginx: another servers error');

        // The application's own header (its tabs and switchers) comes with the page.
        $this->assertSame($this->site->id, $this->get('/site/logs?site='.$this->site->id)->inertiaProps('shell.site.id'));

        $own = $this->logTexts('/site/logs?site='.$this->site->id);
        $this->assertEqualsCanonicalizing(['demo.ERROR: checkout failed', 'demo worker: job processed', 'nginx: upstream timed out'], $own);

        // The organisation-wide page still shows everything.
        $all = $this->logTexts('/logs');
        $this->assertContains('other.ERROR: someone else', $all);
        $this->assertContains('nginx: another servers error', $all);
        // A site that does not exist matches nothing, rather than everything.
        $this->assertSame([], $this->logTexts('/site/logs?site=9999'));
    }

    public function test_an_applications_logs_include_its_process_output_and_its_own_nginx_errors(): void
    {
        $line = fn (string $source, string $text) => DB::table('log_lines')->insert(['organization_id' => 'org-1', 'node_id' => 'web-1.org-1',
            'source' => $source, 'logged_at' => now()->subMinutes(2), 'text' => $text]);
        $line('app:demo', 'listening on 3000');
        $line('nginx.error:demo', 'upstream timed out for demo');
        $line('nginx.error:other', 'upstream timed out for another site');
        $line('php-fpm', 'WARNING: [pool www] server reached pm.max_children');

        $own = $this->logTexts('/site/logs?site='.$this->site->id);
        $this->assertContains('listening on 3000', $own);
        $this->assertContains('upstream timed out for demo', $own);
        $this->assertNotContains('upstream timed out for another site', $own);
        $this->assertNotContains('WARNING: [pool www] server reached pm.max_children', $own);
        // The server-wide view has the PHP-FPM line.
        $this->assertContains('WARNING: [pool www] server reached pm.max_children', $this->logTexts('/logs'));
    }

    public function test_deploying_from_the_site_page_queues_an_operation_and_opens_it(): void
    {
        Queue::fake();

        $response = $this->post('/site/deploy', ['site' => $this->site->id]);

        $op = DB::table('operations')->orderByDesc('id')->first();
        $this->assertSame(['deploy', 'console', 'matt@example.com', 'queued'], [$op->kind, $op->trigger, $op->actor, $op->status]);
        $response->assertRedirect('/operation?operation='.$op->id);
    }

    public function test_a_missing_site_says_so(): void
    {
        $this->get('/site?site=999')->assertInertia(fn (Assert $page) => $page->where('view.missing', true));
    }

    public function test_the_operation_page_shows_stages_and_log(): void
    {
        $ops = new Operations;
        $id = $ops->create('org-1', 'deploy', 'Deploy demo', 'console', 'matt@example.com', ['setup', 'build', 'activate'], (int) $this->site->id, 'web-1.org-1', 'r1');
        $ops->start($id);
        $ops->stage($id, 'setup');
        $ops->log($id, 'run', 'nginx configuration is valid');
        $ops->stage($id, 'build');
        $ops->fail($id, 'composer exited 1');

        $view = $this->get('/operation?operation='.$id)->assertOk()->inertiaProps('view');

        $this->assertSame('Deploy demo', $view['title']);
        $this->assertSame([['Setup', 'done'], ['Build', 'failed'], ['Activate', 'skipped']], array_map(fn ($s) => [$s['name'], $s['status']], $view['stages']));
        $this->assertSame('not run', $view['stages'][2]['time']);
        $this->assertContains('nginx configuration is valid', array_column($view['lines'], 'text'));
        $this->assertSame('composer exited 1', $view['error']);
        $this->assertSame('failed', $view['status']);
    }

    public function test_a_finished_operation_says_it_is_done_and_where_to_go_next(): void
    {
        $ops = new Operations;
        $id = $ops->create('org-1', 'deploy', 'Deploy demo', 'console', 'matt@example.com', ['setup'], (int) $this->site->id, 'web-1.org-1', 'r1');
        $ops->start($id);
        $ops->stage($id, 'setup');
        $ops->succeed($id);

        $this->get('/operation?operation='.$id)->assertInertia(fn (Assert $page) => $page
            ->where('view.status', 'succeeded')->where('view.site.name', 'demo')->where('view.site.id', $this->site->id)->etc());
    }

    public function test_settings_mask_secrets_until_revealed_and_audit_the_reveal(): void
    {
        // Their own variables start empty; one of theirs that looks secret is masked until revealed.
        app(Sites::class)->setEnvValues($this->site, ['STRIPE_SECRET' => 'sk_live_abc']);
        $page = $this->get("/site/settings?site={$this->site->id}");

        $page->assertInertia(fn (Assert $p) => $p->component('Site/Settings')->where('data.envMasked', fn ($v) => str_contains($v, 'STRIPE_SECRET=••••••••••••')));
        $this->assertStringNotContainsString('sk_live_abc', $page->getContent());
        $this->assertStringNotContainsString('APP_KEY=base64:', $page->getContent());

        $text = $this->postJson("/site/settings/{$this->site->id}/env/reveal")->assertOk()->json('text');
        $this->assertStringContainsString('STRIPE_SECRET=sk_live_abc', $text);
        $this->assertStringNotContainsString('APP_KEY', $text, 'The key is injected, not theirs.');
        $this->assertDatabaseHas('audit_records', ['action' => 'site.env_read']);
    }

    public function test_saving_masked_text_is_refused(): void
    {
        $this->post("/site/settings/{$this->site->id}/env", ['envText' => "APP_KEY=••••••••••••\n"])->assertSessionHasErrors('envText');
    }

    public function test_a_saved_change_is_pending_until_the_next_deploy(): void
    {
        $this->post("/site/settings/{$this->site->id}/workers", ['workers' => 3])->assertSessionHas('status', 'Workers saved. They change on the next deploy.');

        $this->get("/site/settings?site={$this->site->id}")->assertInertia(fn (Assert $page) => $page->where('site.pending', true));
        $this->assertSame(3, (int) DB::table('sites')->where('id', $this->site->id)->value('queue_workers'));
        $this->assertNotNull(DB::table('sites')->where('id', $this->site->id)->value('pending_since'));
    }

    public function test_a_token_is_shown_once_and_can_be_revoked(): void
    {
        $this->get('/tokens')->assertOk();
        $this->post('/tokens', ['name' => 'laptop', 'scope' => 'deploy'])->assertRedirect();
        $token = (string) session('made');
        $this->assertMatchesRegularExpression('/^cm_[0-9a-f]{40}$/', $token);
        $page = $this->get('/tokens');
        $this->assertSame($token, $page->inertiaProps('made'));
        $this->assertSame('laptop', $page->inertiaProps('tokens')[0]['name']);
        // Shown once: the next visit has the name but never the token.
        $again = $this->get('/tokens');
        $this->assertSame('', $again->inertiaProps('made'));
        $this->assertStringNotContainsString($token, $again->getContent());

        $this->withToken($token)->getJson('/api/v1/whoami')->assertOk()->assertJson(['token' => 'laptop', 'scopes' => ['read', 'deploy']]);
        $this->delete('/tokens/'.(int) DB::table('api_tokens')->value('id'))->assertRedirect();
        $this->withToken($token)->getJson('/api/v1/whoami')->assertUnauthorized();
    }

    public function test_env_history_lists_changed_keys_and_puts_a_version_back(): void
    {
        $sites = app(Sites::class);
        $sites->setEnvValues($this->site, ['MAIL_HOST' => 'smtp.example.com']);
        $before = $sites->env($sites->find('web-1.org-1', 'demo'));
        $sites->setEnvValues($this->site, ['STRIPE_SECRET' => 'sk_test_abc']);
        $versions = $sites->envVersions($this->site);
        $previous = $versions[1]['version'];

        $page = $this->get("/site/settings?site={$this->site->id}&tab=env");
        $page->assertInertia(fn (Assert $p) => $p->has('data.envVersions', count($versions))
            ->where('data.envVersions.0.changes.added', fn ($added) => in_array('STRIPE_SECRET', $added->toArray(), true)));
        $this->assertStringNotContainsString('sk_test_abc', $page->getContent());

        $this->post("/site/settings/{$this->site->id}/env/restore", ['version' => $previous])->assertSessionHas('status', "Version {$previous} of .env is back, as the newest version. It reaches the server on the next deploy.");

        $this->assertSame($before, $sites->env($sites->find('web-1.org-1', 'demo')));
    }

    public function test_a_domain_that_does_not_point_here_is_refused(): void
    {
        Queue::fake();

        $this->post("/site/settings/{$this->site->id}/domain", ['newDomain' => 'nowhere.invalid'])->assertSessionHasErrors('newDomain');

        $this->assertSame(0, DB::table('operations')->where('kind', 'domain')->count());
    }

    public function test_a_daemon_with_a_newline_is_refused(): void
    {
        $this->post("/site/settings/{$this->site->id}/daemons", ['daemonName' => 'evil', 'daemonCommand' => "php artisan x\nuser=root"])
            ->assertSessionHasErrors('daemonName');
    }

    public function test_the_audit_log_filters_and_reports_the_chain(): void
    {
        $this->get('/audit')->assertOk();

        $all = $this->get('/audit');
        $this->assertTrue($all->inertiaProps('chain')['intact']);
        $this->assertContains('site.created', array_column($all->inertiaProps('records.rows'), 'action'));

        $found = array_column($this->get('/audit?q=tls_enabled')->inertiaProps('records.rows'), 'action');
        $this->assertContains('site.tls_enabled', $found);
        $this->assertNotContains('site.created', $found);
    }

    public function test_a_site_is_created_from_the_console_with_a_github_web_url(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-9.org-1', 'organization_id' => 'org-1', 'agent_version' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->get('/sites')->assertInertia(fn (Assert $page) => $page->where('form.servers', fn ($servers) => collect($servers)->pluck('name')->contains('web-9')));

        $this->post('/sites', ['server' => 'web-9.org-1', 'name' => 'Blog', 'domain' => 'blog.example.com', 'repository' => 'https://github.com/acme/blog', 'branch' => 'main', 'app' => false])
            ->assertRedirect();

        $site = DB::table('sites')->where('name', 'blog')->sole();
        $this->assertSame(['web-9.org-1', 'blog.example.com', 'git@github.com:acme/blog.git', 'main'], [$site->node_id, $site->domain, $site->repository, $site->branch]);
        $this->get("/site/settings?site={$site->id}&tab=source")->assertInertia(fn (Assert $page) => $page->where('data.source', 'deploy_key')
            ->where('data.deployKey', fn ($key) => str_contains($key, 'ssh-ed25519')));

        $this->post('/sites', ['server' => 'web-9.org-1', 'name' => 'blog', 'domain' => 'blog.example.com', 'repository' => 'git@github.com:acme/blog.git', 'branch' => 'main'])
            ->assertSessionHasErrors(['newName' => 'web-9.org-1 already has a site called blog.']);
    }

    public function test_the_logs_page_filters_by_text_server_and_age(): void
    {
        $line = fn (string $node, string $text, int $minutesAgo) => DB::table('log_lines')->insert(['organization_id' => 'org-1', 'node_id' => $node,
            'source' => 'laravel:shop', 'logged_at' => now()->subMinutes($minutesAgo), 'text' => $text]);
        $line('web-1.org-1', 'production.ERROR: SQLSTATE[HY000] connection refused', 5);
        $line('web-2.org-1', 'production.INFO: order 42 paid', 5);
        $line('web-1.org-1', 'production.ERROR: yesterday', 1200);

        $this->get('/logs')->assertOk();
        $this->assertEqualsCanonicalizing(['production.ERROR: SQLSTATE[HY000] connection refused', 'production.INFO: order 42 paid'], $this->logTexts('/logs'));
        $this->assertSame(['production.ERROR: SQLSTATE[HY000] connection refused'], $this->logTexts('/logs?q=SQLSTATE'));
        $this->assertSame(['production.INFO: order 42 paid'], $this->logTexts('/logs?server=web-2.org-1'));
        $this->assertContains('production.ERROR: yesterday', $this->logTexts('/logs?range=24h'));
    }

    public function test_the_delete_tab_explains_the_defaults_and_refuses_with_previews(): void
    {
        Queue::fake();
        DB::table('sites')->insert(['project_id' => 1,
            'organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'name' => 'demo-main', 'domain' => 'main.demo.example.com',
            'repository' => 'git@github.com:acme/demo.git', 'branch' => 'main', 'deploy_key_public' => 'x', 'parent_site_id' => $this->site->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->get("/site/settings?site={$this->site->id}&tab=delete")->assertInertia(fn (Assert $page) => $page->has('data.previews', 1)->where('site.name', 'demo'));

        $this->post("/site/settings/{$this->site->id}/delete", ['deleteData' => true])->assertSessionHasErrors('delete');
        $this->assertSame(0, DB::table('operations')->where('kind', 'app_delete')->count());
    }

    public function test_deleting_an_application_needs_no_typed_name(): void
    {
        Queue::fake();

        $this->post("/site/settings/{$this->site->id}/delete")->assertRedirect();
        $this->assertSame(1, DB::table('operations')->where('kind', 'app_delete')->count());
    }

    public function test_the_databases_page_lists_clusters_and_refuses_bad_input(): void
    {
        Queue::fake();
        $this->get('/databases')->assertOk();

        $this->assertSame([], $this->get('/databases')->inertiaProps('clusters'));
        $before = DB::table('operations')->count();
        $this->post('/databases', ['name' => 'main', 'engine' => 'oracle', 'region' => 'nyc3', 'size' => 'db-s-1vcpu-1gb'])->assertSessionHasErrors('name');
        $this->assertSame($before, DB::table('operations')->count(), 'Nothing new queued.');
    }

    public function test_creating_a_cluster_queues_an_operation(): void
    {
        Queue::fake();

        $this->post('/databases', ['name' => 'main', 'engine' => 'pg', 'region' => 'nyc3', 'size' => 'db-s-1vcpu-1gb'])->assertRedirect();

        $op = DB::table('operations')->where('kind', 'database_create')->sole();
        $this->assertSame(['create', 'online'], array_column(json_decode($op->stages, true), 'name'));
    }

    public function test_a_cluster_on_another_provider_is_a_server_we_run_and_needs_that_provider_connected(): void
    {
        Queue::fake();
        $hetzner = app(Providers::class)->get('hetzner')->catalog();
        $form = ['name' => 'main', 'provider' => 'hetzner', 'engine' => 'pg', 'region' => $hetzner->defaultRegion, 'size' => array_key_first($hetzner->sizes)];

        $this->post('/databases', $form)->assertSessionHasErrors('name');
        $this->assertSame(0, DB::table('operations')->where('kind', 'database_create')->count());

        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'hetzner', 'vault_key' => 'cloud.hetzner.token', 'created_at' => now(), 'updated_at' => now()]);
        $this->get('/databases')->assertInertia(fn (Assert $page) => $page->where('choices.hetzner.managed', false)->where('choices.hetzner.engines', ['pg' => 'PostgreSQL', 'mysql' => 'MySQL'])->where('choices.digitalocean.managed', true));
        $this->post('/databases', ['engine' => 'valkey'] + $form)->assertSessionHasErrors('name');

        $this->post('/databases', $form)->assertRedirect();
        $op = DB::table('operations')->where('kind', 'database_create')->sole();
        $this->assertSame(['create', 'enroll', 'setup'], array_column(json_decode($op->stages, true), 'name'));
        $this->assertSame('hetzner', json_decode($op->meta, true)['provider']);
    }

    public function test_settings_show_sqlite_until_a_cluster_is_attached(): void
    {
        $this->get("/site/settings?site={$this->site->id}&tab=database")
            ->assertInertia(fn (Assert $page) => $page->where('data.database.label', 'SQLite on web-1')->where('data.clusters', []));

        $this->post("/site/settings/{$this->site->id}/database/attach", ['cluster' => 999])->assertSessionHasErrors('cluster');
    }

    public function test_a_managed_database_can_be_disconnected_without_losing_it(): void
    {
        $cluster = DB::table('resources')->insertGetId([
            'organization_id' => 'org-1', 'type' => 'database_cluster', 'slug' => 'main', 'external_id' => 'x-main',
            'state' => 'active', 'desired_state' => 'active', 'attributes' => json_encode(['engine' => 'pg']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sites')->where('id', $this->site->id)->update(['database_cluster_id' => $cluster]);

        $this->get("/site/settings?site={$this->site->id}&tab=database")->assertInertia(fn (Assert $page) => $page->where('data.database.managed', true));
        $this->post("/site/settings/{$this->site->id}/database/detach")->assertSessionHas('status', fn ($status) => str_starts_with($status, 'Database detached.'));

        $this->assertNull(DB::table('sites')->where('id', $this->site->id)->value('database_cluster_id'));
        $this->assertSame('active', DB::table('resources')->where('id', $cluster)->value('state'), 'The cluster and its data stay.');
        $this->assertDatabaseHas('audit_records', ['action' => 'site.database_detached']);
    }

    public function test_a_detached_connection_is_shown_as_external_from_the_env(): void
    {
        app(Sites::class)->setEnvValues($this->site, [
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'DB_PORT' => '5432', 'DB_DATABASE' => 'shop',
        ]);

        $this->get("/site/settings?site={$this->site->id}&tab=database")
            ->assertInertia(fn (Assert $page) => $page->where('data.database.label', 'External PostgreSQL at db.example.com, database shop (unmanaged)'));
    }

    public function test_the_php_version_can_change_only_once_installed_on_the_server(): void
    {
        Queue::fake();
        $url = "/site/settings/{$this->site->id}/php";

        $this->get("/site/settings?site={$this->site->id}&tab=runtime")->assertInertia(fn (Assert $page) => $page->where('data.phpVersion', '8.4'));
        $this->post($url, ['version' => '8.3'])->assertSessionHasErrors('phpVersion');

        DB::table('server_php_versions')->insert(['node_id' => 'web-1.org-1', 'version' => '8.3', 'created_at' => now(), 'updated_at' => now()]);
        $this->post($url, ['version' => '8.3'])->assertSessionHas('status', 'PHP version saved: 8.3. It applies on the next deploy.');

        $this->assertSame('8.3', DB::table('sites')->where('id', $this->site->id)->value('php_version'));
        $this->assertDatabaseHas('audit_records', ['action' => 'site.php_version_changed']);
    }

    public function test_operation_show_prints_the_log_and_fails_with_the_operation(): void
    {
        $id = app(Operations::class)->create('org-1', 'deploy', 'Deploy demo', 'cli', 'me', ['build']);
        DB::table('operations')->where('id', $id)->update(['status' => 'failed', 'log' => "12:00:00  build    error   it broke\n"]);

        $this->artisan('operation:show', ['id' => $id, '--wait' => true])
            ->expectsOutputToContain('failed · Deploy demo')
            ->expectsOutputToContain('it broke')
            ->assertFailed();
    }

    public function test_a_server_is_added_only_once_the_site_has_a_shared_database(): void
    {
        Queue::fake();
        DB::table('nodes')->insert(['node_id' => 'web-2.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $add = fn (string $node) => $this->post("/site/settings/{$this->site->id}/servers/add", ['server' => $node]);

        $this->get("/site/settings?site={$this->site->id}&tab=servers")->assertInertia(fn (Assert $page) => $page->where('data.spare.0.name', 'web-2'));
        $add('web-2.org-1')->assertSessionHasErrors('server');
        $this->assertSame(0, DB::table('operations')->where('kind', 'server_add')->count());

        DB::table('sites')->where('id', $this->site->id)->update(['database_cluster_id' => 1]);
        $add('web-2.org-1')->assertRedirect()->assertSessionHasNoErrors();
        $op = DB::table('operations')->where('kind', 'server_add')->sole();
        $this->assertSame('admit', json_decode($op->stages, true)[0]['name']);

        $add('web-1.org-1')->assertSessionHasErrors('server');
        $this->post("/site/settings/{$this->site->id}/servers/remove", ['node' => 'web-1.org-1'])->assertSessionHasErrors('server');
    }

    public function test_busy_hours_are_added_and_removed_on_an_autoscaled_site(): void
    {
        $resources = new ResourceManager(new AuditLog);
        $pool = $resources->declare('org-1', 'autoscale_pool', 'demo-pool', ['min' => 1, 'max' => 3, 'cpu' => 0.6, 'site_id' => $this->site->id]);
        DB::table('sites')->where('id', $this->site->id)->update(['autoscale_pool_id' => $pool->id, 'load_balancer_id' => 99]);
        $schedule = fn () => $resources->findById($pool->id)->attributes['schedule'] ?? [];
        $url = "/site/settings/{$this->site->id}/windows";

        $this->post($url, ['days' => 'fri', 'from' => 22, 'to' => 2, 'min' => 3])->assertSessionHasNoErrors();
        $this->assertEquals([['days' => 'fri', 'from' => 22, 'to' => 2, 'min' => 3]], $schedule());
        $this->get("/site/settings?site={$this->site->id}&tab=servers")->assertInertia(fn (Assert $page) => $page->where('data.pool.schedule.0.to', 2)->where('data.pool.schedule.0.from', 22));

        $this->post($url, ['days' => 'fri', 'from' => 22, 'to' => 2, 'min' => 4])->assertSessionHasErrors('window');
        $this->assertCount(1, $schedule(), 'More than the pool allows is refused.');

        $this->post("{$url}/remove", ['index' => 0])->assertSessionHasNoErrors();
        $this->assertSame([], $schedule());
    }
}
