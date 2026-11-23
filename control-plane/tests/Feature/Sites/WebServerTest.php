<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A site's own nginx settings: the upload limit and request timeout, and a
 * snippet inside its server block. Written to every server it runs on, checked
 * by nginx, and put back if nginx refuses.
 */
final class WebServerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    private ?string $failure = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('nodes')->insert([
            ['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()],
            ['node_id' => 'web-2.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()],
        ]);

        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            preg_match('#/v1/nodes/([^/]+)/commands#', $r->url(), $m);
            $this->gateway[] = ['kind' => $r['kind'] ?? null, 'node' => $m[1] ?? null, 'spec' => $r['args']['spec'] ?? null];

            return $this->failure !== null && ($r['kind'] ?? null) === 'apply_recipe'
                ? $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => $this->failure])
                : $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
    }

    private function sites(): Sites
    {
        return $this->app->make(Sites::class);
    }

    private function site(): object
    {
        return $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
    }

    private function vhost(object $site): string
    {
        return $this->sites()->generatedVhost($this->sites()->find($site->node_id, $site->name));
    }

    public function test_each_site_has_its_own_access_and_error_log(): void
    {
        $site = $this->site();

        $this->assertStringContainsString("access_log /var/log/nginx/{$site->name}.access.log;", $this->vhost($site));
        $this->assertStringContainsString("error_log /var/log/nginx/{$site->name}.error.log;", $this->vhost($site));
    }

    public function test_a_site_gets_the_platforms_defaults_until_it_sets_its_own(): void
    {
        $site = $this->site();

        $this->assertStringContainsString('client_max_body_size 64m;', $this->vhost($site));
        $this->assertStringContainsString('fastcgi_read_timeout 60s;', $this->vhost($site));
        $this->assertStringNotContainsString('your snippet', $this->vhost($site));
        $this->assertSame(['upload_mb' => false, 'timeout_s' => false], $this->sites()->nginx($site)['custom']);
    }

    public function test_its_own_settings_and_snippet_go_into_its_app_server_block_only(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['tls' => true]);
        $this->sites()->setNginx($this->sites()->find('web-1.org-1', 'shop'), 200, 300, "location = /health {\n    return 200 \"ok\";\n}");

        $vhost = $this->vhost($site);

        $this->assertStringContainsString('client_max_body_size 200m;', $vhost);
        $this->assertStringContainsString('fastcgi_read_timeout 300s;', $vhost);
        $this->assertStringContainsString("    # ---- your snippet ----\n    location = /health {\n        return 200 \"ok\";\n    }\n    # ---- end of your snippet ----", $vhost);
        $this->assertSame(1, substr_count($vhost, '# ---- your snippet ----'), 'In the app server, not the port 80 redirect.');
        $this->assertTrue(strpos($vhost, 'your snippet') > strpos($vhost, 'listen 443'), 'After the TLS server\'s listen lines.');
    }

    public function test_putting_a_value_back_to_null_returns_to_the_default(): void
    {
        $site = $this->site();
        $this->sites()->setNginx($site, 200, 300, '# a comment');
        $this->sites()->setNginx($this->sites()->find('web-1.org-1', 'shop'), null, null, '');

        $this->assertStringContainsString('client_max_body_size 64m;', $this->vhost($site));
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('nginx_snippet'));
    }

    /** @return array<string, array{0: string}> */
    public static function badSnippets(): array
    {
        return [
            'an include' => ["include /etc/passwd;"],
            'a listen' => ["listen 8080;"],
            'a server_name' => ["server_name evil.example.com;"],
            'a certificate' => ["ssl_certificate /etc/x.pem;"],
            'upper case' => ["INCLUDE /etc/passwd;"],
            'one after a brace' => ["location / { include /etc/passwd; }"],
            'closing the server block' => ["}\nserver {\n    server_name evil.example.com;"],
            'an unclosed brace' => ["location / {\n    return 200;"],
            'too long' => [str_repeat('a', 10001)],
        ];
    }

    /**
     * @dataProvider badSnippets
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('badSnippets')]
    public function test_a_snippet_cannot_take_over_what_the_platform_owns(string $snippet): void
    {
        $site = $this->site();

        $this->expectException(InvalidArgumentException::class);
        try {
            $this->sites()->setNginx($site, null, null, $snippet);
        } finally {
            $this->assertNull(DB::table('sites')->where('id', $site->id)->value('nginx_snippet'), 'Nothing was saved.');
        }
    }

    public function test_a_snippet_may_mention_those_words_in_comments_and_strings(): void
    {
        $site = $this->site();
        $this->sites()->setNginx($site, null, null, "# include nothing; listen nowhere\nadd_header X-Note \"include listen server_name\" always;");

        $this->assertStringContainsString('X-Note', $this->vhost($site));
    }

    public function test_the_limits_are_bounded(): void
    {
        $site = $this->site();
        foreach ([[0, null], [3000, null], [null, 1], [null, 4000]] as [$upload, $timeout]) {
            try {
                $this->sites()->setNginx($site, $upload, $timeout, null);
                $this->fail('Accepted '.json_encode([$upload, $timeout]));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_config_is_backed_up_checked_and_put_back_if_nginx_refuses_it(): void
    {
        $steps = array_column($this->sites()->setupRecipe($this->site())['steps'], null, 'id');
        $ids = array_keys($steps);

        $this->assertSame(['vhost-backup', 'auth-file', 'vhost', 'nginx-test', 'reload-nginx'], array_slice($ids, -5), 'Back up, password file, write, check, reload.');
        $check = implode(' ', $steps['nginx-test']['args']);
        $this->assertStringContainsString('nginx -t', $check);
        $this->assertStringContainsString('cp -p /var/backups/cloud-manager/nginx/shop.conf /etc/nginx/sites-enabled/shop.conf', $check, 'The old file goes back.');
        $this->assertStringContainsString('rm -f /etc/nginx/sites-enabled/shop.conf', $check, 'Or the new one goes, if there was no old one.');
        $this->assertStringEndsWith('exit 1', $check, 'And the step still fails, so the operation does.');
    }

    public function test_saving_writes_the_settings_to_every_server_the_site_runs_on(): void
    {
        $site = $this->site();
        DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->sites()->setNginx($this->sites()->find('web-1.org-1', 'shop'), 128, null, null);

        $id = $this->app->make(OperationRunner::class)->queueWebServer((int) $site->id, 'console', 'me');
        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $applied = array_values(array_filter($this->gateway, fn ($c) => $c['kind'] === 'apply_recipe'));
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], array_column($applied, 'node'));
        foreach ($applied as $call) {
            $this->assertSame('web-server-shop', $call['spec']['name']);
            $this->assertSame(['vhost-backup', 'auth-file', 'vhost', 'nginx-test', 'reload-nginx'], array_column($call['spec']['steps'], 'id'), 'Only the web server part, so pending variables do not reach the server early.');
            $this->assertStringContainsString('client_max_body_size 128m;', base64_decode($call['spec']['steps'][2]['content_b64']));
        }
    }

    public function test_when_nginx_refuses_the_operation_fails_with_its_message(): void
    {
        $site = $this->site();
        $this->failure = 'nginx: [emerg] unknown directive "foo" in /etc/nginx/sites-enabled/shop.conf:9';

        $id = $this->app->make(OperationRunner::class)->queueWebServer((int) $site->id, 'console', 'me');
        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($id));

        $this->assertStringContainsString('unknown directive "foo"', (string) DB::table('operations')->where('id', $id)->value('error'));
    }

    public function test_the_console_saves_the_settings_queues_the_operation_and_shows_the_generated_config(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();

        $this->get("/site/settings?site={$site->id}&tab=web")->assertInertia(fn (Assert $page) => $page
            ->where('data.webUpload', 64)->where('data.webTimeout', 60)
            ->where('data.webPreview', fn ($vhost) => str_contains($vhost, 'client_max_body_size 64m;')));

        $url = "/site/settings/{$site->id}/web";
        $this->post($url, ['upload' => 200, 'timeout' => 90, 'snippet' => "add_header X-Frame-Options \"SAMEORIGIN\" always;"])->assertRedirect();

        $row = DB::table('sites')->where('id', $site->id)->first();
        $this->assertSame([200, 90], [(int) $row->nginx_upload_mb, (int) $row->nginx_timeout_s]);
        $this->assertSame(1, DB::table('operations')->where('kind', 'web_server')->count());
        Queue::assertPushed(\App\Lib\Operations\RunOperation::class);

        $this->post($url, ['upload' => 64, 'timeout' => 60, 'snippet' => 'include /etc/passwd;'])->assertSessionHasErrors('web');
        $this->assertSame(1, DB::table('operations')->where('kind', 'web_server')->count(), 'A refused snippet queues nothing.');
    }

    public function test_password_protection_writes_a_hash_file_and_asks_nginx_for_it_everywhere_but_the_certificate_challenge(): void
    {
        $site = $this->site();
        $site = $this->sites()->setBasicAuth($site, 'staging', 'a long enough secret');

        $steps = array_column($this->sites()->setupRecipe($site)['steps'], null, 'id');
        $this->assertSame('dir', $steps['auth-dir']['type']);
        $file = base64_decode($steps['auth-file']['content_b64']);
        $this->assertStringStartsWith('staging:$2y$', $file);
        $this->assertStringNotContainsString('a long enough secret', $file);
        $this->assertSame('/etc/nginx/cm-auth/shop.htpasswd', $steps['auth-file']['path']);
        $this->assertTrue($steps['auth-file']['sensitive']);

        $vhost = base64_decode($steps['vhost']['content_b64']);
        $this->assertStringContainsString('auth_basic "Restricted";', $vhost);
        $this->assertStringContainsString('auth_basic_user_file /etc/nginx/cm-auth/shop.htpasswd;', $vhost);
        // Certificates must still be issued and renewed.
        $this->assertMatchesRegularExpression('#acme-challenge/ \{\s+auth_basic off;#', $vhost);

        // Removing it takes the directive and the file away.
        $site = $this->sites()->clearBasicAuth($site);
        $steps = array_column($this->sites()->setupRecipe($site)['steps'], null, 'id');
        $this->assertSame('absent', $steps['auth-file']['type']);
        $this->assertStringNotContainsString('auth_basic "Restricted"', base64_decode($steps['vhost']['content_b64']));
    }

    public function test_password_protection_is_checked(): void
    {
        $site = $this->site();
        foreach ([['bad user!', 'a long enough secret'], ['staging', 'short']] as [$user, $password]) {
            try {
                $this->sites()->setBasicAuth($site, $user, $password);
                $this->fail("{$user}/{$password} was accepted");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        DB::table('sites')->where('id', $site->id)->update(['load_balancer_id' => 5]);
        $this->expectExceptionMessage('load balancer');
        $this->sites()->setBasicAuth(DB::table('sites')->find($site->id), 'staging', 'a long enough secret');
    }
}
