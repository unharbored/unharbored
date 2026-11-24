<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Projects;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Providers;
use App\Lib\Services\Catalogue;
use App\Lib\Services\Services;
use App\Lib\Templates\Sources;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

/** Installing a template: the form the page is given, the review, and what creating does. */
final class ServiceNewPageTest extends ServiceTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->node('web-2.org-2', 'org-2');
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }

    private function prepared(string $node = 'web-1.org-1'): void
    {
        DB::table('nodes')->where('node_id', $node)->update(['containers_ready_at' => now(), 'docker_version' => '29.0.1']);
    }

    /** What the page sends to check and create, for RustFS filled in. @return array<string, mixed> */
    private function answers(array $over = []): array
    {
        return array_replace([
            'template' => 'rustfs',
            'values' => ['name' => 'files', 's3_host' => 'files-s3.example.com', 'ui_host' => 'files-ui.example.com', 'storage' => '10GB'],
            'secrets' => [], 'node' => 'web-1.org-1', 'project' => '', 'acknowledged' => false, 'portsApproved' => false,
        ], $over);
    }

    private function check(array $over = [])
    {
        return $this->postJson('/services/new/check', $this->answers($over));
    }

    private function create(array $over = [])
    {
        return $this->postJson('/services/new/create', $this->answers($over));
    }

    private function connectHetzner(): void
    {
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'hetzner', 'vault_key' => 'cloud.hetzner.token', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** What the page sends to make a server for the service. @return array<string, string> */
    private function newServer(): array
    {
        $catalog = app(Providers::class)->get('hetzner')->catalog();
        $big = array_key_first(array_filter($catalog->sizes, fn ($s) => $s['memory_gb'] >= 1));

        return ['provider' => 'hetzner', 'region' => $catalog->defaultRegion, 'size' => $big];
    }

    public function test_a_connected_provider_offers_a_new_server_with_sizes_big_enough_and_none_without_one(): void
    {
        $this->member('manager');
        $this->get('/services/new?template=rustfs')->assertInertia(fn (Assert $p) => $p->where('newServer', []));

        $this->connectHetzner();
        $page = $this->get('/services/new?template=rustfs')->assertOk();
        $choice = $page->inertiaProps('newServer')['hetzner'];
        $catalog = app(Providers::class)->get('hetzner')->catalog();
        $this->assertSame($catalog->regions, $choice['regions']);
        $this->assertNotEmpty($choice['sizes']);
        foreach (array_keys($choice['sizes']) as $slug) {
            $this->assertGreaterThanOrEqual(1, $catalog->sizes[$slug]['memory_gb'], 'RustFS needs 1GB');
        }
    }

    public function test_a_container_template_or_one_that_asks_for_a_secret_is_not_offered_a_new_server(): void
    {
        config(['templates.offered' => ['rustfs', 'uptime-kuma']]);
        $this->member('manager');
        $this->connectHetzner();

        $this->get('/services/new?template=uptime-kuma')->assertInertia(fn (Assert $p) => $p->where('newServer', []));
        $this->create(['template' => 'uptime-kuma', 'values' => ['name' => 'status', 'host' => 'status.example.com'], 'node' => '', 'newServer' => $this->newServer(), 'acknowledged' => true])
            ->assertStatus(422)->assertJsonValidationErrors('node');
    }

    public function test_the_review_for_a_new_server_needs_no_existing_one_and_makes_no_record(): void
    {
        $this->member('manager');
        $this->connectHetzner();

        $review = $this->check(['node' => '', 'newServer' => $this->newServer(), 'values' => ['name' => 'files', 'storage' => '10GB']])->assertOk()->json();

        $this->assertTrue($review['newServer']);
        $this->assertStringStartsWith('A new Hetzner server (', $review['server']);
        $this->assertSame([], $review['dns'], 'free names need no DNS record');
        $this->assertNull($this->service());
        $this->assertSame(0, DB::table('operations')->count());
    }

    public function test_creating_with_a_new_server_queues_one_operation_that_makes_the_server_then_installs(): void
    {
        $this->member('manager');
        $this->connectHetzner();

        $id = $this->create(['node' => '', 'newServer' => $this->newServer()])->assertOk()->json('operation');

        $op = DB::table('operations')->where('id', $id)->first();
        $this->assertSame('service_create', $op->kind);
        $this->assertSame(['server', 'service'], array_column(json_decode($op->stages, true), 'name'));
        $meta = json_decode($op->meta, true);
        $this->assertSame(['svc-files', 'hetzner'], [$meta['server']['slug'], $meta['server']['provider']]);
        $this->assertSame('files-s3.example.com', $meta['inputs']['s3_host']);
        $this->assertNull($this->service(), 'the service is made once the server exists');
        $this->assertNull(DB::table('nodes')->where('node_id', 'svc-files.org-1')->first());
    }

    public function test_the_operation_waits_for_the_server_then_installs_on_it_and_finishes_when_the_service_does(): void
    {
        $this->member('manager');
        $this->connectHetzner();
        $id = $this->create(['node' => '', 'newServer' => $this->newServer()])->assertOk()->json('operation');
        $runner = $this->app->make(OperationRunner::class);

        $this->assertSame('waiting', $runner->run($id));
        $provision = DB::table('operations')->where('kind', 'provision')->first();
        $this->assertSame('svc-files.org-1', $provision->node_id);
        $this->assertSame('waiting', $runner->run($id), 'still being made');

        DB::table('operations')->where('id', $provision->id)->update(['status' => 'succeeded']);
        $this->node('svc-files.org-1', 'org-1');
        $this->assertSame('waiting', $runner->run($id));
        $service = $this->service();
        $this->assertSame('svc-files.org-1', $service->node_id);
        $install = DB::table('operations')->where('kind', 'template_service_install')->first();

        DB::table('operations')->where('id', $install->id)->update(['status' => 'succeeded']);
        $this->assertSame('succeeded', $runner->run($id));
    }

    public function test_the_operation_fails_and_says_where_the_server_is_when_the_server_cannot_be_made(): void
    {
        $this->member('manager');
        $this->connectHetzner();
        $id = $this->create(['node' => '', 'newServer' => $this->newServer()])->assertOk()->json('operation');
        $runner = $this->app->make(OperationRunner::class);
        $runner->run($id);
        DB::table('operations')->where('kind', 'provision')->update(['status' => 'failed', 'error' => 'no capacity']);

        $this->assertSame('failed', $runner->run($id));
        $this->assertStringContainsString('server failed', (string) DB::table('operations')->where('id', $id)->value('error'));
    }

    public function test_a_template_that_is_not_offered_has_no_form_and_cannot_be_installed_from_the_console(): void
    {
        config(['templates.offered' => ['rustfs']]);
        $this->member('manager');
        foreach ([['gitea', 'is not available to install yet'], ['uptime-kuma', 'is not available to install yet'], ['nope', 'There is no template called nope'], ['', 'Choose a template from the catalogue first']] as [$template, $says]) {
            $this->get('/services/new'.($template === '' ? '' : "?template={$template}"))->assertOk()
                ->assertInertia(fn (Assert $page) => $page->component('ServiceNew')->where('servers', [])->where('form.error', fn ($e) => str_contains($e, $says))->missing('form.fields'));
        }
        $this->create(['template' => 'gitea', 'values' => ['name' => 'g'], 'acknowledged' => true, 'portsApproved' => true])->assertStatus(422);
        $this->assertNull($this->service('g'));
    }

    public function test_the_form_is_generated_from_the_inputs_with_servers_and_fit(): void
    {
        $this->node('tiny.org-1', 'org-1', memGb: 0);
        DB::table('node_metrics')->where('node_id', 'tiny.org-1')->update(['mem_total' => 512 * 1024 ** 2, 'disk_free' => 100 * 1024 ** 3]);
        $this->member('manager');

        $page = $this->get('/services/new?template=rustfs')->assertOk();
        $page->assertInertia(fn (Assert $p) => $p->component('ServiceNew')->where('template', 'rustfs')->where('form.canInstall', true)->has('form.fields'));
        $fields = collect($page->inertiaProps('form')['fields'])->keyBy('key');
        $this->assertTrue($fields['name']['required']);
        $this->assertFalse($fields['s3_host']['required'], 'a blank domain gets a free name');
        $this->assertTrue($fields['s3_host']['generated']);
        $this->assertFalse($fields['name']['generated']);
        $this->assertSame('50GB', $fields['storage']['default']);
        $this->assertSame('snapshot', $fields['backup']['default']);
        $servers = collect($page->inertiaProps('servers'))->keyBy('name');
        $this->assertTrue($servers['web-1']['fits']);
        $this->assertFalse($servers['tiny']['fits']);
        $this->assertStringContainsString('needs at least 1GB of memory', $servers['tiny']['reason']);
        $this->assertArrayNotHasKey('web-2', $servers->all());

        // The disk check follows the storage input.
        $bigger = collect($this->postJson('/services/new/servers', ['template' => 'rustfs', 'values' => ['storage' => '500GB']])->assertOk()->json())->keyBy('name');
        $this->assertStringContainsString('needs about', $bigger['web-1']['reason']);
        $this->assertStringContainsString('free disk', $bigger['web-1']['reason']);
    }

    public function test_an_offline_server_does_not_fit_and_says_so(): void
    {
        $this->connected = false;
        $this->member('manager');
        $servers = $this->get('/services/new?template=rustfs')->inertiaProps('servers');
        $this->assertStringContainsString('is not online', $servers[0]['reason']);
    }

    public function test_a_server_whose_port_is_taken_is_greyed_out_with_the_reason_before_review(): void
    {
        $this->install();
        $this->member('manager');
        $web1 = collect($this->get('/services/new?template=rustfs')->inertiaProps('servers'))->firstWhere('name', 'web-1');
        $this->assertFalse($web1['fits']);
        $this->assertStringContainsString('already used by the service files', $web1['reason']);
    }

    public function test_the_review_lists_what_is_created_dns_the_download_and_the_role_check_and_creates_nothing(): void
    {
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-1', 'external_id' => '1', 'state' => 'active', 'desired_state' => 'present',
            'attributes' => json_encode(['ipv4' => '203.0.113.7']), 'created_at' => now(), 'updated_at' => now()]);
        $this->member('manager');

        $review = $this->check()->assertOk()->json();
        $this->assertSame(['files', '/srv/cloud-manager/data/files', '10GB', 'Allowed: you are a Manager'], [$review['service'], $review['data'], $review['storage'], $review['role']]);
        $this->assertSame(['files-s3.example.com', 'files-ui.example.com'], array_column($review['endpoints'], 'domain'));
        $this->assertSame([['files-s3.example.com', 'A', '203.0.113.7'], ['files-ui.example.com', 'A', '203.0.113.7']], array_map(fn ($d) => [$d['domain'], $d['type'], $d['value']], $review['dns']));
        $this->assertSame(['github.com', self::RUSTFS_SHA], [$review['download']['host'], $review['download']['sha256']]);
        $this->assertSame('snapshot', $review['backup']);
        $this->assertNull($review['label']);
        $this->assertNull($review['warning']);
        $this->assertNull($this->service('files'));
        $this->assertSame([], $this->recipes);
    }

    public function test_the_review_asks_for_aaaa_records_when_the_server_has_only_ipv6(): void
    {
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-1', 'external_id' => '1', 'state' => 'active', 'desired_state' => 'present',
            'attributes' => json_encode(['ipv4' => null, 'ipv6' => '2605:4840:3:18ea::100']), 'created_at' => now(), 'updated_at' => now()]);
        $this->member('manager');

        $review = $this->check()->assertOk()->json();
        $this->assertSame([['files-s3.example.com', 'AAAA', '2605:4840:3:18ea::100'], ['files-ui.example.com', 'AAAA', '2605:4840:3:18ea::100']], array_map(fn ($d) => [$d['domain'], $d['type'], $d['value']], $review['dns']));
    }

    public function test_the_review_marks_a_custom_template(): void
    {
        $dir = sys_get_temp_dir().'/cm-tpl-'.uniqid();
        mkdir($dir);
        file_put_contents("{$dir}/rustfs2.yml", str_replace(['id: rustfs', 'name: RustFS'], ['id: rustfs2', 'name: RustFS Two'], file_get_contents(base_path('templates/rustfs.yml'))));
        config(['templates.path' => $dir, 'templates.sources' => [['kind' => 'builtin'], ['kind' => 'local']], 'templates.allow_custom' => true, 'templates.offered' => ['rustfs2']]);
        foreach ([Sources::class, Catalogue::class, Services::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $this->member('manager');
        $review = $this->check(['template' => 'rustfs2'])->assertOk()->json();
        $this->assertSame('Custom, not reviewed', $review['label']);
        $this->assertStringContainsString('will download and run a program from github.com', $review['warning']);
        @unlink("{$dir}/rustfs2.yml");
        @rmdir($dir);
    }

    public function test_the_review_says_what_each_backup_choice_does(): void
    {
        $this->member('manager');
        foreach (['none', 'spaces', 'snapshot'] as $choice) {
            $this->node("{$choice}.org-1", 'org-1');
            $review = $this->check(['values' => ['name' => "r-{$choice}", 's3_host' => "{$choice}-s3.example.com", 'ui_host' => "{$choice}-ui.example.com", 'storage' => '10GB', 'backup' => $choice], 'node' => "{$choice}.org-1"])->assertOk()->json();
            $this->assertSame($choice, $review['backup']);
        }
    }

    public function test_a_bad_domain_or_size_shows_a_visible_error_and_nothing_is_queued(): void
    {
        $this->member('manager');
        $this->check(['values' => ['name' => 'files', 's3_host' => 'not a domain', 'ui_host' => 'b.example.com', 'storage' => 'lots']])
            ->assertStatus(422)->assertJsonValidationErrors(['values.s3_host', 'values.storage']);
        $this->assertNull($this->service('files'));

        // A server is required.
        $this->check(['node' => ''])->assertStatus(422)->assertJsonValidationErrors(['node']);
        // The placement checks of Services show too: a server too small.
        DB::table('node_metrics')->where('node_id', 'web-1.org-1')->update(['disk_free' => 1024 ** 3]);
        $this->check(['values' => ['name' => 'files', 's3_host' => 'a.example.com', 'ui_host' => 'b.example.com', 'storage' => '50GB']])
            ->assertStatus(422)->assertJsonValidationErrors(['form'])->assertJsonPath('errors.form.0', fn ($m) => str_contains($m, 'free disk'));
    }

    public function test_the_happy_path_ends_with_a_queued_operation_and_a_service_row(): void
    {
        $this->member('manager');
        $answer = $this->create()->assertOk();
        $service = $this->service('files');
        $this->assertNotNull($service);
        $this->assertSame('installing', $service->status);
        $this->assertSame('org-1', $service->organization_id);
        $op = DB::table('operations')->where('kind', 'template_service_install')->first();
        $this->assertSame('console', $op->trigger);
        $this->assertSame((int) $op->id, $answer->json('operation'));
        $this->assertSame((int) $service->id, json_decode($op->meta, true)['service_id']);
        $this->assertSame(Projects::ensureDefault('org-1'), (int) $service->project_id);
    }

    public function test_the_project_chosen_is_used_and_another_teams_project_is_refused(): void
    {
        $this->member('manager');
        $client = Projects::create('org-1', 'Client A', Actor::user('1', 'a@example.com'));
        $other = Projects::create('org-2', 'Globex work', Actor::user('1', 'a@example.com'));
        $projects = $this->get('/services/new?template=rustfs')->inertiaProps('form')['projects'];
        $this->assertContains('Client A', array_column($projects, 'name'));
        $this->assertNotContains('Globex work', array_column($projects, 'name'));

        $this->check(['project' => (string) $other])->assertStatus(422)->assertJsonPath('errors.form.0', fn ($m) => str_contains($m, 'choose one of this team'));
        $this->assertNull($this->service('files'));
        $this->assertSame('Client A', $this->check(['project' => (string) $client])->assertOk()->json('project'));
        $this->create(['project' => (string) $client])->assertOk();
        $this->assertSame($client, (int) $this->service('files')->project_id);
    }

    public function test_the_current_project_filter_is_the_default_project(): void
    {
        $this->member('manager');
        $client = Projects::create('org-1', 'Client A', Actor::user('1', 'a@example.com'));
        $this->withSession([Projects::SESSION_KEY => $client])->create()->assertOk();
        $this->assertSame($client, (int) $this->service('files')->project_id);
    }

    public function test_another_teams_server_cannot_be_used(): void
    {
        $this->member('manager');
        $this->check(['node' => 'web-2.org-2'])->assertStatus(422)->assertJsonPath('errors.form.0', fn ($m) => str_contains($m, 'There is no server web-2.org-2 in this team'));
        $this->create(['node' => 'web-2.org-2'])->assertStatus(422);
        $this->assertNull($this->service('files'));
    }

    public function test_the_runner_acknowledgement_and_its_secret_are_enforced_and_the_secret_is_never_kept(): void
    {
        config(['templates.offered' => ['rustfs', 'github-runner']]);
        $this->member('manager');
        $secret = 'ghp_TOP_SECRET_REGISTRATION_TOKEN';
        $runner = fn (array $over = []) => ['template' => 'github-runner', 'values' => ['name' => 'ci', 'url' => 'https://github.com/acme/app'], 'secrets' => ['token' => $secret], 'node' => 'web-1.org-1'] + $over;

        $page = $this->get('/services/new?template=github-runner');
        $this->assertSame('secret', collect($page->inertiaProps('form')['fields'])->firstWhere('key', 'token')['type']);
        $this->assertStringContainsString('This runner executes code', (string) $page->inertiaProps('form')['acknowledge']);
        $this->assertStringNotContainsString($secret, $page->getContent());

        $refused = $this->postJson('/services/new/check', $runner())->assertStatus(422)->assertJsonValidationErrors(['acknowledged']);
        $this->assertStringNotContainsString($secret, $refused->getContent());
        $this->assertNull($this->service('ci'));
        // Enforced on the server, not only in the form.
        $this->postJson('/services/new/create', $runner())->assertStatus(422);
        $this->assertNull($this->service('ci'));

        $this->assertStringNotContainsString($secret, $this->postJson('/services/new/check', $runner(['acknowledged' => true]))->assertOk()->getContent());
        $this->postJson('/services/new/create', $runner(['acknowledged' => true]))->assertOk();
        $service = $this->service('ci');
        $this->assertNotNull($service);
        $this->assertStringNotContainsString($secret, $service->inputs);
        $this->assertStringNotContainsString($secret, (string) json_encode(DB::table('operations')->get()));
        $this->assertSame($secret, app(Vault::class)->get('org-1', Services::secretName((int) $service->id, 'token')));
        $this->assertStringNotContainsString($secret, (string) json_encode(DB::table('audit_records')->get()));
    }

    public function test_the_page_source_clears_secrets_when_going_back_and_after_creating(): void
    {
        $source = (string) file_get_contents(resource_path('js/Pages/ServiceNew.vue'));
        $this->assertMatchesRegularExpression('/function back\(\) \{\s*step\.value = 1;\s*for \(const k of Object\.keys\(secrets\)\) secrets\[k\] = \'\';/', $source);
        $this->assertStringContainsString("secrets[k] = ''", substr($source, (int) strpos($source, 'async function create()')));
    }

    public function test_extra_ports_need_an_explicit_approval_on_the_server_and_pass_through_to_install(): void
    {
        config(['templates.offered' => ['gitea']]);
        $this->member('manager');
        $form = $this->get('/services/new?template=gitea')->inertiaProps('form');
        $this->assertSame(['2222/tcp · Git over SSH'], array_map(fn ($p) => "{$p['port']}/{$p['proto']} · {$p['label']}", $form['extraPorts']));
        $def = app(Sources::class)->load()->find('gitea')->definition;
        $inputs = ['name' => 'git'];
        foreach ($def->inputs as $i) {
            if ($i['type'] === 'domain') {
                $inputs[$i['key']] = "{$i['key']}.example.com";
            }
        }
        $call = fn (bool $approved) => $this->postJson('/services/new/create', ['template' => 'gitea', 'values' => $inputs, 'secrets' => [], 'node' => 'web-1.org-1', 'project' => '', 'acknowledged' => true, 'portsApproved' => $approved]);

        $this->assertSame(422, $call(false)->status());
        $this->assertNull($this->service('git'));
        $this->assertSame(200, $call(true)->status());
        $this->assertNotNull($this->service('git'));
        $audit = DB::table('audit_records')->where('action', 'service.ports_approved')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(['2222/tcp'], json_decode($audit[0]->metadata, true)['ports']);
        // Services itself still refuses without the approval.
        $this->expectException(\InvalidArgumentException::class);
        $this->services()->install('org-1', 'web-1.org-1', 'gitea', $inputs + ['name' => 'git2'], 'a@example.com', 'manager', acknowledged: true, dispatch: false);
    }

    // ---- a template that runs as a container -----------------------------------------------------

    public function test_the_form_lists_prepared_and_unprepared_servers_and_offers_preparing_to_a_manager(): void
    {
        $this->node('web-3.org-1', 'org-1');
        $this->prepared('web-3.org-1');
        $this->member('manager');
        $page = $this->get('/services/new?template=uptime-kuma')->assertOk();
        $this->assertSame('Uptime Kuma', $page->inertiaProps('form')['name']);
        $servers = collect($page->inertiaProps('servers'))->keyBy('name');
        $this->assertFalse($servers['web-1']['fits']);
        $this->assertTrue($servers['web-1']['prepare']);
        $this->assertStringContainsString('Not prepared for containers', $servers['web-1']['reason']);
        $this->assertTrue($servers['web-3']['fits']);
        $this->assertFalse($servers['web-3']['prepare']);
        $this->assertTrue($page->inertiaProps('form')['canInstall']);

        $this->member('developer');
        $this->get('/services/new?template=uptime-kuma')->assertInertia(fn (Assert $p) => $p->where('form.canInstall', false));
    }

    public function test_the_review_shows_the_digest_the_memory_limit_the_volume_and_loopback_only(): void
    {
        $this->prepared();
        $this->member('manager');
        $body = ['template' => 'uptime-kuma', 'values' => ['name' => 'kuma', 'host' => 'kuma.example.com'], 'node' => 'web-1.org-1'];
        $review = $this->postJson('/services/new/check', $body)->assertOk()->json();
        $this->assertSame('sha256:'.self::KUMA_DIGEST, $review['container']['digest']);
        $this->assertSame('512MB', $review['container']['memory']);
        $this->assertStringContainsString('louislam/uptime-kuma', $review['container']['image']);
        $this->assertSame(['kuma.example.com'], array_column($review['endpoints'], 'domain'));
        $this->assertContains('cm-svc-kuma-kuma', $review['container']['volumes']);
        $this->assertNull($review['download']['host'] ?? null);
        $this->assertNull($this->service('kuma'), 'the review creates nothing');

        // An unprepared node is refused at review.
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['containers_ready_at' => null]);
        $this->postJson('/services/new/check', $body)->assertStatus(422)->assertJsonPath('errors.form.0', fn ($m) => str_contains($m, 'not prepared for containers'));
    }

    public function test_the_console_installs_uptime_kuma(): void
    {
        $this->prepared();
        $this->member('manager');
        $this->postJson('/services/new/create', ['template' => 'uptime-kuma', 'values' => ['name' => 'kuma', 'host' => 'kuma.example.com'], 'secrets' => [], 'node' => 'web-1.org-1', 'project' => '', 'acknowledged' => false, 'portsApproved' => false])->assertOk();
        $row = $this->service('kuma');
        $this->assertSame(['container', 'installing'], [$row->runtime, $row->status]);
    }

    public function test_only_a_manager_may_check_or_create(): void
    {
        $this->member('developer');
        $this->check()->assertForbidden();
        $this->create()->assertForbidden();
        $this->get('/services/new?template=rustfs')->assertOk();
    }
}
