<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Api\ApiTokens;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Previews;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use stdClass;
use Tests\TestCase;

/**
 * A branch runs at its own URL on the preview server, can reach nothing of
 * the site it came from, and leaves nothing behind when it goes.
 */
final class PreviewsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.preview_domain' => 'preview.example.com']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        $http = new HttpFactory();
        $http->fake(function (Request $r) use ($http) {
            $this->gateway[] = ['kind' => $r['kind'], 'args' => $r['args'] ?? []];

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
        foreach (['web-1.org-1' => null, 'preview-1.org-1' => 'preview'] as $node => $role) {
            DB::table('nodes')->insert(['node_id' => $node, 'organization_id' => 'org-1', 'role' => $role, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function sites(): Sites
    {
        return $this->app->make(Sites::class);
    }

    private function parent(): stdClass
    {
        $sites = $this->sites();
        $shop = $sites->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $sites->setEnvValues($shop, [
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.internal', 'DB_PASSWORD' => 'prod-db-pass', 'REDIS_HOST' => 'cache.internal',
            'REDIS_PASSWORD' => 'prod-cache-pass', 'AWS_SECRET_ACCESS_KEY' => 'prod-bucket-secret', 'FILESYSTEM_DISK' => 's3',
            'MAIL_MAILER' => 'postmark', 'POSTMARK_TOKEN' => 'prod-mail', 'STRIPE_SECRET' => 'sk_live_x', 'FEATURE_FLAGS' => 'checkout-v2',
        ]);
        DB::table('sites')->where('id', $shop->id)->update(['preview_exclude' => json_encode(['STRIPE_SECRET']), 'preview_seeder' => 'PreviewSeeder', 'queue_workers' => 3]);

        return $sites->find('web-1.org-1', 'shop');
    }

    public function test_a_preview_runs_on_the_preview_server_with_nothing_of_productions(): void
    {
        $parent = $this->parent();
        $preview = $this->app->make(Previews::class)->create($parent, 'feature/New_Checkout', followPushes: true);

        $this->assertSame(['preview-1.org-1', 'shop-feature-new-checkout', 'feature-new-checkout.shop.preview.example.com', 'feature/New_Checkout'],
            [$preview->node_id, $preview->name, $preview->domain, $preview->branch]);
        $this->assertSame([$parent->id, 7, 1, 1], [(int) $preview->parent_site_id, (int) $preview->preview_idle_days, (int) $preview->push_to_deploy, (int) $preview->queue_workers]);
        $this->assertSame($parent->deploy_key_public, $preview->deploy_key_public, 'Same way into the repository.');

        $env = $this->sites()->env($preview);
        foreach (['prod-db-pass', 'db.internal', 'cache.internal', 'prod-cache-pass', 'prod-bucket-secret', 'prod-mail', 'sk_live_x', 'postmark'] as $secret) {
            $this->assertStringNotContainsString($secret, $env, "{$secret} must not reach a preview.");
        }
        foreach (["APP_ENV=preview\n", "DB_CONNECTION=sqlite\n", 'DB_DATABASE=/home/deploy/shop-feature-new-checkout/shared/database.sqlite', "CACHE_STORE=database\n", "FILESYSTEM_DISK=local\n", "MAIL_MAILER=log\n", "FEATURE_FLAGS=checkout-v2\n", 'APP_URL=https://feature-new-checkout.shop.preview.example.com'] as $line) {
            $this->assertStringContainsString($line, $env);
        }
        preg_match('/^APP_KEY=(.*)$/m', $env, $preKey);
        preg_match('/^APP_KEY=(.*)$/m', $this->sites()->env($parent), $parentKey);
        $this->assertNotSame($parentKey[1], $preKey[1], 'Its own app key: production cookies and encrypted data mean nothing to it.');
    }

    public function test_a_preview_tells_search_engines_to_stay_away_and_a_site_does_not(): void
    {
        $parent = $this->parent();
        $preview = $this->app->make(Previews::class)->create($parent, 'x', false);
        $vhost = fn ($site) => base64_decode(collect($this->sites()->setupRecipe($site)['steps'])->firstWhere('id', 'vhost')['content_b64'] ?? '');
        $this->assertStringContainsString('add_header X-Robots-Tag "noindex, nofollow" always;', $vhost($preview));
        $this->assertStringNotContainsString('X-Robots-Tag', $vhost($parent));
    }

    public function test_a_second_preview_of_the_same_branch_gets_its_own_name(): void
    {
        $parent = $this->parent();
        $a = $this->app->make(Previews::class)->create($parent, 'main', false);
        $b = $this->app->make(Previews::class)->create($parent, 'main', false);
        $this->assertSame(['shop-main', 'shop-main-2'], [$a->name, $b->name]);
        $this->assertNotSame($a->domain, $b->domain);
    }

    public function test_no_preview_without_a_preview_server_or_of_a_preview(): void
    {
        $parent = $this->parent();
        $preview = $this->app->make(Previews::class)->create($parent, 'x', false);
        try {
            $this->app->make(Previews::class)->create($preview, 'y', false);
            $this->fail('A preview of a preview.');
        } catch (InvalidArgumentException) {
        }
        DB::table('nodes')->update(['role' => null]);
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(Previews::class)->create($parent, 'z', false);
    }

    public function test_its_first_deploy_runs_the_parents_seeder_after_migrating(): void
    {
        $preview = $this->app->make(Previews::class)->create($this->parent(), 'x', false);
        $deployer = new Deployer($this->sites(), $this->app->make(GatewayClient::class), new AuditLog(), new GitHubApp(new HttpFactory(), $this->app->make(Vault::class), new AuditLog()));
        $seeder = (new \ReflectionMethod($deployer, 'seeder'))->invoke($deployer, $preview);
        $this->assertSame('PreviewSeeder', $seeder);
        $this->assertSame(['composer', 'migrate', 'seed', 'optimize'], array_column($deployer->buildRecipe('/r', true, $seeder)['steps'], 'id'));

        DB::table('sites')->where('id', $preview->id)->update(['current_release' => 'r1']);
        $this->assertNull((new \ReflectionMethod($deployer, 'seeder'))->invoke($deployer, $this->sites()->find('preview-1.org-1', $preview->name)), 'Once only.');
    }

    public function test_deleting_a_preview_leaves_nothing_on_the_server_or_here(): void
    {
        $preview = $this->app->make(Previews::class)->create($this->parent(), 'x', false);
        $this->sites()->setEnvValues($preview, ['A' => '1']);
        $done = $this->app->make(Previews::class)->destroy($preview);

        $this->assertCount(3, $done);
        $this->assertSame('apply_recipe', $this->gateway[0]['kind']);
        $this->assertStringContainsString('certbot delete', implode(' ', $this->gateway[1]['args']['args']));
        $this->assertFalse(DB::table('sites')->where('id', $preview->id)->exists());
        $this->assertSame(0, DB::table('site_env_versions')->where('site_id', $preview->id)->count());
        $this->assertSame([], array_filter($this->app->make(Vault::class)->names('org-1'), fn ($n) => str_contains($n, $preview->name)), 'No secret of it is left.');
    }

    public function test_a_site_is_not_deleted_as_a_preview(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->app->make(Previews::class)->destroy($this->parent());
    }

    public function test_idle_previews_are_swept_and_kept_ones_are_not(): void
    {
        $parent = $this->parent();
        $idle = $this->app->make(Previews::class)->create($parent, 'old', false, 3);
        $this->app->make(Previews::class)->create($parent, 'kept', false, null);
        $this->app->make(Previews::class)->create($parent, 'fresh', false, 3);
        DB::table('sites')->where('id', $idle->id)->update(['created_at' => now()->subDays(5)]);
        $this->travel(1)->days();

        $this->artisan('previews:sweep')->assertSuccessful();
        $ops = DB::table('operations')->where('kind', 'preview_destroy')->pluck('site_id')->all();
        $this->assertSame([$idle->id], array_map('intval', $ops));
    }

    public function test_previews_over_the_api(): void
    {
        $parent = $this->parent();
        $token = $this->app->make(ApiTokens::class)->issue('org-1', 'agent', 'deploy', 'me')['token'];

        $made = $this->withToken($token)->postJson('/api/v1/sites/shop/previews', ['branch' => 'feature/x', 'idle_days' => null])->assertStatus(202)->json();
        $this->assertSame(['shop-feature-x', 'https://feature-x.shop.preview.example.com'], [$made['preview'], $made['preview_url']]);
        $this->assertNull(DB::table('sites')->where('name', 'shop-feature-x')->value('preview_idle_days'), 'null keeps it.');
        $this->assertSame('preview_create', DB::table('operations')->find($made['operation'])->kind);

        $this->withToken($token)->getJson('/api/v1/sites/shop/previews')->assertOk()->assertJsonPath('previews.0.preview_of', 'shop');
        $this->withToken($token)->deleteJson('/api/v1/sites/shop')->assertForbidden();
        $this->withToken($token)->deleteJson('/api/v1/sites/shop-feature-x')->assertStatus(202);
    }

    private function deployer(): Deployer
    {
        return new Deployer($this->sites(), $this->app->make(GatewayClient::class), new AuditLog(), new GitHubApp(new HttpFactory(), $this->app->make(Vault::class), new AuditLog()));
    }

    private function migrates(stdClass $site): bool
    {
        $deployer = $this->deployer();

        return (new \ReflectionMethod($deployer, 'migrates'))->invoke($deployer, $site);
    }

    public function test_overrides_are_filled_in_per_preview_and_win(): void
    {
        $parent = $this->parent();
        $previews = $this->app->make(Previews::class);
        $previews->setOverrides($parent, "# OAuth for previews\nOAUTH_REDIRECT_URI={url}/auth/callback\nOAUTH_CLIENT_SECRET=preview-secret\nFEATURE_FLAGS=all\nPREVIEW_OF={site}@{branch} ({slug})\n");
        $preview = $previews->create($parent, 'feature/Login', false);
        $env = $this->sites()->env($preview);

        $this->assertStringContainsString("OAUTH_REDIRECT_URI=https://feature-login.shop.preview.example.com/auth/callback\n", $env);
        $this->assertStringContainsString("PREVIEW_OF=shop@feature/Login (feature-login)\n", $env);
        $this->assertStringContainsString("FEATURE_FLAGS=all\n", $env);
        $this->assertStringNotContainsString('checkout-v2', $env, 'An override replaces the key it names.');
        $this->assertStringNotContainsString('preview-secret', (string) json_encode(DB::table('sites')->get()), 'Overrides live in the vault.');
        $this->assertSame("OAUTH_REDIRECT_URI={url}/auth/callback\nOAUTH_CLIENT_SECRET=preview-secret\nFEATURE_FLAGS=all\nPREVIEW_OF={site}@{branch} ({slug})\n", $previews->overrides($parent));

        $this->expectException(InvalidArgumentException::class);
        $previews->setOverrides($parent, 'not a line');
    }

    public function test_a_shared_preview_uses_the_parents_database_and_never_migrates_it(): void
    {
        $parent = $this->parent();
        DB::table('sites')->where('id', $parent->id)->update(['database_cluster_id' => 42]);
        $parent = $this->sites()->find('web-1.org-1', 'shop');
        $preview = $this->app->make(Previews::class)->create($parent, 'copy-fix', false, database: 'shared');
        $env = $this->sites()->env($preview);

        $this->assertSame(['shared', 42], [$preview->preview_database, (int) $preview->database_cluster_id]);
        foreach (["DB_CONNECTION=pgsql\n", "DB_HOST=db.internal\n", "DB_PASSWORD=prod-db-pass\n"] as $line) {
            $this->assertStringContainsString($line, $env);
        }
        $this->assertStringNotContainsString('DB_CONNECTION=sqlite', $env, 'Exactly the parent\'s database settings, none of its own.');
        $this->assertStringNotContainsString('cache.internal', $env, 'The database only: still not its cache.');
        $this->assertFalse($this->migrates($preview));
        DB::table('sites')->where('id', $preview->id)->update(['current_release' => 'r1']);
        $this->assertFalse($this->migrates($this->sites()->find('preview-1.org-1', $preview->name)), 'Not on later deploys either.');
        $this->assertNull((new \ReflectionMethod($this->deployer(), 'seeder'))->invoke($this->deployer(), $preview));
        $this->assertTrue($this->migrates($parent), 'The parent itself still migrates.');
    }

    public function test_a_cloned_preview_gets_its_own_database_and_migrates_after_the_copy(): void
    {
        $parent = $this->parent();
        DB::table('sites')->where('id', $parent->id)->update(['database_cluster_id' => 42, 'preview_database' => 'clone']);
        $parent = $this->sites()->find('web-1.org-1', 'shop');
        $preview = $this->app->make(Previews::class)->create($parent, 'x', false);
        $env = $this->sites()->env($preview);

        $this->assertSame('clone', $preview->preview_database, 'The site default.');
        $this->assertNull($preview->database_cluster_id, 'Attached to the cluster as its own database when it is built.');
        $this->assertDoesNotMatchRegularExpression('/^DB_/m', $env, 'No database keys until the cluster gives it its own.');
        $this->assertFalse($this->migrates($preview), 'The first deploy leaves migrations to the clone.');
        DB::table('sites')->where('id', $preview->id)->update(['current_release' => 'r1']);
        $this->assertTrue($this->migrates($this->sites()->find('preview-1.org-1', $preview->name)));
    }

    public function test_a_sqlite_site_clones_to_sqlite_and_cannot_be_shared(): void
    {
        $sites = $this->sites();
        $blog = $sites->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');
        $preview = $this->app->make(Previews::class)->create($blog, 'x', false, database: 'clone');
        $this->assertStringContainsString("DB_CONNECTION=sqlite\nDB_DATABASE=/home/deploy/blog-x/shared/database.sqlite\n", $sites->env($preview));

        $this->expectException(InvalidArgumentException::class);
        $this->app->make(Previews::class)->create($blog, 'y', false, database: 'shared');
    }

    public function test_a_database_off_any_cluster_here_is_neither_cloned_nor_shared(): void
    {
        foreach (['clone', 'shared'] as $database) {
            try {
                $this->app->make(Previews::class)->create($this->parent(), "x-{$database}", false, database: $database);
                $this->fail("{$database} of a database the control plane does not manage.");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not on a managed cluster', $e->getMessage());
            }
            DB::table('sites')->delete();
        }
    }

    public function test_a_fresh_preview_of_a_site_on_a_cluster_gets_an_empty_database_of_the_same_kind(): void
    {
        $parent = $this->parent();
        DB::table('sites')->where('id', $parent->id)->update(['database_cluster_id' => 42]);
        $parent = $this->sites()->find('web-1.org-1', 'shop');
        $preview = $this->app->make(Previews::class)->create($parent, 'x', false);

        $this->assertSame('fresh', $preview->preview_database);
        $this->assertFalse(Previews::onSqliteFor($parent, 'fresh'), 'Made on the cluster when it is built, as the parent\'s kind.');
        $this->assertDoesNotMatchRegularExpression('/^DB_/m', $this->sites()->env($preview), 'The cluster writes its own database keys.');
        $this->assertTrue($this->migrates($preview));
        $this->assertSame('PreviewSeeder', (new \ReflectionMethod($this->deployer(), 'seeder'))->invoke($this->deployer(), $preview));

        $sqlite = $this->app->make(Previews::class)->create($parent, 'y', false, database: 'sqlite');
        $this->assertStringContainsString("DB_CONNECTION=sqlite\n", $this->sites()->env($sqlite), 'SQLite when asked, whatever the parent uses.');
    }

    public function test_only_a_previews_own_database_is_ever_dropped(): void
    {
        $clusters = $this->app->make(\App\Lib\Cloud\DatabaseClusters::class);
        $parent = $this->parent();
        DB::table('sites')->where('id', $parent->id)->update(['database_cluster_id' => 42]);
        $parent = $this->sites()->find('web-1.org-1', 'shop');
        $shared = $this->app->make(Previews::class)->create($parent, 'x', false, database: 'shared');
        foreach ([$parent, $shared] as $site) {
            try {
                $clusters->dropDatabase($site);
                $this->fail("{$site->name}'s database was dropped.");
            } catch (InvalidArgumentException) {
            }
        }
        $this->assertSame([], $clusters->dropDatabase($this->app->make(Previews::class)->create($parent, 'y', false, database: 'sqlite')), 'Nothing on a cluster to drop.');
    }
}
