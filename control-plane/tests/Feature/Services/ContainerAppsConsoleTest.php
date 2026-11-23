<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Api\ApiTokens;
use App\Lib\Containers\ContainerApps;
use App\Lib\Git\AppDetector;
use App\Lib\Operations\OperationRunner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** Applications from a Dockerfile or an image in the console and the API: detection, the wizard, the pages, roles and isolation. */
final class ContainerAppsConsoleTest extends ServiceTestCase
{
    private const DIGEST_1 = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private const DIGEST_2 = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private string $tagDigest = self::DIGEST_1;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['org-1' => 'Acme', 'org-2' => 'Globex'] as $id => $name) {
            DB::table('organizations')->insertOrIgnore(['id' => $id, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->node('web-2.org-2', 'org-2');
        DB::table('nodes')->whereIn('node_id', ['web-1.org-1', 'web-2.org-2'])->update(['containers_ready_at' => now(), 'docker_version' => '29.0.0']);
        $this->execOutput = function (string $name, array $args): string {
            if ($name === 'runuser') {
                return "aaaaaaaaaaaa1111111111111111111111111111\n";
            }
            if ($name === 'docker' && ($args[1] ?? '') === 'inspect') {
                $ref = (string) end($args);
                if (str_contains((string) ($args[3] ?? ''), 'RepoDigests')) {
                    return preg_replace('/[:@].*$/', '', explode('/', $ref, 2)[count(explode('/', $ref)) > 1 ? 1 : 0] ?? $ref) === '' ? '' : \App\Lib\Containers\AppRecipes::repository($ref).'@'.(preg_match('/@(sha256:[0-9a-f]{64})$/', $ref, $m) ? $m[1] : $this->tagDigest)."\n";
                }

                return 'sha256:'.hash('sha256', $ref)."\n";
            }

            return '';
        };
    }

    private function member(string $role, string $org = 'org-1'): User
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => $org, 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        return $user;
    }


    private function git(): array
    {
        return ['url' => 'https://github.com/acme/shop.git', 'source' => 'https', 'host' => 'github.com', 'display' => 'acme/shop', 'default' => 'main', 'branches' => ['main'], 'fingerprint' => null, 'ssh_port' => 22, 'note' => 'public'];
    }

    private function fakeRepo(string $dockerfile, ?string $compose = null): void
    {
        Http::fake([
            'raw.githubusercontent.com/acme/shop/main/Dockerfile' => Http::response($dockerfile),
            'raw.githubusercontent.com/acme/shop/main/docker-compose.yml' => $compose === null ? Http::response('', 404) : Http::response($compose),
            'raw.githubusercontent.com/*' => Http::response('', 404),
            'github.com/*' => Http::response('', 404),
        ]);
    }

    private function make(array $input): object
    {
        $apps = $this->app->make(ContainerApps::class);
        $made = $apps->start('org-1', $apps->plan('org-1', $input), 'm@example.com');
        DB::table('operations')->where('kind', 'app_create')->update(['status' => 'succeeded']);

        return DB::table('sites')->where('id', $made['site'])->first();
    }

    private function dockerfileApp(): object
    {
        return $this->make(['kind' => 'dockerfile', 'name' => 'shop', 'node' => 'web-1.org-1', 'domain' => 'shop.example.com', 'git' => $this->git(), 'container_port' => 3000, 'branch' => 'main']);
    }

    private function imageApp(): object
    {
        return $this->make(['kind' => 'image', 'name' => 'tool', 'node' => 'web-1.org-1', 'domain' => 'tool.example.com', 'image' => 'ghcr.io/acme/tool:2.1', 'container_port' => 8080]);
    }

    private function deployed(object $site): void
    {
        $runner = $this->app->make(OperationRunner::class);
        $this->assertSame('succeeded', $runner->run($runner->recordDeploy((int) $site->id, 'console', 'm@example.com')));
    }

    // ---- detection -----------------------------------------------------------------------------

    /** @return array{file: string, port: ?int, ports: list<int>, compose: ?string}|null */
    private function detect(string $dockerfile, array $others = []): ?array
    {
        return AppDetector::dockerfile(fn (string $f): ?string => $f === 'Dockerfile' ? $dockerfile : ($others[$f] ?? null));
    }

    public function test_a_dockerfile_is_found_and_its_expose_port_read(): void
    {
        $found = $this->detect("FROM node:22\nWORKDIR /app\nEXPOSE 3000\nCMD [\"node\", \"server.js\"]\n");
        $this->assertSame(['Dockerfile', 3000, [3000], null], [$found['file'], $found['port'], $found['ports'], $found['compose']]);
    }

    public function test_expose_variants(): void
    {
        $this->assertSame(8080, $this->detect("EXPOSE 8080/tcp\n")['port']);
        $this->assertSame(80, $this->detect("expose 80 443\n")['port']);
        $this->assertSame([80, 443], $this->detect("EXPOSE 80 443\n")['ports']);
        $this->assertSame(3000, $this->detect("EXPOSE 3000-3005\n")['port'], 'the first of a range');
        $this->assertSame(8080, $this->detect("EXPOSE 5353/udp 8080/tcp # the web port\n")['port'], 'UDP and the trailing comment are ignored');
        $this->assertSame([8080, 9090], $this->detect("EXPOSE 8080\nFROM scratch\nEXPOSE 9090\n8080\n")['ports'], 'every stage, once each');
        $this->assertSame(['file' => 'Dockerfile', 'port' => null, 'ports' => [], 'compose' => null], $this->detect("FROM alpine\nCMD [\"sh\"]\n"), 'no EXPOSE line: found, no port');
        $this->assertNull($this->detect("FROM alpine\nEXPOSE \$PORT\n")['port'], 'a variable says nothing we can use');
        $this->assertNull($this->detect("FROM alpine\nEXPOSE 0 99999\n")['port']);
    }

    public function test_no_dockerfile_is_null_and_a_compose_file_is_noticed_never_run(): void
    {
        $this->assertNull(AppDetector::dockerfile(fn () => null));
        $this->assertNull(AppDetector::dockerfile(fn (string $f) => $f === 'Dockerfile' ? '  ' : null));
        $found = $this->detect("FROM alpine\nEXPOSE 80\n", ['docker-compose.yml' => "services:\n  web:\n    build: .\n"]);
        $this->assertSame('docker-compose.yml', $found['compose']);
        $this->assertSame('compose.yaml', $this->detect("FROM alpine\n", ['compose.yaml' => 'services: {}'])['compose']);
        $this->assertSame('docker/Dockerfile.prod', AppDetector::dockerfile(fn (string $f) => $f === 'docker/Dockerfile.prod' ? 'EXPOSE 81' : null, 'docker/Dockerfile.prod')['file']);
    }

    // ---- the wizard ----------------------------------------------------------------------------

    // ---- the pages --------------------------------------------------------------------------------

    public function test_the_applications_list_marks_container_applications_and_links_the_two_new_flows(): void
    {
        $this->member('manager');
        $this->dockerfileApp();
        $this->imageApp();
        app(\App\Lib\Sites\Sites::class)->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');

        $types = collect($this->get('/sites')->assertOk()->assertInertia(fn (Assert $page) => $page->where('canCreate', true)->etc())->inertiaProps('sites'))->pluck('type', 'name')->all();
        $this->assertSame('Dockerfile', $types['shop']);
        $this->assertSame('Docker image', $types['tool']);
        $this->assertNull($types['blog']);
    }

    public function test_the_site_page_of_a_dockerfile_application_shows_the_container_and_hides_the_php_and_worker_panels(): void
    {
        $this->member('manager');
        $site = $this->dockerfileApp();
        $this->deployed($site);

        $view = $this->get('/site?site='.$site->id)->assertOk()->inertiaProps('view');
        $text = json_encode($view, JSON_UNESCAPED_UNICODE);

        $this->assertSame('dockerfile', $view['container']['kind']);
        foreach (['Built from', 'container 3000 · 127.0.0.1:23000', 'Memory limit', '512MB'] as $shown) {
            $this->assertStringContainsString($shown, $text);
        }
        foreach (['Queue worker', 'Scheduler', 'Autoscale pool', 'Load balancer', 'Reverb', 'SQLite'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $text);
        }
        $this->assertNull($view['previews'], 'no preview prompt for a container application');
    }

    public function test_the_site_page_of_an_image_application_shows_the_image_and_the_pinned_digest(): void
    {
        $this->member('manager');
        $site = $this->imageApp();
        $this->deployed($site);

        $view = $this->get('/site?site='.$site->id)->assertOk()->inertiaProps('view');
        $text = json_encode($view, JSON_UNESCAPED_UNICODE);

        $this->assertSame('ghcr.io/acme/tool:2.1', $view['container']['image']);
        $this->assertSame('Docker image', $view['source']);
        foreach (['Digest', substr(self::DIGEST_1, 0, 19), 'Image'] as $shown) {
            $this->assertStringContainsString($shown, $text);
        }
        foreach (['Queue worker', 'Scheduler', 'Deploy key'] as $hidden) {
            $this->assertStringNotContainsString($hidden, $text);
        }
        $this->assertNull($view['previews']);
    }

    public function test_a_php_application_page_is_unchanged(): void
    {
        $this->member('manager');
        $id = (int) app(\App\Lib\Sites\Sites::class)->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git')->id;

        $view = $this->get("/site?site={$id}")->assertOk()->inertiaProps('view');
        $text = json_encode($view, JSON_UNESCAPED_UNICODE);

        $this->assertStringContainsString('Queue worker', $text);
        $this->assertStringContainsString('Scheduler', $text);
        $this->assertSame(0, $view['previews'], 'the set-up-previews prompt');
        $this->assertNull($view['container']);
    }

    public function test_the_settings_nav_of_a_container_application_has_no_scaling_workers_build_scheduler_script_or_previews(): void
    {
        $this->member('manager');
        $site = $this->dockerfileApp();
        $this->get("/site/settings?site={$site->id}&tab=web")->assertOk()->assertInertia(fn (Assert $page) => $page->component('Site/Settings')->where('site.runtime', 'dockerfile')->where('tab', 'web'));
        // The nav is the page's own: a container application's branch holds the container link and the web server, nothing else.
        $nav = (string) file_get_contents(resource_path('js/Pages/Site/Settings.vue'));
        preg_match('/<template v-if="container">(.*?)<\/template>/s', $nav, $branch);
        $this->assertStringContainsString("/site/container?site=\${site.id}", $branch[1]);
        $this->assertStringContainsString('Web server', $branch[1]);
        foreach (['Servers &amp; scaling', 'Workers &amp; processes', 'Type &amp; build', 'Scheduler', 'Server script', 'Previews', 'PHP version'] as $gone) {
            $this->assertStringNotContainsString($gone, $branch[1], $gone);
        }
        $this->assertStringContainsString("v-if=\"site.runtime !== 'image'\" :href=\"href('source')\"", $nav, 'an image application has no repository');
        $image = $this->imageApp();
        $this->get("/site/settings?site={$image->id}&tab=env")->assertOk()->assertInertia(fn (Assert $page) => $page->where('site.runtime', 'image'));
    }

    public function test_the_container_page_shows_settings_update_and_registry_by_role_and_never_a_token(): void
    {
        $this->member('manager');
        $site = $this->imageApp();
        $this->deployed($site);
        app(\App\Lib\Vault\Vault::class)->put('org-1', ContainerApps::registrySecret($site), 'ghp_PAGETOKEN');
        ContainerApps::merge((int) $site->id, ['registry' => ['server' => 'ghcr.io', 'user' => 'bot']]);

        $response = $this->get('/site/container?site='.$site->id)->assertOk();
        $view = $response->inertiaProps('view');
        $this->assertSame([self::DIGEST_1, true, true, ['server' => 'ghcr.io', 'user' => 'bot'], 23000], [$view['digest'], $view['canDeploy'], $view['canEdit'], $view['registry'], $view['hostPort']]);
        $this->assertStringNotContainsString('ghp_PAGETOKEN', $response->getContent());
        $this->assertSame(['port' => '8080', 'memory' => '512MB', 'readOnly' => false], array_intersect_key($view['form'], ['port' => 1, 'memory' => 1, 'readOnly' => 1]), 'the form starts from the saved settings');

        $this->member('viewer');
        $view = $this->get('/site/container?site='.$site->id)->assertOk()->inertiaProps('view');
        $this->assertSame([self::DIGEST_1, false, false], [$view['digest'], $view['canDeploy'], $view['canEdit']]);
        $this->member('developer');
        $view = $this->get('/site/container?site='.$site->id)->assertOk()->inertiaProps('view');
        $this->assertSame([true, true], [$view['canDeploy'], $view['canEdit']]);

        $dockerfile = $this->dockerfileApp();
        $this->member('manager');
        $view = $this->get('/site/container?site='.$dockerfile->id)->assertOk()->inertiaProps('view');
        $this->assertSame('dockerfile', $view['kind']);
    }

    public function test_the_update_flow_on_the_page_shows_old_and_new_digest_and_confirms_only_a_shown_digest(): void
    {
        $this->member('manager');
        $site = $this->imageApp();
        $this->deployed($site);
        $this->tagDigest = self::DIGEST_2;
        $page = '/site/container?site='.$site->id;

        $this->from($page)->post('/site/container/check', ['site' => $site->id])->assertRedirect($page);
        $found = $this->get($page)->inertiaProps('found');
        $this->assertSame([self::DIGEST_1, self::DIGEST_2, true], [$found['old'], $found['new'], $found['changed']]);

        $this->post('/site/container/update', ['site' => $site->id, 'digest' => $found['new']]);
        $op = DB::table('operations')->where('kind', 'deploy')->orderByDesc('id')->first();
        $this->assertSame(self::DIGEST_2, ContainerApps::state(DB::table('sites')->where('id', $site->id)->first())['pending_digest']);
        $this->post('/site/container/update', ['site' => $site->id, 'digest' => $found['new']])->assertRedirect();
        $this->assertNotNull($op);
    }

    public function test_saving_settings_marks_the_application_as_waiting_for_a_deploy(): void
    {
        $this->member('developer');
        $site = $this->dockerfileApp();
        $this->deployed($site);
        $page = '/site/container?site='.$site->id;

        $this->from($page)->post('/site/container/settings', ['site' => $site->id, 'port' => '4000', 'memory' => '1GB', 'health' => '/healthz', 'volumes' => '/data', 'readOnly' => true, 'dockerfile' => 'Dockerfile', 'context' => '.'])
            ->assertRedirect($page)->assertSessionHas('status', 'Saved. It reaches the container on the next deploy.');
        $state = ContainerApps::state(DB::table('sites')->where('id', $site->id)->first());
        $this->assertSame([4000, '1GB', '/healthz', ['/data'], true], [$state['container_port'], $state['memory'], $state['health'], $state['volumes'], $state['read_only']]);
        $this->assertNotNull(DB::table('sites')->where('id', $site->id)->value('pending_since'));
        $errors = $this->from($page)->post('/site/container/settings', ['site' => $site->id, 'port' => '99999', 'memory' => '1GB', 'health' => '', 'volumes' => '', 'dockerfile' => 'Dockerfile', 'context' => '.'])
            ->assertSessionHasErrors('container_port')->baseResponse->getSession()->get('errors')->first('container_port');
        $this->assertStringContainsString('the port the application listens on', $errors);
    }

    // ---- roles and isolation ----------------------------------------------------------------------------

    public function test_every_new_method_is_gated(): void
    {
        $this->member('manager');
        $site = $this->imageApp();
        $this->deployed($site);
        $posts = ['/site/container/settings' => ['port' => 80], '/site/container/registry' => ['registryUser' => 'u', 'registryToken' => 't'],
            '/site/container/check' => [], '/site/container/update' => ['digest' => self::DIGEST_2]];

        $this->member('viewer');
        foreach ($posts as $url => $data) {
            $this->post($url, ['site' => $site->id] + $data)->assertForbidden();
        }
        $this->get('/site/container?site='.$site->id)->assertOk();

        $this->member('developer');
        foreach (['/site/container/settings' => ['port' => 8080], '/site/container/registry' => ['registryUser' => '', 'registryToken' => ''], '/site/container/check' => []] as $url => $data) {
            $this->assertNotSame(403, $this->post($url, ['site' => $site->id] + $data)->status(), "{$url} for a Developer");
        }
    }

    public function test_another_teams_application_cannot_be_touched_by_id_and_another_teams_server_cannot_be_used(): void
    {
        $this->member('manager');
        $site = $this->imageApp();
        $this->deployed($site);

        $this->member('manager', 'org-2');
        $this->post('/site/container/settings', ['site' => $site->id, 'port' => 81])->assertNotFound();
        $this->post('/site/container/registry', ['site' => $site->id, 'registryUser' => 'u', 'registryToken' => 't'])->assertNotFound();
        $this->post('/site/container/check', ['site' => $site->id])->assertNotFound();
        $this->post('/site/container/update', ['site' => $site->id, 'digest' => self::DIGEST_2])->assertNotFound();
        $response = $this->get('/site/container?site='.$site->id);
        $this->assertTrue($response->inertiaProps('view.missing'));
        $this->assertStringNotContainsString('ghcr.io/acme/tool', $response->getContent());
        $this->assertSame(8080, ContainerApps::state(DB::table('sites')->where('id', $site->id)->first())['container_port']);
        $this->assertSame(0, DB::table('sites')->where('organization_id', 'org-2')->count());
    }

    // ---- the API ------------------------------------------------------------------------------------------

    private function bearer(string $scope = 'read', string $org = 'org-1'): array
    {
        return ['Authorization' => 'Bearer '.$this->app->make(ApiTokens::class)->issue($org, "t {$scope}", $scope, 'me')['token']];
    }

    public function test_the_api_lists_container_applications_with_their_runtime_and_no_secret(): void
    {
        $this->member('manager');
        $image = $this->make(['kind' => 'image', 'name' => 'tool', 'node' => 'web-1.org-1', 'domain' => 'tool.example.com', 'image' => 'ghcr.io/acme/tool:2.1', 'container_port' => 8080, 'registry_user' => 'bot', 'registry_token' => 'ghp_APITOKEN']);
        $this->deployed($image);
        $this->dockerfileApp();
        app(\App\Lib\Sites\Sites::class)->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');

        $r = $this->getJson('/api/v1/sites', $this->bearer())->assertOk();
        $by = collect($r->json('sites'))->keyBy('name');
        $this->assertSame(['image', 'dockerfile', 'php'], [$by['tool']['runtime'], $by['shop']['runtime'], $by['blog']['runtime']]);
        $this->assertSame(['image' => 'ghcr.io/acme/tool:2.1', 'digest' => self::DIGEST_1, 'dockerfile' => null, 'container_port' => 8080, 'memory' => '512MB', 'private_registry' => true], $by['tool']['container']);
        $this->assertSame('Dockerfile', $by['shop']['container']['dockerfile']);
        $this->assertArrayNotHasKey('container', $by['blog']);
        $this->assertStringNotContainsString('ghp_APITOKEN', $r->getContent());
        $detail = $this->getJson('/api/v1/sites/tool', $this->bearer())->assertOk()->assertJsonPath('runtime', 'image');
        $this->assertStringNotContainsString('ghp_APITOKEN', $detail->getContent());
        $this->getJson('/api/v1/sites/tool', $this->bearer('read', 'org-2'))->assertNotFound();
    }

    public function test_the_api_deploy_and_rollback_work_for_a_container_application(): void
    {
        $this->member('manager');
        $site = $this->dockerfileApp();
        DB::table('operations')->where('kind', 'app_create')->update(['status' => 'succeeded']);

        $id = $this->postJson('/api/v1/sites/shop/deploy', [], $this->bearer('deploy'))->assertStatus(202)->json('operation');
        $this->assertSame(['setup', 'fetch', 'build', 'start', 'health', 'switch', 'prune'], array_column(json_decode((string) DB::table('operations')->where('id', $id)->value('stages'), true), 'name'));
        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run((int) $id));
    }

    // ---- the migration ------------------------------------------------------------------------------------

    public function test_repository_and_deploy_key_are_nullable_and_the_rest_of_the_row_is_unchanged(): void
    {
        $columns = collect(\Illuminate\Support\Facades\Schema::getColumns('sites'))->keyBy('name');
        $this->assertTrue($columns['repository']['nullable']);
        $this->assertTrue($columns['deploy_key_public']['nullable']);
        $this->assertTrue($columns['container']['nullable']);
        $this->assertFalse($columns['name']['nullable']);
        $this->assertFalse($columns['domain']['nullable']);
        $this->assertSame(1, collect(\Illuminate\Support\Facades\Schema::getIndexes('sites'))->where('unique', true)->where('columns', ['node_id', 'name'])->count(), 'the unique index survived the column change');
    }
}
