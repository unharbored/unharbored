<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Containers\AppRecipes;
use App\Lib\Containers\ContainerApps;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/** The Dockerfile and Docker image wizard: what the page is given, what it looks up, the review and creating. */
final class ContainerAppNewPageTest extends ServiceTestCase
{
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
                    return AppRecipes::repository($ref).'@'.(preg_match('/@(sha256:[0-9a-f]{64})$/', $ref, $m) ? $m[1] : 'sha256:'.str_repeat('1', 64))."\n";
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

    /** What the Dockerfile form sends to check and create. @return array<string, mixed> */
    private function dockerfileInput(array $over = []): array
    {
        return array_replace(['kind' => 'dockerfile', 'name' => 'shop', 'node' => 'web-1.org-1', 'project' => 0, 'domain' => 'shop.example.com', 'git' => $this->git(), 'repository' => 'acme/shop', 'branch' => 'main', 'dockerfile' => '', 'context' => '', 'container_port' => 3000,
            'health' => '', 'memory' => '', 'read_only' => false, 'volumes' => '', 'env' => [], 'image' => '', 'registry_user' => '', 'registry_token' => ''], $over);
    }

    private function imageInput(array $over = []): array
    {
        return array_replace($this->dockerfileInput(['kind' => 'image', 'git' => [], 'repository' => '', 'branch' => '', 'domain' => '', 'name' => 'tool', 'container_port' => 8080, 'image' => 'ghcr.io/acme/tool:2.1']), $over);
    }

    private function check(array $input)
    {
        return $this->postJson('/apps/container/check', ['input' => $input]);
    }

    // ---- the page and what it looks up -------------------------------------------------------

    public function test_each_kind_is_given_its_page(): void
    {
        $this->member('manager');
        $this->get('/apps/container?kind=dockerfile')->assertOk()->assertInertia(fn (Assert $p) => $p->component('ContainerAppNew')->where('kind', 'dockerfile')->has('options.servers'));
        $this->get('/apps/container?kind=image')->assertOk()->assertInertia(fn (Assert $p) => $p->where('kind', 'image'));
        $this->get('/apps/container')->assertInertia(fn (Assert $p) => $p->where('kind', 'dockerfile'));
        $source = (string) file_get_contents(resource_path('js/Pages/ContainerAppNew.vue'));
        $this->assertStringContainsString('has not reviewed an image you name', $source);
        $this->assertStringContainsString('Registry token', $source);
        $this->assertStringContainsString('Dockerfile path', $source);
    }

    public function test_the_wizard_says_we_found_a_dockerfile_with_its_port_and_that_compose_is_never_run(): void
    {
        $this->member('manager');
        $this->fakeRepo("FROM node:22\nEXPOSE 3000\n", "services:\n  web: {}\n");

        $found = $this->postJson('/apps/container/detection', ['repo' => 'acme/shop', 'branch' => 'main', 'git' => $this->git(), 'dockerfile' => ''])->assertOk()->json();
        $this->assertSame([true, 'Dockerfile', 3000, 'docker-compose.yml'], [$found['found'], $found['file'], $found['port'], $found['compose']]);
    }

    public function test_the_wizard_says_so_when_there_is_no_dockerfile_or_no_expose_line(): void
    {
        $this->member('manager');
        $this->fakeRepo("FROM alpine\nCMD [\"sh\"]\n");
        $found = $this->postJson('/apps/container/detection', ['repo' => 'acme/shop', 'branch' => 'main', 'git' => $this->git(), 'dockerfile' => ''])->json();
        $this->assertTrue($found['found']);
        $this->assertNull($found['port']);

        Http::fake(['raw.githubusercontent.com/*' => Http::response('', 404), 'github.com/*' => Http::response('', 404)]);
        $this->assertSame(['found' => false, 'file' => 'Dockerfile'], $this->postJson('/apps/container/detection', ['repo' => 'acme/shop', 'branch' => 'other', 'git' => $this->git(), 'dockerfile' => ''])->json());
        $this->assertSame([], $this->postJson('/apps/container/detection', ['repo' => '', 'branch' => '', 'git' => []])->json());
    }

