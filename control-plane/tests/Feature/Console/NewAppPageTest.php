<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** The new application wizard: what the page is given, what it looks up, and how its answers are checked. */
final class NewAppPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'digitalocean', 'vault_key' => 'cloud.digitalocean.token', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'provider' => 'digitalocean', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('resources')->insert(['organization_id' => 'org-1', 'type' => 'server', 'slug' => 'web-1', 'external_id' => '1', 'state' => 'active',
            'desired_state' => 'present', 'attributes' => json_encode(['ipv4' => '203.0.113.7']), 'created_at' => now(), 'updated_at' => now()]);
    }

    /** What the wizard sends to check and create: the same shape the page builds. @return array<string, mixed> */
    private function input(array $over = []): array
    {
        return array_replace([
            'project' => 0, 'provider' => 'digitalocean', 'git' => [], 'name' => 'shop', 'repository' => 'acme/shop', 'branch' => 'main', 'runtime' => 'php', 'runtime_config' => [], 'root_dir' => '',
            'compute' => 'single', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1'], 'pool' => ['min' => 1, 'max' => 4, 'cpu' => 70, 'size' => 's-2vcpu-4gb'],
            'database' => 'sqlite', 'cache' => 'database', 'database_size' => 'db-s-1vcpu-1gb', 'cache_size' => 'db-s-1vcpu-1gb', 'storage' => 'disk', 'env' => [], 'previews' => false,
        ], $over);
    }

    private function check(array $over = [])
    {
        return $this->postJson('/apps/new/check', ['input' => $this->input($over)]);
    }

    public function test_the_page_renders_with_what_it_needs(): void
    {
        $this->get('/apps/new')->assertOk()->assertInertia(fn (Assert $page) => $page->component('NewApp')
            ->where('options.provider', 'digitalocean')->has('options.regions')->has('options.sizes')->has('options.runtimes')->where('options.servers.0.id', 'web-1.org-1'));
        $this->assertStringContainsString('paste any repository', (string) file_get_contents(resource_path('js/Pages/NewApp.vue')));
    }

    public function test_a_single_server_application_from_review_to_its_operation(): void
    {
        Queue::fake();
        $review = $this->check(['env' => ['STRIPE_SECRET' => 'sk_test_1']])->assertOk();
        $this->assertSame(['shop', 'single'], [$review->json('name'), $review->json('compute')]);
        $this->assertSame('address → deploy → https', implode(' → ', array_slice($review->json('steps'), 0, 3)));
        $this->assertStringContainsString('SQLite', $review->json('summary'));
        $this->assertStringContainsString('cache in the database', $review->json('summary'));
        $this->assertSame(1, count($review->json('env')));
        $this->assertNull(DB::table('sites')->where('name', 'shop')->first(), 'the review creates nothing');

        $answer = $this->postJson('/apps/new/create', ['input' => $this->input(['env' => ['STRIPE_SECRET' => 'sk_test_1']])])->assertOk();
        $op = DB::table('operations')->orderByDesc('id')->first();
        $this->assertSame(['app_create', 'matt@example.com', $op->id], [$op->kind, $op->actor, $answer->json('operation')]);
        $this->assertSame('single', DB::table('sites')->where('name', 'shop')->value('compute'));
    }

    public function test_a_bad_answer_shows_on_its_step(): void
    {
        $this->check(['name' => 'Bad Name'])->assertStatus(422)->assertJsonPath('errors.name.0', fn ($m) => str_contains($m, 'lowercase letters, digits and dashes'));
        $this->postJson('/apps/new/create', ['input' => $this->input(['name' => 'Bad Name'])])->assertStatus(422);
        $this->assertNull(DB::table('sites')->where('name', 'Bad Name')->first());
    }

    public function test_reviewing_without_choosing_a_server_says_so_beside_the_server(): void
    {
        $this->check(['server' => ['mode' => 'existing', 'node' => '']])->assertStatus(422)->assertJsonPath('errors.server.0', fn ($m) => str_contains($m, 'pick one of the servers'));
    }

    public function test_every_error_the_planner_can_raise_has_a_place_on_the_page(): void
    {
        preg_match_all("/InvalidArgumentException\\(['\"]([a-z_]+):/", (string) file_get_contents(app_path('Lib/Sites/NewApplications.php')), $m);
        $page = (string) file_get_contents(resource_path('js/Pages/NewApp.vue'));
        foreach (array_unique($m[1]) as $key) {
            $this->assertStringContainsString("errors.{$key}", $page, "A '{$key}' error from the planner is never shown, so Review looks like it does nothing.");
        }
    }

    public function test_with_no_provider_and_no_server_the_wizard_says_so_before_asking_anything(): void
    {
        DB::table('cloud_credentials')->delete();
        DB::table('nodes')->delete();

        $options = $this->get('/apps/new')->inertiaProps('options');
        $this->assertSame('ssh', $options['method']);
        $this->assertLessThanOrEqual(1, count($options['providers']));
        $this->assertSame([], $options['servers']);
        $source = (string) file_get_contents(resource_path('js/Pages/NewApp.vue'));
        foreach (['You need a server first', 'Connect a cloud provider', 'Add your own server'] as $says) {
            $this->assertStringContainsString($says, $source);
        }
    }

    public function test_a_second_provider_shows_its_catalog_and_its_locked_shape_with_the_reason(): void
    {
        DB::table('cloud_credentials')->insert(['organization_id' => 'org-1', 'provider' => 'aws', 'vault_key' => 'cloud.aws.token', 'created_at' => now(), 'updated_at' => now()]);

        $providers = array_column($this->get('/apps/new')->inertiaProps('options')['providers'], 'id');
        $this->assertContains('digitalocean', $providers);
        $this->assertContains('aws', $providers);
        $aws = $this->getJson('/apps/new/options?provider=aws')->assertOk()->json();
        $this->assertFalse($aws['shapes']['autoscale']['available']);
        $this->assertStringContainsString('AWS does not offer one yet', $aws['shapes']['autoscale']['reason']);
        $this->assertArrayHasKey('t3.small', $aws['sizes']);
        $this->assertArrayNotHasKey('s-1vcpu-1gb', $aws['sizes']);
        $this->assertNotContains('object_storage', $aws['supplies']);
        $defaults = $this->getJson('/apps/new/defaults?provider=aws')->assertOk()->json();
        $this->assertArrayHasKey($defaults['size'], $aws['sizes']);
        $this->assertArrayHasKey($defaults['region'], $aws['regions']);
    }

    public function test_changing_provider_does_not_ask_github_for_the_repositories_again(): void
    {
        DB::table('github_apps')->insert(['organization_id' => 'org-1', 'app_id' => 1, 'slug' => 's', 'owner' => 'acme-dev', 'html_url' => 'h', 'created_at' => now(), 'updated_at' => now()]);
        Http::fake();

        $options = $this->getJson('/apps/new/options?provider=digitalocean&repositories=0')->assertOk()->json();

        Http::assertNothingSent();
        $this->assertArrayNotHasKey('repos', $options, 'The page keeps the list it already has.');
        $this->assertArrayHasKey('servers', $options);
    }

    public function test_the_data_step_offers_services_on_the_server_and_none_and_reviews_them(): void
    {
        $review = $this->check(['database' => 'local:pgsql', 'cache' => 'local:valkey'])->assertOk();
        $this->assertStringContainsString('PostgreSQL on the server', $review->json('summary'));
        $this->assertStringContainsString('Valkey on the server', $review->json('summary'));
        $this->assertStringContainsString('database → cache', implode(' → ', $review->json('steps')));
        $source = (string) file_get_contents(resource_path('js/Pages/NewApp.vue'));
        foreach (['PostgreSQL on this server', 'MySQL on this server', 'Valkey on this server', 'None: managed by you'] as $offered) {
            $this->assertStringContainsString($offered, $source);
        }
    }

    // ---- reading the repository ------------------------------------------------------------------

    /** A public GitHub repository holding a Go API, a Laravel app and a library. @return array<string, mixed> what the page sends to look at it */
    private function monorepo(array $over = []): array
    {
        Http::fake([
            'api.github.com/repos/acme/platform/git/trees/main*' => Http::response(['tree' => [
                ['path' => 'apps/api/go.mod', 'type' => 'blob'], ['path' => 'apps/web/composer.json', 'type' => 'blob'], ['path' => 'packages/ui/package.json', 'type' => 'blob'],
            ]]),
            'raw.githubusercontent.com/acme/platform/main/apps/api/go.mod' => Http::response("module api\n\ngo 1.24\n"),
            'raw.githubusercontent.com/acme/platform/main/apps/web/composer.json' => Http::response('{"require":{"laravel/framework":"^12.0"}}'),
            'raw.githubusercontent.com/acme/platform/main/packages/ui/package.json' => Http::response('{"name":"ui","main":"index.js"}'),
            'raw.githubusercontent.com/*' => Http::response('', 404),
            'github.com/*' => Http::response('', 404),
        ]);
        $git = ['url' => 'https://github.com/acme/platform', 'source' => 'https', 'host' => 'github.com', 'display' => 'acme/platform', 'default' => 'main', 'branches' => ['main'], 'fingerprint' => null, 'ssh_port' => 22, 'note' => ''];

        return array_replace(['repo' => 'acme/platform', 'branch' => 'main', 'git' => $git, 'rootDir' => '', 'rootSet' => false], $over);
    }

    public function test_a_repository_with_several_applications_asks_which_and_does_not_choose_for_the_person(): void
    {
        $found = $this->postJson('/apps/new/apps', $this->monorepo())->assertOk()->json();
        $apps = collect($found['apps'])->keyBy('dir');
        $this->assertSame(['Go 1.24', 'Laravel 12.0'], [$apps['apps/api']['label'], $apps['apps/web']['label']]);
        $this->assertNull($apps['packages/ui']['runtime'], 'a library has nothing to deploy');
        $this->assertNull($found['dir'], 'several applications: none is chosen for the person');

        $this->postJson('/apps/new/source', ['input' => $this->input(['root_dir' => $found['dir'], 'runtime' => ''])])->assertStatus(422)
            ->assertJsonPath('errors.root_dir.0', fn ($m) => str_contains($m, 'Choose which one this is.'));
    }

    public function test_choosing_an_application_fills_in_its_type_and_folder_and_moves_on(): void
    {
        $web = $this->postJson('/apps/new/apps', $this->monorepo(['rootDir' => 'apps/web', 'rootSet' => true]))->assertOk()->json();
        $this->assertSame(['apps/web', 'Laravel 12.0', 'composer.json', 'php'], [$web['dir'], $web['app']['label'], $web['app']['file'], $web['app']['runtime']]);
        $this->postJson('/apps/new/source', ['input' => $this->input(['root_dir' => $web['dir'], 'runtime' => $web['app']['runtime']])])->assertOk();

        $api = $this->postJson('/apps/new/apps', $this->monorepo(['rootDir' => 'apps/api', 'rootSet' => true]))->json();
        $this->assertSame(['apps/api', 'Go 1.24'], [$api['dir'], $api['app']['label']]);
    }

    public function test_a_folder_can_be_named_by_hand_and_then_needs_a_type(): void
    {
        $custom = $this->postJson('/apps/new/apps', $this->monorepo(['rootDir' => 'services/billing', 'rootSet' => true]))->assertOk()->json();
        $this->assertSame('services/billing', $custom['dir']);
        $this->assertNull($custom['app']['runtime'] ?? null);
        $this->postJson('/apps/new/source', ['input' => $this->input(['root_dir' => $custom['dir'], 'runtime' => ''])])->assertStatus(422)
            ->assertJsonPath('errors.runtime.0', fn ($m) => str_contains($m, 'Nothing here says what this is. Choose a type.'));
    }

    public function test_a_repository_that_cannot_be_read_asks_for_a_type_instead_of_guessing(): void
    {
        Http::fake(['*' => Http::response('', 404)]);
        $found = $this->postJson('/apps/new/apps', ['repo' => 'acme/shop', 'branch' => 'main', 'git' => [], 'rootDir' => '', 'rootSet' => false])->assertOk()->json();
        $this->assertSame([], $found['apps'] ?? []);
        $this->postJson('/apps/new/source', ['input' => $this->input(['root_dir' => $found['dir'] ?? '', 'runtime' => ''])])->assertStatus(422)->assertJsonValidationErrors(['runtime']);
    }

    public function test_the_top_of_the_repository_is_a_choice_even_though_an_empty_folder_arrives_as_null(): void
    {
        $this->postJson('/apps/new/source', ['input' => $this->input(['root_dir' => '', 'root_chosen' => true, 'runtime' => 'php'])])->assertOk();
        $this->postJson('/apps/new/source', ['input' => $this->input(['root_dir' => null, 'root_chosen' => false, 'runtime' => 'php'])])->assertStatus(422)->assertJsonValidationErrors(['root_dir']);
    }

    public function test_nothing_is_looked_up_without_a_repository_and_a_branch(): void
    {
        $this->assertSame([], $this->postJson('/apps/new/apps', ['repo' => '', 'branch' => 'main'])->json());
        $this->assertSame([], $this->postJson('/apps/new/detected', ['repo' => 'acme/shop', 'branch' => ''])->json());
        $this->assertSame([], $this->postJson('/apps/new/branches', ['repo' => ''])->json());
    }

    // ---- roles -----------------------------------------------------------------------------------------

    public function test_creating_an_application_needs_the_create_permission(): void
    {
        $user = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $user->id, 'organization_id' => 'org-1', 'role' => 'developer', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($user);

        $this->get('/apps/new')->assertForbidden();
        $this->getJson('/apps/new/options')->assertForbidden();
        foreach (['inspect', 'branches', 'apps', 'detected', 'source', 'check', 'create'] as $path) {
            $this->postJson("/apps/new/{$path}", ['input' => $this->input()])->assertForbidden();
        }
    }

    public function test_a_team_with_only_its_own_server_opens_on_that_provider_and_can_create_an_application(): void
    {
        // The page has no provider chooser with one provider, so it must open on the provider the server resolved, not DigitalOcean.
        DB::table('cloud_credentials')->delete();
        DB::table('nodes')->delete();
        DB::table('nodes')->insert(['node_id' => 'hbnation-prod.org-1', 'provider' => 'custom', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        Queue::fake();

        $this->get('/apps/new')->assertOk()->assertInertia(fn (Assert $page) => $page->where('options.provider', 'custom')
            ->where('options.servers.0.id', 'hbnation-prod.org-1')->has('options.providers', 1));

        $own = ['provider' => 'custom', 'server' => ['mode' => 'existing', 'node' => 'hbnation-prod.org-1']];
        $this->check($own)->assertOk();
        $this->postJson('/apps/new/create', ['input' => $this->input($own)])->assertOk();
        $this->assertSame('custom', DB::table('sites')->where('name', 'shop')->value('provider'));

        // What the page used to send: DigitalOcean, which this team has not connected.
        $refused = $this->check(['provider' => 'digitalocean', 'name' => 'other', 'server' => ['mode' => 'existing', 'node' => 'hbnation-prod.org-1']])->assertStatus(422);
        $this->assertStringContainsString('pick a provider that is connected', (string) json_encode($refused->json()));
    }
}
