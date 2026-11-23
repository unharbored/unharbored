<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use stdClass;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * A script for what the stack does not install: kept in versions, run as root
 * on a server the first time it joins the application, before it takes
 * traffic, and a failure stops the join.
 */
final class ServerScriptTest extends TestCase
{
    use RefreshDatabase;

    private const SCRIPT = "#!/usr/bin/env bash\nset -euo pipefail\napt-get install -y ffmpeg\n";

    /** @var list<array{node: string, kind: string, spec: ?string, steps: list<array<string, mixed>>}> */
    private array $calls = [];

    private bool $scriptFails = false;

    private HttpFactory $http;

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
        $this->http = new HttpFactory();
        $this->http->fake(['gw.test/*' => function (Request $r) {
            preg_match('#/v1/nodes/([^/]+)/commands#', $r->url(), $m);
            $spec = $r['args']['spec'] ?? null;
            $this->calls[] = ['node' => $m[1], 'kind' => $r['kind'], 'spec' => $spec['name'] ?? null, 'steps' => $spec['steps'] ?? []];
            if ($this->scriptFails && ($spec['name'] ?? '') === 'server-script-shop') {
                return $this->http->response(['status' => 'failed', 'error' => 'command bash exited 100: E: Unable to locate package ffmpeg', 'outcome_known' => true]);
            }

            return $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => "abc123\n", 'current' => 'older', 'deleted' => []]]);
        }]);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()));
    }

    private function sites(): Sites
    {
        return $this->app->make(Sites::class);
    }

    private function deployer(): Deployer
    {
        return new Deployer($this->sites(), new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()), new AuditLog(),
            new GitHubApp($this->http, $this->app->make(Vault::class), new AuditLog()));
    }

    private function site(): stdClass
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['current_release' => 'r-old']);

        return $this->sites()->find('web-1.org-1', 'shop');
    }

    /** @return list<string> the specs sent to a node, in order */
    private function specs(string $node): array
    {
        return array_values(array_map(fn ($c) => $c['spec'] ?? $c['kind'], array_filter($this->calls, fn ($c) => $c['node'] === $node)));
    }

    public function test_versions_are_kept_and_saving_the_same_text_adds_none(): void
    {
        $site = $this->site();
        $this->assertSame(['version' => 0, 'content' => ''], Sites::serverScript($site));

        $this->assertSame(1, $this->sites()->saveServerScript($site, "echo one\r\n", 'matt@example.com'));
        $this->assertSame(1, $this->sites()->saveServerScript($site, "echo one\n\n\n", 'matt@example.com'), 'Trailing blank lines and CRLF are not a change.');
        $this->assertSame(2, $this->sites()->saveServerScript($site, 'echo two', 'matt@example.com'));

        $this->assertSame("echo two\n", Sites::serverScript($site)['content']);
        $this->assertSame("echo one\n", Sites::serverScriptVersion($site, 1));
        $this->assertSame([2, 1], array_column(Sites::serverScriptVersions($site), 'version'));
        $this->assertSame(2, DB::table('audit_records')->where('action', 'site.script_saved')->count());

        $this->assertSame(3, $this->sites()->saveServerScript($site, "  \n", 'matt@example.com'), 'An empty script removes it, as a version.');
        $this->assertSame('', Sites::serverScript($site)['content']);
    }

    public function test_a_script_that_is_too_large_or_binary_is_refused(): void
    {
        $site = $this->site();
        foreach ([str_repeat('x', Sites::SCRIPT_MAX + 1), "echo \0"] as $bad) {
            try {
                $this->sites()->saveServerScript($site, $bad, 'me');
                $this->fail('refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('site_scripts')->count());
    }

    public function test_the_script_runs_as_root_from_a_file_that_is_removed_afterwards(): void
    {
        $site = $this->site();
        $this->sites()->saveServerScript($site, self::SCRIPT, 'me');

        $this->deployer()->runServerScript(Sites::on($site, 'web-2.org-1'));

        $this->assertSame(['server-script-shop', 'forget-server-script'], $this->specs('web-2.org-1'));
        $steps = $this->calls[0]['steps'];
        $this->assertSame(['file', 'command'], array_column($steps, 'type'));
        $this->assertSame(['/root/.cm-setup-shop.sh', '0700', self::SCRIPT], [$steps[0]['path'], $steps[0]['mode'], base64_decode($steps[0]['content_b64'])]);
        $this->assertSame(['bash', ['/root/.cm-setup-shop.sh']], [$steps[1]['name'], $steps[1]['args']]);
        $this->assertSame('absent', $this->calls[1]['steps'][0]['type']);
        $this->assertSame(1, (int) DB::table('site_script_runs')->where('node_id', 'web-2.org-1')->value('version'));
    }

    public function test_a_failing_script_fails_the_run_is_not_recorded_and_is_still_removed(): void
    {
        $site = $this->site();
        $this->sites()->saveServerScript($site, self::SCRIPT, 'me');
        $this->scriptFails = true;

        try {
            $this->deployer()->runServerScript(Sites::on($site, 'web-2.org-1'));
            $this->fail('A failing script stops the join.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Unable to locate package ffmpeg', $e->getMessage());
        }
        $this->assertSame(0, DB::table('site_script_runs')->count());
        $this->assertSame(['server-script-shop', 'forget-server-script'], $this->specs('web-2.org-1'));
    }

    public function test_a_server_runs_it_once_when_it_joins_and_not_again_on_the_next_deploy(): void
    {
        $site = $this->site();
        $this->sites()->saveServerScript($site, self::SCRIPT, 'me');
        DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->deployer()->deploy($this->sites()->find('web-1.org-1', 'shop'), now: '20261110T120000Z');
        $this->assertContains('server-script-shop', $this->specs('web-1.org-1'));
        $this->assertContains('server-script-shop', $this->specs('web-2.org-1'));
        $setup = array_search('site-setup-shop', $this->specs('web-2.org-1'), true);
        $this->assertSame($setup + 1, array_search('server-script-shop', $this->specs('web-2.org-1'), true), 'Right after the server is set up, before it fetches or builds anything.');

        $this->calls = [];
        $this->deployer()->deploy($this->sites()->find('web-1.org-1', 'shop'), now: '20261110T130000Z');
        $this->assertNotContains('server-script-shop', array_column($this->calls, 'spec'));
    }

    public function test_a_failing_script_stops_a_deploy_before_anything_is_built(): void
    {
        $site = $this->site();
        $this->sites()->saveServerScript($site, self::SCRIPT, 'me');
        $this->scriptFails = true;

        try {
            $this->deployer()->deploy($site, now: '20261110T120000Z');
            $this->fail('stopped');
        } catch (RuntimeException) {
        }
        $this->assertNotContains('build', array_column($this->calls, 'spec'));
        $this->assertNotContains('activate_release', array_column($this->calls, 'kind'));
    }

    public function test_an_autoscale_member_runs_it_before_it_gets_the_release(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('x', 206)]);
        $site = $this->site();
        $this->sites()->setEnvValues($site, ['AWS_ENDPOINT' => 'https://nyc3.digitaloceanspaces.com', 'AWS_BUCKET' => 'cm-shop-abc123', 'AWS_ACCESS_KEY_ID' => 'AK', 'AWS_SECRET_ACCESS_KEY' => 'SK']);
        DB::table('sites')->where('id', $site->id)->update(['bucket_id' => 7, 'autoscale_pool_id' => 8]);
        $site = $this->sites()->find('web-1.org-1', 'shop');
        $this->sites()->saveServerScript($site, self::SCRIPT, 'me');

        $this->deployer()->join($site, 'shop-555.org-1');

        $mine = $this->specs('shop-555.org-1');
        $this->assertSame(['site-setup-shop', 'server-script-shop', 'forget-server-script', 'unpack'], array_slice($mine, 0, 4));
    }

    public function test_a_site_with_no_script_is_unchanged(): void
    {
        $this->deployer()->deploy($this->site(), now: '20261110T120000Z');
        $this->assertNotContains('server-script-shop', array_column($this->calls, 'spec'));
        $this->assertSame(0, DB::table('site_script_runs')->count());
    }

    public function test_run_on_all_servers_goes_through_every_server_in_an_operation(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();
        DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);
        $url = "/site/settings/{$site->id}/script";
        $page = fn () => $this->get("/site/settings?site={$site->id}&tab=script");

        $page()->assertInertia(fn (Assert $p) => $p->component('Site/Settings')->has('data.scriptVersions', 0));
        $this->post($url, ['text' => self::SCRIPT, 'run' => false])->assertRedirect();
        $this->assertSame(1, Sites::serverScript($site)['version']);
        $this->assertSame(0, DB::table('operations')->where('kind', 'server_script')->count(), 'Save alone runs nothing.');

        $page()->assertInertia(fn (Assert $p) => $p->where('data.scriptVersions.0.version', 1)->where('data.scriptText', self::SCRIPT));
        $this->post($url, ['text' => self::SCRIPT.'echo done', 'run' => true])->assertRedirect();
        $this->assertSame(2, Sites::serverScript($site)['version']);
        $op = DB::table('operations')->where('kind', 'server_script')->first();
        $this->assertNotNull($op);

        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run((int) $op->id));
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], DB::table('site_script_runs')->orderBy('node_id')->pluck('node_id')->all());
        $this->assertSame([2, 2], DB::table('site_script_runs')->pluck('version')->all());

        $this->postJson("{$url}/versions/1")->assertOk()->assertJson(fn ($json) => $json->where('text', fn ($text) => str_contains($text, 'apt-get install')));
        $this->post($url, ['text' => str_repeat('x', Sites::SCRIPT_MAX + 1), 'run' => false])->assertSessionHasErrors('script');
    }
}
