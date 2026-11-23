<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use stdClass;
use Tests\TestCase;

/**
 * An application in a folder of its repository: everything that works from the release or the live release works from that folder.
 */
final class RootFolderTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{kind: string, args: array<string, mixed>}> */
    private array $calls = [];

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->http = new HttpFactory;
        $this->http->fake(['gw.test/*' => function (Request $r) {
            $this->calls[] = ['kind' => $r['kind'], 'args' => $r['args'] ?? []];

            return $this->http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => "abc123\n", 'current' => 'older', 'deleted' => []]]);
        }]);
    }

    private function sites(): Sites
    {
        return new Sites($this->app->make(Vault::class), new AuditLog);
    }

    private function deployer(): Deployer
    {
        return new Deployer($this->sites(), new GatewayClient('http://gw.test', 't', $this->http, new AuditLog), new AuditLog,
            new GitHubApp($this->http, $this->app->make(Vault::class), new AuditLog));
    }

    private function site(string $root = 'apps/web', string $runtime = 'php', array $config = []): stdClass
    {
        $site = $this->sites()->find('web-1.org-1', 'shop') ?? $this->sites()->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/platform.git');
        if ($runtime !== 'php') {
            $site = $this->sites()->setRuntime($site, $runtime, $config);
        }
        DB::table('sites')->where('id', $site->id)->update(['root_dir' => $root === '' ? null : $root]);

        return $this->sites()->find('web-1.org-1', 'shop');
    }

    /** @return list<string> every argument of every command the deploy sent, flattened */
    private function everything(): array
    {
        $out = [];
        array_walk_recursive($this->calls, function ($v) use (&$out) {
            $out[] = (string) $v;
        });

        return $out;
    }

    /** What a recipe's steps say, with the files they write decoded. */
    private static function said(array $recipe): string
    {
        return collect($recipe['steps'] ?? [])->map(fn ($s) => json_encode($s).' '.(isset($s['content_b64']) ? base64_decode($s['content_b64']) : ''))->implode("\n");
    }

    private function called(string $kind): ?array
    {
        foreach ($this->calls as $c) {
            if ($c['kind'] === $kind) {
                return $c['args'];
            }
        }

        return null;
    }

    public function test_a_root_folder_is_a_path_inside_the_repository_or_nothing(): void
    {
        $this->assertSame('', Sites::cleanRootDir(''));
        $this->assertSame('', Sites::cleanRootDir(' / '));
        $this->assertSame('', Sites::cleanRootDir('.'));
        $this->assertSame('apps/web', Sites::cleanRootDir('/apps/web/'));
        $this->assertSame('packages/@acme/site.v2', Sites::cleanRootDir('packages/@acme/site.v2'));

        foreach (['../etc', 'apps/../..', '/../x', 'a b', 'apps//web', 'apps/./web', 'a;rm -rf', '$HOME', 'apps\\web', "apps\nweb"] as $bad) {
            try {
                Sites::cleanRootDir($bad);
                $this->fail("Accepted {$bad}");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_the_live_release_and_a_path_inside_a_release_move_into_the_folder_only_when_there_is_one(): void
    {
        $with = $this->site('apps/web');
        $this->assertSame('/home/deploy/shop/current/apps/web', Sites::current($with));
        $this->assertSame('/home/deploy/shop/releases/r1/apps/web', Sites::appDir($with, '/home/deploy/shop/releases/r1'));

        $without = $this->site('');
        $this->assertSame('/home/deploy/shop/current', Sites::current($without));
        $this->assertSame('/home/deploy/shop/releases/r1', Sites::appDir($without, '/home/deploy/shop/releases/r1'));
    }

    public function test_a_deploy_builds_in_the_folder_and_links_the_shared_files_inside_it_to_the_same_shared_place(): void
    {
        $this->deployer()->deploy($this->site('apps/web'), now: '20261112T120000Z');

        $everything = implode("\n", $this->everything());
        $this->assertStringContainsString('--working-dir=/home/deploy/shop/releases/20261112T120000Z-main/apps/web', $everything, 'composer runs in the folder');
        $this->assertStringContainsString('/home/deploy/shop/releases/20261112T120000Z-main/apps/web/artisan', $everything, 'and so does artisan');

        $prepare = $this->called('deploy');
        $this->assertSame([
            ['rel' => 'apps/web/.env', 'link' => '.env', 'kind' => 'file'],
            ['rel' => 'apps/web/storage', 'link' => 'storage', 'kind' => 'dir'],
        ], $prepare['shared']);

        $this->assertNotNull(collect($this->calls)->first(fn ($c) => ($c['args']['spec']['name'] ?? '') === 'root-folder'), 'It is checked that the folder is in the repository before anything is linked into it.');
    }

    public function test_without_a_root_folder_a_deploy_is_what_it_always_was(): void
    {
        $this->deployer()->deploy($this->site(''), now: '20261112T120000Z');

        $this->assertArrayNotHasKey('shared', $this->called('deploy'), 'The agent links .env and storage at the top, as before, so an older agent works.');
        $this->assertStringContainsString('--working-dir=/home/deploy/shop/releases/20261112T120000Z-main', implode("\n", $this->everything()));
        $this->assertStringNotContainsString('root-folder', implode("\n", $this->everything()));
    }

    public function test_nginx_serves_the_folders_public_directory_and_supervisor_runs_workers_from_it(): void
    {
        $site = $this->site('apps/web');

        $this->assertStringContainsString('root /home/deploy/shop/current/apps/web/public;', $this->sites()->generatedVhost($site));

        $after = self::said($this->deployer()->afterSwitchRecipe($site));
        $this->assertStringContainsString('/home/deploy/shop/current/apps/web/artisan', $after);
        $this->assertStringNotContainsString('/home/deploy/shop/current/artisan', $after);
        $this->assertStringContainsString('directory=/home/deploy/shop/current/apps/web', $after, 'The workers run from the folder.');
    }

    public function test_a_static_site_in_a_folder_is_served_from_that_folders_output(): void
    {
        $site = $this->site('apps/docs', 'static', ['output' => 'dist']);

        $this->assertStringContainsString('root /home/deploy/shop/current/apps/docs/dist;', $this->sites()->generatedVhost($site));
    }

    public function test_a_node_application_builds_and_starts_in_its_folder(): void
    {
        $site = $this->site('apps/api', 'node');
        $this->deployer()->deploy($site, now: '20261112T120000Z');

        $this->assertStringContainsString('releases/20261112T120000Z-main/apps/api', implode("\n", $this->everything()), 'Install and build run in the folder.');
        $this->assertStringContainsString('directory=/home/deploy/shop/current/apps/api', self::said($this->deployer()->afterSwitchRecipe($site)), 'And it starts there.');
    }

    public function test_a_missing_folder_stops_the_deploy_with_the_folder_named(): void
    {
        $http = new HttpFactory;
        $http->fake(['gw.test/*' => function (Request $r) use ($http) {
            if (($r['args']['spec']['name'] ?? '') === 'root-folder') {
                return $http->response(['status' => 'failed', 'error' => 'the root folder apps/web is in the repository on main', 'outcome_known' => true]);
            }

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => "abc\n", 'current' => 'older']]);
        }]);
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gw.test', 't', $http, new AuditLog), new AuditLog, new GitHubApp($http, $this->app->make(Vault::class), new AuditLog));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('the live release is unchanged');
        $deployer->deploy($this->site('apps/web'), now: '20261112T120000Z');
    }

    public function test_a_server_with_an_older_agent_is_refused_before_anything_is_cloned(): void
    {
        config(['control_plane.agent_version' => 'new1234']);
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['agent_version' => 'old5678']);

        try {
            $this->deployer()->deploy($this->site('apps/web'), now: '20261112T120000Z');
            $this->fail('An older agent would have linked an empty shared/apps/web/.env.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('older agent', $e->getMessage());
            $this->assertStringContainsString('Upgrade the agent', $e->getMessage());
        }
        $this->assertSame([], $this->calls, 'Nothing was sent to the server.');

        // At the top of the repository the old agent is fine.
        $this->deployer()->deploy($this->site(''), now: '20261112T120100Z');
        $this->assertNotEmpty($this->calls);
    }

    public function test_changing_the_folder_of_a_site_is_saved_checked_and_audited(): void
    {
        $site = $this->site('');

        $moved = $this->sites()->setRootDir($site, '/apps/web/');
        $this->assertSame('apps/web', $moved->root_dir);
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.root_dir_changed')->count());

        $this->sites()->setRootDir($moved, 'apps/web');
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.root_dir_changed')->count(), 'No change, no record.');

        $this->assertNull($this->sites()->setRootDir($moved, '')->root_dir);

        $this->expectException(InvalidArgumentException::class);
        $this->sites()->setRootDir($moved, '../outside');
    }

    public function test_the_wizard_plan_checks_and_keeps_the_folder(): void
    {
        $plan = fn (array $over) => app(NewApplications::class)->plan('org-1', $over + [
            'git' => ['source' => 'github_app', 'url' => 'git@github.com:acme/platform.git', 'host' => 'github.com', 'display' => 'acme/platform', 'fingerprint' => null],
            'name' => 'platform-web', 'repository' => 'acme/platform', 'branch' => 'main', 'server' => ['mode' => 'existing', 'node' => 'web-1.org-1'],
        ]);

        try {
            $plan(['root_dir' => '../x']);
            $this->fail('A folder outside the repository was accepted.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('root_dir: ', $e->getMessage());
        }
    }
}
