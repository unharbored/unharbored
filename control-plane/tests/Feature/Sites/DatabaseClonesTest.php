<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Resources\ResourceManager;
use App\Lib\Sites\CloneConversion;
use App\Lib\Sites\DatabaseClones;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use stdClass;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A copy of an application's database from a fresh dump: a new database and
 * user beside the site's own on a managed cluster, or a file for SQLite.
 * Nothing is written to the source, the password never leaves the vault or the
 * one-off program, and a failed copy leaves nothing behind.
 */
final class DatabaseClonesTest extends TestCase
{
    use RefreshDatabase;

    private const API = 'api.digitalocean.com/v2';

    private HttpFactory $http;

    /** @var list<array{method: string, url: string, body: array<string, mixed>}> */
    private array $sent = [];

    /** @var list<string> names that already exist on the cluster */
    private array $existing = ['shop_app'];

    private bool $scriptFails = false;

    private string $scriptError = 'pg_restore exited 1: permission denied';

    private string $scriptOutput = '';

    private Sites $sites;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Cache::store('array')->clear();
        Queue::fake();
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->http = new HttpFactory();
        $this->http->fake(function (Request $r) {
            $this->sent[] = ['method' => $r->method(), 'url' => $r->url(), 'body' => $r->data()];
            $url = $r->url();
            if (str_contains($url, 'gw.test')) {
                $failing = $this->scriptFails && str_contains((string) json_encode($r->data()), 'database-clone"');

                return $failing ? $this->http->response(['status' => 'failed', 'outcome_known' => true, 'error' => $this->scriptError])
                    : $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $this->scriptOutput]]);
            }

            return match (true) {
                str_ends_with($url, '/databases') => $this->http->response(['database' => ['id' => 'db-uuid', 'status' => 'creating']], 201),
                str_ends_with($url, '/databases/db-uuid/ca') => $this->http->response(['ca' => ['certificate' => base64_encode("-----BEGIN CERTIFICATE-----\nCA\n-----END CERTIFICATE-----\n")]]),
                str_ends_with($url, '/databases/db-uuid') => $this->http->response(['database' => ['id' => 'db-uuid', 'status' => 'online', 'version' => '17', 'connection' => ['host' => 'main.db.ondigitalocean.com', 'port' => 25060, 'user' => 'doadmin', 'password' => 'admin-secret']]]),
                str_ends_with($url, '/dbs') && $r->method() === 'GET' => $this->http->response(['dbs' => array_map(fn ($n) => ['name' => $n], $this->existing)]),
                str_ends_with($url, '/dbs') => $this->http->response(['db' => ['name' => $r['name']]], 201),
                str_ends_with($url, '/users') && $r->method() === 'GET' => $this->http->response(['users' => array_map(fn ($n) => ['name' => $n], $this->existing)]),
                str_ends_with($url, '/users') => $this->http->response(['user' => ['name' => $r['name'], 'password' => 'clone-secret']], 201),
                str_contains($url, '/users/') => $this->http->response([], 204),
                str_contains($url, '/dbs/') => $this->http->response([], 204),
                str_ends_with($url, '/firewall') => $r->method() === 'GET' ? $this->http->response(['rules' => []]) : $this->http->response([], 204),
                default => $this->http->response([], 404),
            };
        });
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()));
        $this->sites = $this->app->make(Sites::class);
        $this->app->make(CredentialStore::class)->connect('org-1', 'dop_v1_test_token');
    }

    private function clones(): DatabaseClones
    {
        return $this->app->make(DatabaseClones::class);
    }

    private function say(): \Closure
    {
        return function (string $a, string $t): void {};
    }

    /** A site on a managed PostgreSQL cluster. */
    private function clusterSite(): stdClass
    {
        $droplet = $this->app->make(ResourceManager::class)->declare('org-1', 'server', 'web-1', []);
        $this->app->make(ResourceManager::class)->recordExternalId($droplet, '616267966');
        $site = $this->sites->create('org-1', 'web-1.org-1', 'shop-app', 'shop.example.com', 'git@github.com:acme/shop.git');
        $clusters = $this->app->make(DatabaseClusters::class);
        $cluster = $clusters->refresh($clusters->create('org-1', 'main', 'pg', 'nyc3', 'db-s-1vcpu-1gb'));
        $clusters->attach($site, $cluster);
        $this->sent = [];

        return DB::table('sites')->where('id', $site->id)->first();
    }

    /** The decoded files the clone recipe wrote, keyed by path. @return array<string, string> */
    private function written(): array
    {
        $out = [];
        foreach ($this->sent as $s) {
            foreach ($s['body']['args']['spec']['steps'] ?? [] as $step) {
                if (($step['type'] ?? null) === 'file' && str_contains($step['path'], 'db-clone')) {
                    $out[$step['path']] = base64_decode($step['content_b64']);
                }
            }
        }

        return $out;
    }

    public function test_a_managed_database_is_copied_into_a_new_database_with_its_own_user(): void
    {
        $site = $this->clusterSite();

        $id = $this->clones()->clone($site, 'staging_check', false, $this->say());

        $created = array_values(array_filter($this->sent, fn ($s) => $s['method'] === 'POST' && str_contains($s['url'], '/databases/db-uuid/')));
        $this->assertSame(['dbs', 'users'], array_map(fn ($s) => basename($s['url']), $created));
        $this->assertSame('staging_check', $created[0]['body']['name']);

        $script = $this->written()['/root/.cm-db-clone.php'];
        $this->assertStringContainsString("'password' => 'clone-secret'", $script);
        $this->assertStringContainsString("'schema_only' => false", $script);
        $this->assertStringContainsString('pg_dump -Fc', $script);

        $row = DB::table('database_clones')->where('id', $id)->first();
        $this->assertSame(['pgsql', 'staging_check', 'staging_check', 'main.db.ondigitalocean.com'], [$row->engine, $row->name, $row->database, $row->host]);
        $this->assertNotContains('clone-secret', array_values((array) $row), 'The password is not in the table.');
        $this->assertSame('clone-secret', $this->app->make(Vault::class)->get('org-1', "database-clone.{$id}.password"));

        $removed = array_values(array_filter($this->sent, fn ($s) => $s['method'] === 'DELETE'));
        $this->assertSame([], $removed, 'Nothing is removed from the source or the cluster.');
    }

    public function test_the_program_is_valid_php_for_each_engine_and_never_in_a_command_line(): void
    {
        foreach ([['pgsql', true], ['mysql', false], ['sqlite', false]] as [$engine, $schema]) {
            $script = DatabaseClones::script(['engine' => $engine, 'host' => 'h', 'port' => 25060, 'database' => 'copy_1', 'username' => 'copy_1', 'password' => "pa'ss\\word", 'path' => '/srv/x/shared/database-copy_1.sqlite', 'schema_only' => $schema]);
            $file = tempnam(sys_get_temp_dir(), 'clone').'.php';
            file_put_contents($file, $script);
            exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
            @unlink($file);
            $this->assertSame(0, $code, "{$engine}: ".implode("\n", $out));
            $out = [];
        }
        $this->assertStringContainsString("'pa\\'ss\\\\word'", $script, 'The password is quoted safely.');
        $this->assertStringContainsString('--schema-only', DatabaseClones::script(['schema_only' => true]));
    }

    public function test_schema_only_is_passed_to_the_dump(): void
    {
        $site = $this->clusterSite();
        $this->clones()->clone($site, 'shape_only', true, $this->say());

        $this->assertStringContainsString("'schema_only' => true", $this->written()['/root/.cm-db-clone.php']);
    }

    public function test_the_program_is_removed_after_the_run_whatever_happens(): void
    {
        $site = $this->clusterSite();
        $this->clones()->clone($site, 'staging_check', false, $this->say());

        $recipes = array_values(array_filter($this->sent, fn ($s) => str_contains($s['url'], 'gw.test') && isset($s['body']['args']['spec'])));
        $last = end($recipes)['body']['args']['spec'];
        $this->assertSame(['database-clone-cleanup'], [$last['name']]);
        $this->assertSame('absent', $last['steps'][0]['type']);
        $this->assertSame('/root/.cm-db-clone.php', $last['steps'][0]['path']);
    }

    public function test_a_name_already_on_the_cluster_is_refused_before_anything_is_made(): void
    {
        $site = $this->clusterSite();
        $this->existing = ['shop_app', 'staging_check'];

        try {
            $this->clones()->clone($site, 'staging_check', false, $this->say());
            $this->fail('refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already has a database or user called staging_check', $e->getMessage());
        }
        $this->assertSame([], array_values(array_filter($this->sent, fn ($s) => $s['method'] === 'POST')));
        $this->assertSame(0, DB::table('database_clones')->count());
    }

    public function test_a_failed_copy_removes_what_it_made_and_leaves_no_clone(): void
    {
        $site = $this->clusterSite();
        $this->scriptFails = true;

        try {
            $this->clones()->clone($site, 'staging_check', false, $this->say());
            $this->fail('failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('pg_restore exited 1', $e->getMessage());
        }
        $deleted = array_map(fn ($s) => substr($s['url'], strpos($s['url'], '/databases/')), array_filter($this->sent, fn ($s) => $s['method'] === 'DELETE'));
        $this->assertContains('/databases/db-uuid/dbs/staging_check', $deleted);
        $this->assertContains('/databases/db-uuid/users/staging_check', $deleted);
        $this->assertSame(0, DB::table('database_clones')->count());
    }

    public function test_the_connection_is_revealed_as_env_lines_and_audited(): void
    {
        $site = $this->clusterSite();
        $id = $this->clones()->clone($site, 'staging_check', false, $this->say());

        $lines = $this->clones()->reveal($site, $id, Actor::user('1', 'matt@example.com'));

        $this->assertSame(['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'main.db.ondigitalocean.com', 'DB_PORT' => '25060', 'DB_DATABASE' => 'staging_check',
            'DB_USERNAME' => 'staging_check', 'DB_PASSWORD' => 'clone-secret', 'DB_SSLMODE' => 'require'], $lines);
        $this->assertTrue(DB::table('audit_records')->where('action', 'site.database_clone_revealed')->exists());
        $this->assertStringNotContainsString('clone-secret', (string) json_encode(DB::table('audit_records')->get()));
    }

    public function test_deleting_a_copy_drops_it_from_the_cluster_and_the_vault(): void
    {
        $site = $this->clusterSite();
        $id = $this->clones()->clone($site, 'staging_check', false, $this->say());

        $this->clones()->remove($site, $id);

        $this->assertSame(0, DB::table('database_clones')->count());
        $this->assertNull($this->app->make(Vault::class)->get('org-1', "database-clone.{$id}.password"));
        $this->assertNotEmpty(array_filter($this->sent, fn ($s) => $s['method'] === 'DELETE' && str_ends_with($s['url'], '/dbs/staging_check')));
    }

    public function test_bad_and_repeated_names_are_refused(): void
    {
        $site = $this->clusterSite();
        foreach (['Staging', '1abc', 'a', 'has-dash', 'x; drop'] as $bad) {
            try {
                $this->clones()->clone($site, $bad, false, $this->say());
                $this->fail("{$bad} should be refused");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->clones()->clone($site, 'copy_one', false, $this->say());
        $this->expectException(InvalidArgumentException::class);
        $this->clones()->clone($site, 'copy_one', false, $this->say());
    }

    public function test_sqlite_is_copied_to_a_file_and_cannot_be_schema_only(): void
    {
        $site = $this->sites->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');

        try {
            $this->clones()->clone($site, 'copy_one', true, $this->say());
            $this->fail('refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('whole file', $e->getMessage());
        }

        $id = $this->clones()->clone($site, 'copy_one', false, $this->say());
        $row = DB::table('database_clones')->where('id', $id)->first();
        $this->assertSame(['sqlite', '/home/deploy/blog/shared/database-copy_one.sqlite'], [$row->engine, $row->path]);
        $this->assertStringContainsString('VACUUM INTO', $this->written()['/root/.cm-db-clone.php']);
        $this->assertSame(['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => '/home/deploy/blog/shared/database-copy_one.sqlite'], $this->clones()->reveal($site, $id, Actor::user('1', 'm@e.com')));
    }

    public function test_a_database_that_is_not_ours_to_copy_is_refused(): void
    {
        $site = $this->sites->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');
        $this->sites->setInjected($site, ['DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'db.example.com', 'DB_DATABASE' => 'blog'], 'external');

        $this->expectExceptionMessage('not on a managed cluster');
        $this->clones()->clone(DB::table('sites')->where('id', $site->id)->first(), 'copy_one', false, $this->say());
    }

    public function test_the_console_queues_the_copy_reveals_it_and_deletes_it(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->clusterSite();
        $url = "/site/settings/{$site->id}";
        $page = fn () => $this->get("/site/settings?site={$site->id}&tab=database");

        $this->post("{$url}/clones", ['name' => 'Bad Name'])->assertSessionHasErrors('clone');
        $this->assertSame(0, DB::table('operations')->where('kind', 'database_clone')->count());

        $this->post("{$url}/clones", ['name' => 'staging_check', 'schemaOnly' => true])->assertRedirect();
        $op = DB::table('operations')->where('kind', 'database_clone')->first();
        $this->assertSame(['name' => 'staging_check', 'schema_only' => true], json_decode((string) $op->meta, true));

        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run((int) $op->id));
        $id = (int) DB::table('database_clones')->value('id');
        $page()->assertInertia(fn (Assert $p) => $p->where('data.clones.0.name', 'staging_check'));
        $this->postJson("{$url}/clones/{$id}/reveal")->assertOk()->assertJsonPath('lines.DB_PASSWORD', 'clone-secret');
        $this->post("{$url}/clones/{$id}/drop");
        $this->assertSame(0, DB::table('database_clones')->count());
    }

    public function test_conversion_queries_run_in_order_on_the_new_database_only_after_the_restore(): void
    {
        $site = $this->clusterSite();
        $this->scriptOutput = "statement 1: 12 rows affected\nstatement 2: 0 rows affected\n";
        $lines = [];
        $this->clones()->clone($site, 'staging_check', false, function (string $a, string $t) use (&$lines) {
            $lines[] = "{$a}: {$t}";
        }, "UPDATE users SET password = '{{bcrypt:secret}}'; -- scrub; really\nUPDATE users SET email = 'user' || id || '@example.test';");

        $script = $this->written()['/root/.cm-db-clone.php'];
        $this->assertStringContainsString("UPDATE users SET password = \\'$2y$", $script);
        $this->assertLessThan(strpos($script, "'UPDATE users SET email"), strpos($script, 'UPDATE users SET password'), 'In order.');
        $this->assertStringNotContainsString('{{bcrypt', $script);
        // After the restore, as the copy's own user, to the copy's database; the source's variables are not used for it.
        $this->assertGreaterThan(strpos($script, 'pg_restore'), strpos($script, '$convert(new PDO('));
        $this->assertStringContainsString("dbname='.\$t['database']", $script);
        $this->assertStringContainsString("\$t['username'], \$t['password']))", $script);
        $this->assertContains('convert: statement 1: 12 rows affected', $lines);
        foreach ($lines as $l) {
            $this->assertStringNotContainsString('UPDATE', $l);
            $this->assertStringNotContainsString('$2y$', $l);
        }
        $audit = DB::table('audit_records')->where('action', 'site.database_cloned')->first();
        $this->assertStringContainsString('"conversion_statements":2', (string) $audit->metadata);
        $this->assertStringContainsString(CloneConversion::hash("UPDATE users SET password = '{{bcrypt:secret}}'; -- scrub; really\nUPDATE users SET email = 'user' || id || '@example.test';"), (string) $audit->metadata);
        $this->assertStringNotContainsString('UPDATE', (string) $audit->metadata);
        $this->assertStringNotContainsString('$2y$', (string) $audit->metadata);
    }

    public function test_a_clone_without_conversion_has_no_statements_and_no_audit_fields(): void
    {
        $site = $this->clusterSite();
        $this->clones()->clone($site, 'plain_copy', false, $this->say());

        $this->assertStringContainsString("'statements' => \n", str_replace("'statements' => \n  array (\n)", "'statements' => \n", $this->written()['/root/.cm-db-clone.php']));
        $this->assertStringNotContainsString('conversion', (string) DB::table('audit_records')->where('action', 'site.database_cloned')->value('metadata'));
    }

    public function test_a_failing_statement_drops_the_copy_and_reports_its_number_without_the_hash(): void
    {
        $site = $this->clusterSite();
        $this->scriptFails = true;
        $this->scriptError = 'conversion statement 2 of 3 failed: relation "nope" does not exist '.Hash::make('x');

        try {
            $this->clones()->clone($site, 'staging_check', false, $this->say(), "UPDATE users SET a = 1;\nUPDATE nope SET a = 1;\nUPDATE users SET b = 2;");
            $this->fail('failed');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('conversion statement 2 of 3 failed', $e->getMessage());
            $this->assertStringNotContainsString('$2y$', $e->getMessage());
        }
        $deleted = array_map(fn ($s) => substr($s['url'], strpos($s['url'], '/databases/')), array_filter($this->sent, fn ($s) => $s['method'] === 'DELETE'));
        $this->assertContains('/databases/db-uuid/dbs/staging_check', $deleted);
        $this->assertSame(0, DB::table('database_clones')->count());
    }

    public function test_a_forbidden_statement_is_refused_before_anything_is_made(): void
    {
        $site = $this->clusterSite();

        try {
            $this->clones()->clone($site, 'staging_check', false, $this->say(), "UPDATE users SET a = 1;\nCOPY users TO PROGRAM 'sh';");
            $this->fail('refused');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Statement 2 is not allowed', $e->getMessage());
        }
        $this->assertSame([], array_values(array_filter($this->sent, fn ($s) => $s['method'] === 'POST')));
    }

    public function test_a_failed_sqlite_copy_removes_its_file(): void
    {
        $site = $this->sites->create('org-1', 'web-1.org-1', 'lite-app', 'lite.example.com', 'git@github.com:acme/lite.git');
        $this->scriptFails = true;

        try {
            $this->clones()->clone(DB::table('sites')->where('id', $site->id)->first(), 'lite_copy', false, $this->say(), 'UPDATE users SET a = 1;');
            $this->fail('failed');
        } catch (\RuntimeException) {
        }
        $last = end($this->sent)['body']['args']['spec'];
        $this->assertSame('database-clone-discard', $last['name']);
        $this->assertStringEndsWith('/shared/database-lite_copy.sqlite', $last['steps'][0]['path']);
    }

    public function test_the_conversion_program_is_valid_php_for_each_engine(): void
    {
        foreach (['pgsql', 'mysql', 'sqlite'] as $engine) {
            $script = DatabaseClones::script(['engine' => $engine, 'host' => 'h', 'port' => 1, 'database' => 'c', 'username' => 'c', 'password' => 'p', 'path' => '/x', 'schema_only' => false, 'statements' => ["UPDATE t SET a = 'it''s';", 'SELECT 1']]);
            $file = tempnam(sys_get_temp_dir(), 'clone').'.php';
            file_put_contents($file, $script);
            exec('php -l '.escapeshellarg($file).' 2>&1', $out, $code);
            @unlink($file);
            $this->assertSame(0, $code, "{$engine}: ".implode("\n", $out));
            $out = [];
        }
    }

    public function test_the_saved_conversion_fills_the_next_clone_and_the_gate_is_the_clones(): void
    {
        $user = User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']);
        $this->actingAs($user);
        $site = $this->clusterSite();
        $url = "/site/settings/{$site->id}";
        $page = fn () => $this->get("/site/settings?site={$site->id}&tab=database");

        $page()->assertInertia(fn (Assert $p) => $p->where('data.cloneSql', ''));
        $this->post("{$url}/clones/sql", ['sql' => 'DROP DATABASE x;'])->assertSessionHasErrors('clone');
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('clone_conversion'));

        $sql = "UPDATE users SET password = '{{bcrypt:secret}}';";
        $this->post("{$url}/clones/sql", ['sql' => $sql]);
        $this->assertSame($sql, DB::table('sites')->where('id', $site->id)->value('clone_conversion'));
        $page()->assertInertia(fn (Assert $p) => $p->where('data.cloneSql', $sql)->where('data.cloneExamples.1.label', 'Blank all emails')->has('data.cloneExamples.2.sql'));

        $this->post("{$url}/clones", ['name' => 'staging_check', 'sql' => $sql]);
        $op = DB::table('operations')->where('kind', 'database_clone')->first();
        $this->assertSame($sql, json_decode((string) $op->meta, true)['conversion']);
        $this->assertStringNotContainsString('$2y$', (string) $op->meta);

        $this->post("{$url}/clones", ['name' => 'other_copy', 'sql' => "COPY a TO '/tmp/x';"])->assertSessionHasErrors('clone');
        $this->assertSame(1, DB::table('operations')->where('kind', 'database_clone')->count());

        $this->post("{$url}/clones/sql", ['sql' => '']);
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('clone_conversion'));
    }
}