    public function test_the_form_lists_the_small_server_and_the_unprepared_server_with_a_way_to_prepare_it(): void
    {
        $this->member('manager');
        DB::table('node_metrics')->where('node_id', 'web-1.org-1')->update(['mem_total' => 1024 ** 3]);
        DB::table('nodes')->insert(['node_id' => 'raw-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $servers = collect($this->get('/apps/container?kind=dockerfile')->inertiaProps('options')['servers'])->keyBy('id');
        $this->assertSame([true, true], [$servers['web-1.org-1']['prepared'], $servers['web-1.org-1']['small']]);
        $this->assertFalse($servers['raw-1.org-1']['prepared']);
        $this->assertTrue($this->get('/apps/container')->inertiaProps('options')['canPrepare']);

        // After preparing a server in another tab: the same answer, fetched again.
        $this->getJson('/apps/container/options?kind=image')->assertOk()->assertJsonPath('canPrepare', true);

        $this->member('developer');
        $this->get('/apps/container')->assertForbidden();
        $this->getJson('/apps/container/options?kind=image')->assertForbidden();
    }

    // ---- check and create ---------------------------------------------------------------------

    public function test_a_dockerfile_application_from_the_form_to_its_operation(): void
    {
        $user = $this->member('manager');
        $this->fakeRepo("FROM node:22\nEXPOSE 3000\n");

        $review = $this->check($this->dockerfileInput())->assertOk()->json();
        $this->assertSame(['dockerfile', 'shop', 'web-1', 'shop.example.com', 'Dockerfile', 3000, '512MB'], [$review['kind'], $review['name'], $review['node'], $review['domain'], $review['settings']['dockerfile'], $review['settings']['container_port'], $review['settings']['memory']]);
        $this->assertNull(DB::table('sites')->where('name', 'shop')->first(), 'the review creates nothing');

        $answer = $this->postJson('/apps/container/create', ['input' => $this->dockerfileInput()])->assertOk();
        $op = DB::table('operations')->orderByDesc('id')->first();
        $this->assertSame(['app_create', $user->email, $op->id], [$op->kind, $op->actor, $answer->json('operation')]);
        $site = DB::table('sites')->where('name', 'shop')->first();
        $this->assertSame(['dockerfile', 3000], [$site->runtime, ContainerApps::state($site)['container_port']]);
    }

    public function test_wizard_validation_shows_on_the_form_for_both_kinds(): void
    {
        $this->member('manager');
        $this->fakeRepo("FROM node:22\n");
        foreach ([
            [$this->dockerfileInput(['name' => 'Bad Name']), 'name', 'lowercase letters, digits and dashes'],
            [$this->dockerfileInput(['container_port' => '']), 'container_port', 'the port the application listens on'],
            [$this->dockerfileInput(['dockerfile' => '../Dockerfile']), 'dockerfile', 'a path inside the repository'],
            [$this->imageInput(['image' => 'nginx', 'container_port' => 80]), 'image', 'give a tag, such as nginx:1.27'],
            [$this->imageInput(['image' => 'nginx:1.27', 'container_port' => 80, 'registry_user' => 'bot']), 'registry_user', 'needs a user name and a token'],
            [$this->imageInput(['image' => 'nginx:1.27', 'container_port' => '']), 'container_port', 'the port the application listens on'],
        ] as [$input, $field, $says]) {
            $this->check($input)->assertStatus(422)->assertJsonPath("errors.{$field}.0", fn ($m) => str_contains($m, $says));
        }
        // Every field the planner can name has a place on the form to show it.
        $source = (string) file_get_contents(resource_path('js/Pages/ContainerAppNew.vue'));
        preg_match_all("/InvalidArgumentException\\(['\"]([a-z_]+):/", (string) file_get_contents(app_path('Lib/Containers/ContainerApps.php')), $m);
        foreach (array_unique($m[1]) as $key) {
            $this->assertTrue(str_contains($source, "errors.{$key}") || str_contains($source, 'v-for="(message, key) in errors"'), "A '{$key}' error from the planner is never shown.");
        }
    }

    public function test_an_image_application_shows_the_digest_and_never_sends_the_token_back(): void
    {
        $this->member('manager');
        $input = $this->imageInput(['registry_user' => 'bot', 'registry_token' => 'ghp_FORMTOKEN']);
        $review = $this->check($input)->assertOk();
        $this->assertSame(['image', 'bot'], [$review->json('kind'), $review->json('registry.user')]);
        $this->assertStringNotContainsString('ghp_FORMTOKEN', $review->getContent(), 'a typed token is never sent back');

        $this->postJson('/apps/container/create', ['input' => $input])->assertOk();
        $site = DB::table('sites')->where('name', 'tool')->first();
        $this->assertSame('image', $site->runtime);
        $this->assertSame('ghp_FORMTOKEN', app(Vault::class)->get('org-1', ContainerApps::registrySecret($site)));
        $this->assertStringNotContainsString('ghp_FORMTOKEN', json_encode(DB::table('operations')->get()).json_encode(DB::table('audit_records')->get()));
        $source = (string) file_get_contents(resource_path('js/Pages/ContainerAppNew.vue'));
        $this->assertStringContainsString("s.registryToken = ''", substr($source, (int) strpos($source, 'async function create()')));
    }

    // ---- roles and isolation ---------------------------------------------------------------------

    public function test_creating_needs_the_manager_role(): void
    {
        $this->member('viewer');
        $this->get('/apps/container')->assertForbidden();
        $this->check($this->imageInput())->assertForbidden();
        $this->postJson('/apps/container/create', ['input' => $this->imageInput()])->assertForbidden();
        $this->postJson('/apps/container/inspect', ['input' => 'acme/x'])->assertForbidden();

        $this->member('developer');
        $this->check($this->imageInput())->assertForbidden();
        $this->postJson('/apps/container/create', ['input' => $this->imageInput()])->assertForbidden();
        $this->postJson('/apps/container/inspect', ['input' => 'acme/x'])->assertForbidden();

        $this->member('manager');
        $this->assertNotSame(403, $this->check($this->imageInput())->status());
    }

    public function test_another_teams_server_cannot_be_used(): void
    {
        $this->member('manager', 'org-2');
        $this->check($this->imageInput(['node' => 'web-1.org-1', 'image' => 'nginx:1', 'container_port' => 80]))->assertStatus(422);
        $this->assertSame(0, DB::table('sites')->where('organization_id', 'org-2')->count());
    }
}
