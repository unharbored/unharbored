<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * A site on several servers must never end up half on one release and half
 * on another, must migrate once, and must run its scheduler once.
 */
final class MultiServerDeployTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{node: string, kind: string, spec: ?string, steps: list<string>}> */
    private array $calls = [];

    private ?string $failOn = null;

    /** @var list<string> */
    private array $pruned = [];

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);

        $this->http = new HttpFactory();
        $this->http->fake(['gw.test/*' => function (Request $r) {
            preg_match('#/v1/nodes/([^/]+)/commands#', $r->url(), $m);
            $spec = $r['args']['spec'] ?? null;
            $call = ['node' => $m[1], 'kind' => $r['kind'], 'spec' => $spec['name'] ?? null, 'steps' => array_column($spec['steps'] ?? [], 'id')];
            $this->calls[] = $call;
            if ($this->failOn !== null && $this->failOn === "{$call['node']}:{$call['kind']}") {
                return $this->http->response(['status' => 'failed', 'error' => "{$call['kind']} broke on {$call['node']}", 'outcome_known' => true]);
            }

            return $this->http->response(['status' => 'succeeded', 'outcome_known' => true,
                'detail' => ['output' => "abc123\n", 'current' => 'older', 'deleted' => $r['kind'] === 'prune_releases' ? $this->pruned : []]]);
        }]);
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog());
    }

    private function deployer(): Deployer
    {
        return new Deployer($this->sites(), new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()), new AuditLog(),
            new GitHubApp($this->http, $this->app->make(Vault::class), new AuditLog()));
    }

    private function siteOnTwoServers(): stdClass
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['current_release' => 'r-old']);
        DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);

        return $this->sites()->find('web-1.org-1', 'shop');
    }

    /** @return list<string> */
    private function kinds(string $node): array
    {
        return array_column(array_values(array_filter($this->calls, fn ($c) => $c['node'] === $node)), 'kind');
    }

    public function test_every_server_is_built_before_any_switches(): void
    {
        $lines = [];
        $this->deployer()->deploy($this->siteOnTwoServers(), now: '20261106T120000Z', log: function ($a, $t) use (&$lines) { $lines[] = $t; });

        $firstActivate = array_search('activate_release', array_column($this->calls, 'kind'), true);
        $lastBuild = max(array_keys(array_filter($this->calls, fn ($c) => $c['spec'] === 'build')));
        $this->assertGreaterThan($lastBuild, $firstActivate, 'Nothing switches until the release is built everywhere.');

        $this->assertContains('activate_release', $this->kinds('web-1.org-1'));
        $this->assertContains('activate_release', $this->kinds('web-2.org-1'));
        $this->assertNotEmpty(array_filter($lines, fn ($l) => str_starts_with($l, '[web-2] ')), 'Log lines say which server.');
    }

    public function test_every_server_has_its_programs_updated_before_any_restarts_workers(): void
    {
        $this->deployer()->deploy($this->siteOnTwoServers(), now: '20261106T120000Z');

        $specs = array_column($this->calls, 'spec');
        $programs = array_keys($specs, 'programs-shop', true);
        $firstAfter = array_search('after-switch-shop', $specs, true);
        $this->assertCount(2, $programs);
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], array_map(fn ($i) => $this->calls[$i]['node'], $programs));
        $this->assertGreaterThan(max($programs), $firstAfter, 'A restart signal reaches every server through the shared cache.');
    }

    public function test_migrations_run_once_on_the_primary(): void
    {
        $this->deployer()->deploy($this->siteOnTwoServers(), now: '20261106T120000Z');

        $builds = array_values(array_filter($this->calls, fn ($c) => $c['spec'] === 'build'));
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], array_column($builds, 'node'));
        $this->assertContains('migrate', $builds[0]['steps']);
        $this->assertNotContains('migrate', $builds[1]['steps']);
    }

    public function test_the_scheduler_runs_on_the_primary_only(): void
    {
        $site = $this->siteOnTwoServers();
        $after = fn (bool $primary) => array_column($this->deployer()->afterSwitchRecipe($site, null, scheduler: $primary)['steps'], null, 'id')['scheduler'];

        $this->assertSame('file', $after(true)['type']);
        $this->assertSame('absent', $after(false)['type'], 'Otherwise every scheduled job runs once per server.');
    }

    public function test_a_build_failing_anywhere_switches_nothing(): void
    {
        $this->failOn = 'web-2.org-1:apply_recipe';
        $site = $this->siteOnTwoServers();

        try {
            $this->deployer()->deploy($site, now: '20261106T120000Z');
            $this->fail('Should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('live release is unchanged', $e->getMessage());
        }

        $this->assertNotContains('activate_release', array_column($this->calls, 'kind'));
        $this->assertSame('r-old', DB::table('sites')->where('id', $site->id)->value('current_release'));
    }

    public function test_a_switch_failing_on_one_server_puts_the_others_back(): void
    {
        $this->failOn = 'web-2.org-1:activate_release';
        $site = $this->siteOnTwoServers();

        try {
            $this->deployer()->deploy($site, now: '20261106T120000Z');
            $this->fail('Should have failed.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('live release is unchanged', $e->getMessage());
        }

        $web1 = $this->kinds('web-1.org-1');
        $this->assertContains('activate_release', $web1);
        $this->assertSame('rollback', end($web1), 'web-1 switched, so it is switched back.');
        $this->assertNotContains('rollback', $this->kinds('web-2.org-1'), 'web-2 never switched.');
        $this->assertSame('r-old', DB::table('sites')->where('id', $site->id)->value('current_release'));
    }

    public function test_rollback_moves_every_server(): void
    {
        $this->deployer()->rollback($this->siteOnTwoServers());

        $this->assertContains('rollback', $this->kinds('web-1.org-1'));
        $this->assertContains('rollback', $this->kinds('web-2.org-1'));
    }

    public function test_with_a_bucket_the_primary_builds_once_and_the_others_unpack_that_build(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $site = $this->siteOnTwoServers();
        $this->sites()->setEnvValues($site, ['AWS_ENDPOINT' => 'https://nyc3.digitaloceanspaces.com', 'AWS_BUCKET' => 'cm-shop-abc123',
            'AWS_ACCESS_KEY_ID' => 'AK', 'AWS_SECRET_ACCESS_KEY' => 'SK']);
        DB::table('sites')->where('id', $site->id)->update(['bucket_id' => 7]);

        $this->deployer()->deploy($this->sites()->find('web-1.org-1', 'shop'), now: '20261106T120000Z');

        $specs = fn (string $node) => array_column(array_values(array_filter($this->calls, fn ($c) => $c['node'] === $node && $c['spec'] !== null)), 'spec');
        $this->assertContains('build', $specs('web-1.org-1'));
        $this->assertContains('pack', $specs('web-1.org-1'));
        $this->assertNotContains('build', $specs('web-2.org-1'), 'web-2 does not build for itself.');
        $this->assertNotContains('fetch-shop', $specs('web-2.org-1'), 'web-2 never needs to reach GitHub.');
        $this->assertContains('unpack', $specs('web-2.org-1'));
        $this->assertContains('deploy', $this->kinds('web-2.org-1'), 'The unpacked build is linked to shared like any other.');

        $kinds = array_map(fn ($c) => $c['spec'] ?? $c['kind'], $this->calls);
        $this->assertLessThan(array_search('activate_release', $kinds, true), array_search('unpack', $kinds, true), 'Every server has the build before any switches.');

        \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_contains($r->url(), 'cm-shop-abc123/.cloud-manager/releases/'));
    }

    private function pooledSite(): stdClass
    {
        $site = $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        $this->sites()->setEnvValues($site, ['AWS_ENDPOINT' => 'https://nyc3.digitaloceanspaces.com', 'AWS_BUCKET' => 'cm-shop-abc123',
            'AWS_ACCESS_KEY_ID' => 'AK', 'AWS_SECRET_ACCESS_KEY' => 'SK']);
        DB::table('sites')->where('id', $site->id)->update(['bucket_id' => 7, 'autoscale_pool_id' => 8, 'current_release' => 'r-old']);

        return $this->sites()->find('web-1.org-1', 'shop');
    }

    public function test_an_autoscaled_site_keeps_each_build_for_as_long_as_its_release(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $this->pruned = ['20261101T000000Z-main'];

        $this->deployer()->deploy($this->pooledSite(), now: '20261106T120000Z');

        $this->assertContains('pack', array_column($this->calls, 'spec'), 'Packed even on one server: a member added later needs it.');
        $deletes = [];
        \Illuminate\Support\Facades\Http::assertSent(function ($r) use (&$deletes) {
            if ($r->method() === 'DELETE') {
                $deletes[] = basename(parse_url($r->url(), PHP_URL_PATH));
            }

            return true;
        });
        $this->assertSame(['20261101T000000Z-main.tar.gz'], $deletes, 'Only the pruned release loses its build; the live one keeps it.');
    }

    public function test_a_pool_member_joins_on_the_live_release_without_building(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('x', 206)]);
        $site = $this->pooledSite();

        $this->deployer()->join($site, 'shop-555.org-1');

        $mine = array_values(array_filter($this->calls, fn ($c) => $c['node'] === 'shop-555.org-1'));
        $this->assertSame(['site-setup-shop', 'unpack', 'deploy', 'cache', 'activate_release'], array_slice(array_map(fn ($c) => $c['spec'] ?? $c['kind'], $mine), 0, 5));
        $this->assertNotContains('build', array_column($this->calls, 'spec'));
        $this->assertSame([], array_filter($this->calls, fn ($c) => $c['node'] !== 'shop-555.org-1'), 'Nothing else is touched.');
    }

    public function test_a_join_packs_the_live_release_first_when_the_bucket_lacks_it(): void
    {
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response('', 404)]);

        $this->deployer()->join($this->pooledSite(), 'shop-555.org-1');

        $order = array_map(fn ($c) => "{$c['node']}:".($c['spec'] ?? $c['kind']), $this->calls);
        $this->assertContains('web-1.org-1:pack', $order);
        $this->assertLessThan(array_search('shop-555.org-1:unpack', $order, true), array_search('web-1.org-1:pack', $order, true));
        $this->assertNotContains('web-1.org-1:build', $order, 'The primary packs what is live; it builds nothing.');
    }

    public function test_a_presigned_url_names_one_object_and_expires(): void
    {
        $url = \App\Lib\Cloud\Spaces::presign('GET', 'https://nyc3.digitaloceanspaces.com', 'cm-shop-abc123', '.cloud-manager/releases/r1.tar.gz', 'AK', 'SK', 1800, 1762430400);

        $this->assertStringStartsWith('https://nyc3.digitaloceanspaces.com/cm-shop-abc123/.cloud-manager/releases/r1.tar.gz?', $url);
        $this->assertStringContainsString('X-Amz-Expires=1800', $url);
        $this->assertMatchesRegularExpression('/X-Amz-Signature=[0-9a-f]{64}$/', $url);
        $this->assertStringNotContainsString('SK', parse_url($url, PHP_URL_QUERY) ?? '');
    }
}
