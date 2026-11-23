<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Api\ApiTokens;
use App\Lib\Audit\AuditLog;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A site's environment has two layers. What cloud-manager injects from the
 * resources a site uses is one; the user's own variables are the other, win
 * over it, and are never rewritten by it.
 */
final class EnvLayersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog());
    }

    private function site(): object
    {
        return $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
    }

    /** A site as it was before the layers: everything in one blob, nothing injected. */
    private function legacySite(string $blob, array $columns = []): object
    {
        $site = $this->site();
        $vault = $this->app->make(Vault::class);
        $vault->put('org-1', 'site:web-1.org-1:shop:env', $blob);
        $vault->forget('org-1', 'site:web-1.org-1:shop:env:injected');
        if ($columns !== []) {
            DB::table('sites')->where('id', $site->id)->update($columns);
        }

        return DB::table('sites')->where('id', $site->id)->first();
    }

    private function parse(string $env): array
    {
        return Sites::envKeys($env);
    }

    public function test_a_new_sites_database_defaults_are_injected_and_not_in_its_own_variables(): void
    {
        $sites = $this->sites();
        $site = $this->site();

        $this->assertSame('', $sites->customEnv($site), 'Their own variables start empty: nothing is typed for them.');
        $this->assertStringStartsWith('base64:', $sites->injected($site)['APP_KEY']['value'], 'The key is made with the application, and injected.');
        $this->assertSame('Made with the application', $sites->injected($site)['APP_KEY']['source']);
        $this->assertSame(['APP_NAME' => 'shop'], array_intersect_key(array_map(fn ($e) => $e['value'], $sites->injected($site)), ['APP_NAME' => 1]));
        $this->assertStringContainsString("APP_KEY=base64:", $sites->env($site), 'The server still gets it.');
        $this->assertSame('sqlite', $sites->injected($site)['DB_CONNECTION']['value']);
        $this->assertSame('Default: SQLite on the server', $sites->injected($site)['DB_CONNECTION']['source']);
        $this->assertStringContainsString("DB_CONNECTION=sqlite\n", $sites->env($site), 'The server still gets them.');
    }

    public function test_the_server_gets_both_layers_and_a_custom_value_wins_once(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $sites->setEnvValues($site, ['DB_CONNECTION' => 'mysql', 'MAIL_HOST' => 'smtp.example.com']);

        $env = $this->parse($sites->env($site));
        $this->assertSame('mysql', $env['DB_CONNECTION']);
        $this->assertSame(1, preg_match_all('/^DB_CONNECTION=/m', $sites->env($site)), 'Once, not twice.');
        $this->assertSame('database', $env['CACHE_STORE'], 'The rest of the injected layer shows through.');
        $this->assertSame(['DB_CONNECTION'], $sites->overriddenKeys($site));
    }

    public function test_injecting_never_touches_the_users_variables_so_an_override_survives_a_rotation(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $sites->setEnvValues($site, ['DB_HOST' => 'replica.internal']);
        $custom = $sites->customEnv($site);

        $sites->setInjected($site, ['DB_HOST' => 'primary.example.com', 'DB_PASSWORD' => 'one'], 'Database cluster cm-main');
        $sites->setInjected($site, ['DB_PASSWORD' => 'two'], 'Database cluster cm-main');

        $this->assertSame($custom, $sites->customEnv($site), 'cloud-manager did not write the user\'s layer.');
        $env = $this->parse($sites->env($site));
        $this->assertSame('replica.internal', $env['DB_HOST'], 'The user\'s value still wins.');
        $this->assertSame('two', $env['DB_PASSWORD'], 'The rotation reached the injected layer.');
        $this->assertSame('primary.example.com', $sites->injected($site)['DB_HOST']['value']);
    }

    public function test_setting_the_same_injected_value_is_not_a_change(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $sites->setInjected($site, ['REDIS_HOST' => 'tls://cache'], 'Cache cluster cm-cache');
        $before = DB::table('site_env_versions')->count();
        DB::table('sites')->where('id', $site->id)->update(['pending_since' => null]);

        $sites->setInjected($site, ['REDIS_HOST' => 'tls://cache'], 'Cache cluster cm-cache');

        $this->assertSame($before, DB::table('site_env_versions')->count());
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('pending_since'));
    }

    public function test_a_null_removes_an_injected_value(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $sites->setInjected($site, ['DB_SSLMODE' => 'require'], 'Database cluster cm-main');
        $sites->setInjected($site, ['DB_SSLMODE' => null], 'Database cluster cm-main');

        $this->assertArrayNotHasKey('DB_SSLMODE', $sites->injected($site));
        $this->assertStringNotContainsString('DB_SSLMODE', $sites->env($site));
    }

    public function test_letting_go_of_a_connection_hands_it_to_the_user(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $sites->setInjected($site, ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'DB_PASSWORD' => 'pw'], 'Database cluster cm-main');
        $sites->setEnvValues($site, ['DB_HOST' => 'mine.example.com']);

        $sites->releaseInjected($site, Sites::INJECTABLE['database']);

        $this->assertArrayNotHasKey('DB_CONNECTION', $sites->injected($site));
        $custom = $this->parse($sites->customEnv($site));
        $this->assertSame('pgsql', $custom['DB_CONNECTION'], 'Moved to the user, so the app keeps its connection.');
        $this->assertSame('pw', $custom['DB_PASSWORD']);
        $this->assertSame('mine.example.com', $custom['DB_HOST'], 'What the user had set is left as theirs.');
        $this->assertSame([], $sites->overriddenKeys($site));
    }

    public function test_history_says_which_layer_changed_and_only_the_users_versions_restore(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $sites->setEnvValues($site, ['MAIL_HOST' => 'smtp.example.com']);
        $sites->setInjected($site, ['REDIS_PASSWORD' => 'secret-redis'], 'Cache cluster cm-cache');

        $versions = $sites->envVersions($site);
        $this->assertSame(['injected', 'custom'], array_column($versions, 'layer'), 'Newest first; there was nothing before the first change to keep.');
        $this->assertSame(['REDIS_PASSWORD'], $versions[0]['changes']['added']);
        $this->assertStringNotContainsString('secret-redis', json_encode($versions).DB::table('site_env_versions')->pluck('changes')->implode(''));

        $this->expectException(InvalidArgumentException::class);
        $sites->restoreEnvVersion($site, $versions[0]['version']);
    }

    public function test_restoring_an_earlier_custom_version_leaves_the_injected_layer_alone(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $sites->setEnvValues($site, ['MAIL_HOST' => 'one']);
        $sites->setInjected($site, ['REDIS_HOST' => 'tls://cache'], 'Cache cluster cm-cache');
        $sites->setEnvValues($site, ['MAIL_HOST' => 'two']);
        $first = collect($sites->envVersions($site))->where('layer', 'custom')->last()['version'];

        $sites->restoreEnvVersion($site, $first);

        $this->assertSame('one', $this->parse($sites->customEnv($site))['MAIL_HOST'], 'The first version had MAIL_HOST=one.');
        $this->assertSame('tls://cache', $sites->injected($site)['REDIS_HOST']['value']);
    }

    public function test_an_old_site_is_split_once_and_its_environment_does_not_change(): void
    {
        $blob = "APP_NAME=shop\nAPP_KEY=base64:abc\n\nDB_CONNECTION=sqlite\nDB_DATABASE=/home/deploy/shop/shared/database.sqlite\n\nSESSION_DRIVER=database\nCACHE_STORE=database\nQUEUE_CONNECTION=database\nMAIL_HOST=smtp.example.com\n";
        $site = $this->legacySite($blob);
        $sites = $this->sites();
        $this->assertSame([], $sites->injected($site));

        $moved = $sites->splitEnvLayers($site);

        $this->assertEqualsCanonicalizing(['DB_CONNECTION', 'DB_DATABASE', 'SESSION_DRIVER', 'CACHE_STORE', 'QUEUE_CONNECTION'], $moved['injected']);
        $this->assertEqualsCanonicalizing(['APP_NAME', 'APP_KEY', 'MAIL_HOST'], $moved['custom']);
        $this->assertEquals($this->parse($blob), $this->parse($sites->env($site)), 'The server gets the same variables, so no deploy is needed.');
        $this->assertNull($sites->splitEnvLayers($site), 'Once.');
        $this->assertSame('split into injected and custom variables', $sites->envVersions($site)[0]['reason']);
    }

    public function test_the_split_keeps_what_the_user_changed_and_follows_what_the_site_uses(): void
    {
        DB::table('resources')->insert(['id' => 7, 'organization_id' => 'org-1', 'type' => 'database', 'slug' => 'cm-main', 'external_id' => 'abc', 'state' => 'active',
            'desired_state' => 'active', 'attributes' => json_encode(['host' => 'db.example.com', 'port' => 25060, 'engine' => 'pg']), 'created_at' => now(), 'updated_at' => now()]);
        $blob = "APP_NAME=shop\nDB_CONNECTION=pgsql\nDB_HOST=replica.internal\nDB_PORT=25060\nDB_DATABASE=shop\nDB_USERNAME=shop\nDB_PASSWORD=pw\nDB_SSLMODE=require\nAWS_BUCKET=my-own-bucket\nAWS_ACCESS_KEY_ID=mine\n";
        $site = $this->legacySite($blob, ['database_cluster_id' => 7]);
        $sites = $this->sites();

        $sites->splitEnvLayers($site);

        $injected = $sites->injected($site);
        $this->assertSame('Database cluster cm-main', $injected['DB_PASSWORD']['source']);
        $this->assertArrayNotHasKey('DB_HOST', $injected, 'The user pointed it somewhere else, so it stays theirs.');
        $this->assertSame('replica.internal', $this->parse($sites->customEnv($site))['DB_HOST']);
        $this->assertSame([], $sites->overriddenKeys($site), 'A custom value with no injected twin overrides nothing.');
        $this->assertArrayNotHasKey('AWS_BUCKET', $injected, 'No bucket here, so storage settings are the user\'s own.');
        $this->assertEquals($this->parse($blob), $this->parse($sites->env($site)));
    }

    public function test_the_data_migration_splits_every_existing_site(): void
    {
        $site = $this->legacySite("APP_NAME=shop\nDB_CONNECTION=sqlite\nDB_DATABASE=/home/deploy/shop/shared/database.sqlite\nSESSION_DRIVER=database\n");
        $this->assertSame([], $this->sites()->injected($site));

        (require database_path('migrations/2026_11_10_010100_split_site_env_layers.php'))->up();

        $this->assertSame('sqlite', $this->sites()->injected($site)['DB_CONNECTION']['value']);
        $this->assertStringNotContainsString('DB_CONNECTION', $this->sites()->customEnv($site));
        (require database_path('migrations/2026_11_10_010100_split_site_env_layers.php'))->up();
        $this->assertCount(2, array_filter($this->sites()->envVersions($site), fn ($v) => $v['layer'] === 'custom'), 'Running it again changes nothing.');
    }

    public function test_injecting_into_an_unsplit_site_splits_it_first_so_old_copies_cannot_override(): void
    {
        $site = $this->legacySite("APP_NAME=shop\nDB_CONNECTION=sqlite\nDB_DATABASE=/home/deploy/shop/shared/database.sqlite\nCACHE_STORE=database\n");
        $sites = $this->sites();

        $sites->setInjected($site, ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com'], 'Database cluster cm-main');

        $this->assertSame('pgsql', $this->parse($sites->env($site))['DB_CONNECTION'], 'The old sqlite copy did not win.');
        $this->assertArrayNotHasKey('DB_CONNECTION', $this->parse($sites->customEnv($site)));
    }

    public function test_deleting_a_site_forgets_both_layers(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $vault = $this->app->make(Vault::class);
        $this->assertTrue($vault->has('org-1', 'site:web-1.org-1:shop:env:injected'));

        $sites->forget($site);

        $this->assertFalse($vault->has('org-1', 'site:web-1.org-1:shop:env'));
        $this->assertFalse($vault->has('org-1', 'site:web-1.org-1:shop:env:injected'));
    }

    // ------------------------------------------------------------------- API

    public function test_the_api_says_what_is_injected_and_overridden_and_edits_only_the_users_layer(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        $sites->setEnvValues($site, ['DB_CONNECTION' => 'mysql']);
        $token = $this->app->make(ApiTokens::class)->issue('org-1', 'manage', 'manage', 'me')['token'];

        $show = $this->withToken($token)->getJson('/api/v1/sites/shop/env')->assertOk();
        $this->assertContains('CACHE_STORE', $show->json('injected'));
        $this->assertSame(['DB_CONNECTION'], $show->json('overridden'));
        $this->assertContains('CACHE_STORE', $show->json('keys'));

        $reveal = $this->withToken($token)->getJson('/api/v1/sites/shop/env?reveal=1')->assertOk();
        $this->assertStringContainsString('CACHE_STORE=database', $reveal->json('contents'));
        $this->assertStringNotContainsString('CACHE_STORE', $reveal->json('custom'), 'custom is only the part you can change.');

        $this->withToken($token)->patchJson('/api/v1/sites/shop/env', ['values' => ['CACHE_STORE' => 'redis']])->assertOk();
        $this->assertSame('redis', $this->parse($sites->env($site))['CACHE_STORE'], 'A value overrides the injected one.');
        $this->assertSame('database', $sites->injected($site)['CACHE_STORE']['value']);

        $this->withToken($token)->patchJson('/api/v1/sites/shop/env', ['values' => ['SESSION_DRIVER' => null]])->assertStatus(422);
        $this->withToken($token)->patchJson('/api/v1/sites/shop/env', ['values' => ['CACHE_STORE' => null]])->assertOk();
        $this->assertSame('database', $this->parse($sites->env($site))['CACHE_STORE'], 'Removing the override shows the injected value again.');
    }

    // --------------------------------------------------------------- console

    public function test_the_variables_page_lists_injected_values_flags_overrides_and_audits_revealing(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $sites = $this->sites();
        $site = $this->site();
        $sites->setInjected($site, ['DB_CONNECTION' => 'pgsql', 'DB_PASSWORD' => 'pw-secret'], 'Database cluster cm-main');
        $sites->setEnvValues($site, ['DB_CONNECTION' => 'mysql']);

        $page = $this->get("/site/settings?site={$site->id}");
        $page->assertInertia(fn (Assert $p) => $p->component('Site/Settings')
            ->where('data.overridden', ['DB_CONNECTION'])
            ->where('data.injected', fn ($rows) => collect($rows)->contains(fn ($r) => $r['key'] === 'DB_CONNECTION' && $r['overridden'] === true && $r['source'] === 'Database cluster cm-main')
                && collect($rows)->contains(fn ($r) => $r['source'] === 'Default: SQLite on the server')));
        $this->assertStringNotContainsString('pw-secret', $page->getContent());

        $this->postJson("/site/settings/{$site->id}/env/injected")->assertOk()->assertJsonPath('values.DB_PASSWORD', 'pw-secret');
        $this->assertTrue(DB::table('audit_records')->where('action', 'site.env_read')->exists(), 'Revealing is audited.');
    }

    public function test_the_editor_holds_only_the_users_variables_and_an_override_can_be_removed(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $sites = $this->sites();
        $site = $this->site();
        $sites->setEnvValues($site, ['CACHE_STORE' => 'redis']);

        $text = $this->postJson("/site/settings/{$site->id}/env/reveal")->assertOk()->json('text');
        $this->assertStringContainsString('CACHE_STORE=redis', $text);
        $this->assertStringNotContainsString('SESSION_DRIVER', $text, 'Injected values are not in the editor, so saving cannot freeze them.');

        $this->post("/site/settings/{$site->id}/env/override/remove", ['key' => 'CACHE_STORE']);
        $this->assertSame('database', $this->parse($sites->env($site))['CACHE_STORE']);
        $this->assertArrayNotHasKey('CACHE_STORE', $this->parse($sites->customEnv($site)));
    }

    public function test_an_application_from_before_has_what_was_made_for_it_moved_out_of_its_own_variables_and_nothing_else(): void
    {
        $sites = $this->sites();
        $site = $this->site();
        // As it used to be: the generated values sat in the user's variables, and were not injected.
        $sites->setInjected($site, array_fill_keys(['APP_NAME', 'APP_ENV', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'LOG_CHANNEL', 'LOG_STACK', 'LOG_LEVEL'], null), 'removed to set up the old shape');
        $sites->setEnv($site, "APP_NAME=shop\nAPP_ENV=staging\nAPP_KEY=base64:oldkeyoldkeyoldkey\nAPP_DEBUG=false\nAPP_URL=http://shop.example.com\n\nLOG_CHANNEL=stack\nLOG_STACK=single\nLOG_LEVEL=debug\nMAIL_HOST=smtp.example.com\n");
        $before = $this->parse($sites->env($site));

        $moved = $sites->moveGeneratedDefaults($site);

        $this->assertEqualsCanonicalizing(['APP_NAME', 'APP_KEY', 'APP_DEBUG', 'APP_URL', 'LOG_CHANNEL', 'LOG_STACK'], $moved);
        $custom = $this->parse($sites->customEnv($site));
        $this->assertSame(['APP_ENV' => 'staging', 'LOG_LEVEL' => 'debug', 'MAIL_HOST' => 'smtp.example.com'], $custom, 'What differs from what was made, and what they added, stays theirs.');
        $this->assertSame('base64:oldkeyoldkeyoldkey', $sites->injected($site)['APP_KEY']['value'], 'The key they have is kept.');
        $this->assertSame('Made with the application', $sites->injected($site)['APP_NAME']['source']);
        $this->assertEqualsCanonicalizing($before, array_intersect_key($this->parse($sites->env($site)), $before) ?: [], 'The server is given exactly what it was.');
        $this->assertSame($before['APP_ENV'], $this->parse($sites->env($site))['APP_ENV']);

        $this->assertSame([], $sites->moveGeneratedDefaults($site), 'Once.');
    }
}
