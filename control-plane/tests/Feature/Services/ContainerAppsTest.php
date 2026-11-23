<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Audit\AuditLog;
use App\Lib\Containers\AppRecipes;
use App\Lib\Containers\ContainerApps;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Services\ContainerRecipes;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Runtimes;
use App\Lib\Sites\Sites;
use App\Lib\Vault\Vault;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Applications from a Dockerfile and from a Docker image (docs/specs/templates.md, step 6), with the gateway faked: the recipes a
 * deploy, a rollback and an update send are checked step by step, in order, as the service tests do.
 */
final class ContainerAppsTest extends ServiceTestCase
{
    private const COMMIT_A = 'aaaaaaaaaaaa1111111111111111111111111111';

    private const COMMIT_B = 'bbbbbbbbbbbb2222222222222222222222222222';

    private const DIGEST_1 = 'sha256:1111111111111111111111111111111111111111111111111111111111111111';

    private const DIGEST_2 = 'sha256:2222222222222222222222222222222222222222222222222222222222222222';

    private const TOKEN = 'ghp_SUPERSECRETTOKEN123';

    private string $commit = self::COMMIT_A;

    /** the digest a tag has on the registry right now */
    private string $tagDigest = self::DIGEST_1;

    /** @var list<string> images Docker "has" on the server; an image absent from here makes `docker image inspect` fail in the test below */
    private array $dockerEnv = [];

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['containers_ready_at' => now(), 'docker_version' => '29.0.0']);
        $this->execOutput = function (string $name, array $args): string {
            if ($name === 'runuser') {
                return $this->commit."\n";
            }
            if ($name === 'docker' && ($args[0] ?? '') === 'image' && ($args[1] ?? '') === 'inspect') {
                $ref = (string) end($args);
                if (str_contains((string) ($args[3] ?? ''), 'RepoDigests')) {
                    $digest = preg_match('/@(sha256:[0-9a-f]{64})$/', $ref, $m) ? $m[1] : $this->tagDigest;

                    return AppRecipes::repository($ref).'@'.$digest."\n";
                }

                return 'sha256:'.hash('sha256', $ref)."\n";
            }

            return '';
        };
    }

    private function execFor(string $name, array $args): string
    {
        return ($this->execOutput)($name, $args);
    }

    private function apps(): ContainerApps
    {
        return $this->app->make(ContainerApps::class);
    }

    private function runner(): OperationRunner
    {
        return $this->app->make(OperationRunner::class);
    }

    /** @return array<string, mixed> */
    private function dockerfileInput(array $over = []): array
    {
        return $over + ['kind' => 'dockerfile', 'name' => 'shop', 'node' => 'web-1.org-1', 'domain' => 'shop.example.com', 'branch' => 'main',
            'git' => ['url' => 'https://github.com/acme/shop.git', 'source' => 'https', 'host' => 'github.com', 'fingerprint' => null],
            'container_port' => 3000, 'health' => '/up', 'memory' => '512MB'];
    }

    /** @return array<string, mixed> */
    private function imageInput(array $over = []): array
    {
        return $over + ['kind' => 'image', 'name' => 'tool', 'node' => 'web-1.org-1', 'domain' => 'tool.example.com', 'image' => 'ghcr.io/acme/tool:2.1', 'container_port' => 8080];
    }

    private function make(array $input): object
    {
        $made = $this->apps()->start('org-1', $this->apps()->plan('org-1', $input), 'm@example.com');

        return DB::table('sites')->where('id', $made['site'])->first();
    }

    private function site(string $name): object
    {
        return DB::table('sites')->where('name', $name)->first();
    }

    private function state(string $name): array
    {
        return ContainerApps::state($this->site($name));
    }

    /** Deploys inline. @return array{op: int, status: string} */
    private function deploy(string $name): array
    {
        // The create operation is queued and never run here; it must not count as work in progress.
        DB::table('operations')->where('kind', 'app_create')->update(['status' => 'succeeded']);
        $op = $this->runner()->recordDeploy((int) $this->site($name)->id, 'console', 'm@example.com');

        return ['op' => $op, 'status' => $this->operate($op)];
    }

    private function error(int $op): string
    {
        return (string) DB::table('operations')->where('id', $op)->value('error');
    }

    /** @return list<array<string, mixed>> the steps (by recipe name prefix) in the order sent */
    private function allSteps(string $prefix): array
    {
        $out = [];
        foreach ($this->recipes as $r) {
            if (str_starts_with($r['name'], $prefix)) {
                array_push($out, ...$r['steps']);
            }
        }

        return $out;
    }

    /** @return list<string> */
    private function recipeOrder(): array
    {
        return array_values(array_filter($this->recipeNames(), fn ($n) => preg_match('/^(app-|fetch-|web-server-|site-setup)/', $n) === 1));
    }

    private function dockerRun(): array
    {
        $runs = array_values(array_filter($this->allSteps('app-run'), fn ($s) => $s['id'] === 'run'));

        return end($runs)['args'];
    }

    // ---- creating: validation ---------------------------------------------------------------

    public function test_a_dockerfile_application_is_planned_with_its_defaults(): void
    {
        $plan = $this->apps()->plan('org-1', $this->dockerfileInput());

        $this->assertSame(['deploy', 'https'], $plan['steps'], 'a domain was given, so no sslip address step');
        $this->assertSame(['dockerfile' => 'Dockerfile', 'context' => '.', 'container_port' => 3000, 'health' => '/up', 'memory' => '512MB', 'read_only' => false, 'volumes' => []], $plan['settings']);
        $this->assertSame('main', $plan['branch']);
        $this->assertSame(['address', 'deploy', 'https'], $this->apps()->plan('org-1', $this->dockerfileInput(['domain' => '']))['steps']);
    }

    public function test_dockerfile_validation_names_the_field_at_fault(): void
    {
        $bad = [
            ['name' => 'Bad Name'], ['container_port' => 0], ['container_port' => 70000], ['health' => 'no-slash'], ['memory' => '10 apples'], ['memory' => '8MB'],
            ['dockerfile' => '/etc/passwd'], ['dockerfile' => '../Dockerfile'], ['context' => '../..'], ['context' => 'a b'],
            ['volumes' => '/proc'], ['volumes' => 'data'], ['volumes' => '/var/run/docker.sock'], ['volumes' => "/a\n/a"],
            ['domain' => 'not a domain'], ['node' => 'someone-else.org-2'], ['git' => ['url' => 'nonsense', 'source' => 'https'], 'repository' => ''],
        ];
        foreach ($bad as $change) {
            try {
                $this->apps()->plan('org-1', $this->dockerfileInput($change));
                $this->fail('accepted '.json_encode($change));
            } catch (InvalidArgumentException $e) {
                $this->assertMatchesRegularExpression('/^[a-z_]+: /', $e->getMessage(), json_encode($change));
            }
        }
    }

    public function test_an_unprepared_server_is_refused_with_the_way_forward(): void
    {
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['containers_ready_at' => null]);

        $this->expectExceptionMessage('node: this server is not prepared for containers. Prepare it first from its Containers page.');
        $this->apps()->plan('org-1', $this->dockerfileInput());
    }

    public function test_a_name_used_by_an_application_or_a_service_is_refused(): void
    {
        $this->make($this->dockerfileInput());
        try {
            $this->apps()->plan('org-1', $this->dockerfileInput());
            $this->fail('a second shop');
        } catch (InvalidArgumentException $e) {
            $this->assertStringStartsWith('name: ', $e->getMessage());
        }
        $this->install('rustfs', $this->rustfs('files'));
        $this->expectExceptionMessage('a service called files already exists');
        $this->apps()->plan('org-1', $this->dockerfileInput(['name' => 'files']));
    }

    public function test_a_small_server_gets_the_build_memory_warning_and_a_big_one_does_not(): void
    {
        $this->assertSame([], $this->apps()->plan('org-1', $this->dockerfileInput())['warnings']);
        DB::table('node_metrics')->where('node_id', 'web-1.org-1')->update(['mem_total' => 1024 ** 3]);

        $warnings = $this->apps()->plan('org-1', $this->dockerfileInput())['warnings'];
        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('1 GB of memory', $warnings[0]);
        $this->assertStringContainsString('builds on the server', $warnings[0]);
        // An image is pulled, not built: no memory warning for it, only the not-reviewed one.
        $image = $this->apps()->plan('org-1', $this->imageInput())['warnings'];
        $this->assertCount(1, $image);
        $this->assertStringContainsString('has not reviewed this image', $image[0]);
    }

    public function test_image_references_need_a_tag_and_nothing_odd(): void
    {
        foreach (['nginx:1.27', 'ghcr.io/acme/tool:2.1', 'registry.example.com:5000/team/app:v1', 'acme/app:latest', 'nginx@sha256:'.str_repeat('a', 64), 'nginx:1.27@sha256:'.str_repeat('b', 64)] as $ok) {
            $this->assertSame($ok, ContainerApps::imageReference($ok)['ref']);
        }
        foreach (['nginx', '', 'nginx:', '-v /:/host alpine:3', 'nginx:1.27; rm -rf /', 'NGINX:1', 'a b:1', 'localhost:5000/app', 'https://x/y:1'] as $bad) {
            try {
                ContainerApps::imageReference($bad);
                $this->fail("accepted {$bad}");
            } catch (InvalidArgumentException $e) {
                $this->assertStringStartsWith('image: ', $e->getMessage());
            }
        }
        $this->assertSame('sha256:'.str_repeat('a', 64), ContainerApps::imageReference('nginx@sha256:'.str_repeat('a', 64))['digest']);
    }

    public function test_an_image_application_is_planned_with_a_registry_login_only_when_a_user_and_token_come_together(): void
    {
        $plan = $this->apps()->plan('org-1', $this->imageInput(['registry_user' => 'bot', 'registry_token' => self::TOKEN]));
        $this->assertSame(['server' => 'ghcr.io', 'user' => 'bot'], $plan['registry']);
        $this->assertNull($this->apps()->plan('org-1', $this->imageInput())['registry']);
        $this->expectExceptionMessage('registry_user: a registry login needs a user name and a token');
        $this->apps()->plan('org-1', $this->imageInput(['registry_user' => 'bot']));
    }

    public function test_a_hub_image_logs_in_to_docker_io(): void
    {
        $this->assertSame('docker.io', AppRecipes::registry('acme/tool:1'));
        $this->assertSame('docker.io', AppRecipes::registry('nginx:1'));
        $this->assertSame('ghcr.io', AppRecipes::registry('ghcr.io/acme/tool:1'));
        $this->assertSame('localhost:5000', AppRecipes::registry('localhost:5000/tool:1'));
        $this->assertSame('ghcr.io/acme/tool', AppRecipes::repository('ghcr.io/acme/tool:2.1'));
        $this->assertSame('registry.example.com:5000/team/app', AppRecipes::repository('registry.example.com:5000/team/app:v1'));
        $this->assertSame('nginx', AppRecipes::repository('nginx@'.self::DIGEST_1));
    }

    // ---- creating: the rows -------------------------------------------------------------------

    public function test_a_dockerfile_application_is_a_site_row_with_the_container_runtime_and_an_app_create_operation(): void
    {
        $site = $this->make($this->dockerfileInput(['env' => ['STRIPE_KEY' => 'sk_live_1']]));

        $this->assertSame('dockerfile', $site->runtime);
        $this->assertTrue(Runtimes::isContainer($site));
        $this->assertSame('https://github.com/acme/shop.git', $site->repository);
        $this->assertNotNull($site->deploy_key_public, 'a repository application keeps its deploy key');
        $this->assertSame([3000, 'Dockerfile'], [$this->state('shop')['container_port'], $this->state('shop')['dockerfile']]);
        $op = DB::table('operations')->where('kind', 'app_create')->first();
        $this->assertSame((int) $site->id, (int) $op->site_id);
        $this->assertSame(['deploy', 'https'], array_column(json_decode($op->stages, true), 'name'));
        $this->assertSame([], json_decode((string) app(Vault::class)->get('org-1', 'site:web-1.org-1:shop:env:injected'), true), 'none of Laravel\'s defaults are injected');
        $this->assertStringContainsString('STRIPE_KEY=sk_live_1', app(Sites::class)->env($site));
    }

    public function test_an_image_application_has_no_repository_and_no_deploy_key_and_no_git_path(): void
    {
        $site = $this->make($this->imageInput(['registry_user' => 'bot', 'registry_token' => self::TOKEN]));

        $this->assertSame('image', $site->runtime);
        $this->assertNull($site->repository);
        $this->assertNull($site->deploy_key_public);
        $this->assertFalse((bool) $site->push_to_deploy);
        $this->assertNull(app(Sites::class)->deployKey($site), 'no deploy key was ever made');
        $this->assertSame(['ghcr.io', 'bot', null], [$this->state('tool')['registry']['server'], $this->state('tool')['registry']['user'], $this->state('tool')['digest']]);
        $this->assertSame(self::TOKEN, app(Vault::class)->get('org-1', ContainerApps::registrySecret($site)));
        // The registry token is in the vault, not in the row, the operation or the audit trail.
        $dump = json_encode(DB::table('sites')->get()).json_encode(DB::table('operations')->get()).json_encode(DB::table('audit_records')->get());
        $this->assertStringNotContainsString(self::TOKEN, $dump);
        $this->assertSame(['deploy', 'https'], array_column(json_decode((string) DB::table('operations')->where('kind', 'app_create')->value('stages'), true), 'name'));
    }

    public function test_git_paths_skip_an_image_application(): void
    {
        $this->make($this->imageInput());
        // The GitHub webhook and the poller find sites by repository: an image application has none, so a push cannot match it.
        $this->assertSame(0, DB::table('sites')->where('push_to_deploy', true)->count());
        $this->assertSame(0, DB::table('sites')->where('git_source', 'https')->where('push_to_deploy', true)->count());
        $this->artisan('sites:poll-git')->assertSuccessful();
        $this->assertSame(0, DB::table('operations')->where('kind', 'deploy')->count());
    }

    // ---- the Dockerfile deploy ------------------------------------------------------------------

    public function test_a_dockerfile_deploy_builds_starts_beside_checks_health_switches_nginx_then_removes_the_old_one(): void
    {
        $this->make($this->dockerfileInput(['env' => ['STRIPE_KEY' => 'sk_live_SECRET1']]));
        $this->recipes = [];
        $d = $this->deploy('shop');
        $this->assertSame('succeeded', $d['status'], $this->error($d['op']));

        $this->assertSame(['setup', 'fetch', 'build', 'start', 'health', 'switch', 'prune'], array_column(json_decode((string) DB::table('operations')->where('id', $d['op'])->value('stages'), true), 'name'));
        $order = $this->recipeOrder();
        $this->assertSame(['site-setup-shop', 'app-check-shop', 'fetch-shop', 'app-source-shop', 'app-build-shop', 'app-prepare-shop', 'app-run-shop', 'app-health-shop', 'web-server-shop'], $order, 'health passes before nginx is switched; the first deploy has no old container to remove');

        // the checkout is a plain clone (no Laravel shared folders), then the files are checked
        $this->assertSame(['clone'], array_keys($this->steps('fetch-shop')));
        $this->assertSame(['dockerfile', 'context'], array_keys($this->steps('app-source')));

        // docker build: BuildKit, the tag name:commit12, the Dockerfile and context in the release, labelled
        $build = $this->steps('app-build')['build']['args'];
        $this->assertStringContainsString('DOCKER_BUILDKIT=1 exec docker build --file "$df" "$@" "$cx"', $build[1]);
        // the paths are resolved and kept inside the release right before the build: a symlink may not lead out of the repository
        $this->assertStringContainsString('realpath -e', $build[1]);
        $this->assertStringContainsString('is outside the repository', $build[1]);
        $release = DB::table('sites')->where('name', 'shop')->value('current_release');
        $this->assertSame(["/home/deploy/shop/releases/{$release}", "/home/deploy/shop/releases/{$release}/Dockerfile", "/home/deploy/shop/releases/{$release}",
            '--tag', 'cm-app-shop:aaaaaaaaaaaa', '--label', 'cloud-manager.app=shop', '--label', 'cloud-manager.commit=aaaaaaaaaaaa'], array_slice($build, 3));
        $this->assertSame('/etc/cloud-manager/apps/shop.env', $this->steps('app-prepare')['env']['path']);

        // the new container: a free host port on 127.0.0.1, the shared confinement flags, the env file, the built image
        $run = $this->dockerRun();
        $this->assertSame(['run', '-d', '--name', 'cm-app-shop-23000', '--restart', 'unless-stopped', '--label', 'cloud-manager.app=shop', '--env-file', '/etc/cloud-manager/apps/shop.env',
            '--memory', '536870912', '--memory-swap', '536870912', '--pids-limit', '512', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges',
            '-p', '127.0.0.1:23000:3000', 'cm-app-shop:aaaaaaaaaaaa'], $run);
        $this->assertNotContains('--privileged', $run);
        $this->assertNotContains('--network', $run);
        $this->assertNotContains('--read-only', $run, 'off by default');
        $this->assertNotContains('-v', $run);

        // health: the container's own port, the check path
        $health = $this->steps('app-health')['health']['args'];
        $this->assertStringContainsString('http://127.0.0.1:23000/up', $health[1]);
        $this->assertSame('cm-app-shop-23000', end($health));

        // nginx now proxies to the new port, with websocket headers
        $vhost = base64_decode($this->steps('web-server')['vhost']['content_b64']);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:23000;', $vhost);
        $this->assertStringContainsString('proxy_set_header Upgrade $http_upgrade;', $vhost);
        $this->assertStringContainsString('proxy_set_header Connection "upgrade";', $vhost);

        $state = $this->state('shop');
        $this->assertSame([23000, null], [$state['host_port'], $state['pending_port']]);
        $this->assertSame(['cm-app-shop:aaaaaaaaaaaa'], array_column($state['images'], 'image'));
        $this->assertSame($release, $this->site('shop')->current_release);
        $this->assertNotNull(DB::table('audit_records')->where('action', 'site.deployed')->first());
    }

    public function test_the_second_deploy_starts_beside_the_old_one_and_removes_it_after_the_switch(): void
    {
        $this->make($this->dockerfileInput());
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $this->commit = self::COMMIT_B;
        $this->recipes = [];
        $d = $this->deploy('shop');
        $this->assertSame('succeeded', $d['status'], $this->error($d['op']));

        $this->assertSame('cm-app-shop-23001', $this->dockerRun()[3], 'the old container still holds 23000, so the new one gets the next free port');
        $order = $this->recipeOrder();
        $this->assertLessThan(array_search('web-server-shop', array_reverse($order, true), true), array_search('app-health-shop', $order, true));
        $discard = $this->steps('app-discard')['remove']['args'];
        $this->assertSame(['sh', 'cm-app-shop-23000'], array_slice($discard, 2), 'the old container is removed, after the switch');
        $names = $this->recipeNames();
        $this->assertGreaterThan(array_search('web-server-shop', array_reverse($names, true), true), array_search('app-discard-shop', $names, true));
        $this->assertSame(23001, $this->state('shop')['host_port']);
        $this->assertSame(['cm-app-shop:bbbbbbbbbbbb', 'cm-app-shop:aaaaaaaaaaaa'], array_column($this->state('shop')['images'], 'image'));
        // the third deploy goes back to the freed port
        $this->commit = 'cccccccccccc3333333333333333333333333333';
        $this->recipes = [];
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $this->assertSame('cm-app-shop-23000', $this->dockerRun()[3]);
    }

    public function test_images_beyond_the_live_one_and_the_two_before_it_are_pruned_and_never_the_live_one(): void
    {
        $this->make($this->dockerfileInput());
        $commits = ['aaaaaaaaaaaa', 'bbbbbbbbbbbb', 'cccccccccccc', 'dddddddddddd'];
        foreach ($commits as $i => $c) {
            $this->commit = $c.str_repeat('0', 28);
            $this->recipes = [];
            $this->assertSame('succeeded', $this->deploy('shop')['status']);
            $this->assertSame($i < 3, $this->steps('app-prune') === [], $i < 3 ? 'nothing to prune yet' : 'the oldest goes');
        }
        $images = array_column($this->state('shop')['images'], 'image');
        $this->assertSame(['cm-app-shop:dddddddddddd', 'cm-app-shop:cccccccccccc', 'cm-app-shop:bbbbbbbbbbbb'], $images);
        $prune = $this->steps('app-prune')['prune']['args'];
        $this->assertSame(['sh', 'sha256:'.hash('sha256', 'cm-app-shop:aaaaaaaaaaaa')], array_slice($prune, 2));
        $this->assertNotContains('sha256:'.hash('sha256', 'cm-app-shop:dddddddddddd'), $prune, 'the running image is never removed');
    }

    public function test_a_build_failure_leaves_the_old_container_serving_and_starts_nothing(): void
    {
        $this->make($this->dockerfileInput());
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $before = $this->state('shop');
        $this->commit = self::COMMIT_B;
        $this->recipes = [];
        $this->failSteps = ['build' => 'the Dockerfile has an error on line 4'];
        $d = $this->deploy('shop');

        $this->assertSame('failed', $d['status']);
        $this->assertStringContainsString('the live release is unchanged', $this->error($d['op']));
        $this->assertStringContainsString('line 4', $this->error($d['op']));
        $this->assertNotContains('app-run-shop', $this->recipeNames());
        $this->assertNotContains('web-server-shop', $this->recipeNames(), 'nginx was never touched');
        $this->assertNotContains('app-discard-shop', $this->recipeNames(), 'the live container was not stopped');
        $this->assertSame([$before['host_port'], $before['images']], [$this->state('shop')['host_port'], $this->state('shop')['images']]);
        $this->assertNotNull(DB::table('audit_records')->where('action', 'site.deploy_failed')->first());
    }

    public function test_a_failed_health_check_removes_the_new_container_and_the_old_one_keeps_serving(): void
    {
        $this->make($this->dockerfileInput());
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $this->commit = self::COMMIT_B;
        $this->recipes = [];
        $this->failSteps = ['health' => 'no answer on 127.0.0.1:23001 in 60 seconds'];
        $d = $this->deploy('shop');

        $this->assertSame('failed', $d['status']);
        $this->assertStringContainsString('the live release is unchanged', $this->error($d['op']));
        $this->assertNotContains('web-server-shop', $this->recipeNames(), 'nginx was not switched');
        $removed = array_values(array_filter($this->allSteps('app-discard'), fn ($s) => $s['id'] === 'remove'));
        $this->assertSame(['cm-app-shop-23001'], array_map(fn ($s) => end($s['args']), $removed), 'only the new container is removed');
        $state = $this->state('shop');
        $this->assertSame([23000, null], [$state['host_port'], $state['pending_port']], 'the live port is unchanged and the pending one is given back');
        $this->assertSame(['cm-app-shop:aaaaaaaaaaaa'], array_column($state['images'], 'image'));
    }

    public function test_an_nginx_refusal_at_the_switch_puts_everything_back(): void
    {
        $this->make($this->dockerfileInput());
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $this->commit = self::COMMIT_B;
        $this->recipes = [];
        $this->failSteps = ['nginx-test' => 'nginx: configuration file test failed'];
        // the setup recipe also contains nginx-test, so it fails before anything starts: that is also a clean failure
        $d = $this->deploy('shop');
        $this->assertSame('failed', $d['status']);
        $this->assertSame([23000, null], [$this->state('shop')['host_port'], $this->state('shop')['pending_port']]);
    }

    public function test_the_switch_failing_after_a_healthy_start_keeps_the_old_port_and_removes_the_new_container(): void
    {
        $this->make($this->dockerfileInput());
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $this->commit = self::COMMIT_B;
        $this->recipes = [];
        // fail only the second nginx-test: setup passes, the switch's own vhost recipe fails
        $seen = 0;
        $this->failSteps = [];
        $http = new Factory;
        $http->fake(function (Request $r) use ($http, &$seen) {
            if ($r->method() === 'GET') {
                return $http->response(['connected' => true]);
            }
            if ($r['kind'] === 'exec') {
                return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $this->execFor($r['args']['name'], $r['args']['args'] ?? [])]]);
            }
            $spec = $r['args']['spec'] ?? null;
            if ($spec !== null) {
                $this->recipes[] = ['node' => 'x', 'name' => $spec['name'], 'steps' => $spec['steps']];
                if ($spec['name'] === 'web-server-shop' && ++$seen >= 1) {
                    return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'nginx: configuration file test failed']);
                }
            }

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog));
        $this->app->forgetInstance(OperationRunner::class);
        $this->app->forgetInstance(Deployer::class);
        $d = $this->deploy('shop');

        $this->assertSame('failed', $d['status']);
        $this->assertStringContainsString('nginx did not accept the switch', $this->error($d['op']));
        $state = $this->state('shop');
        $this->assertSame([23000, null], [$state['host_port'], $state['pending_port']], 'the state names the old port again, so a later vhost write is right');
        $removed = array_map(fn ($s) => end($s['args']), array_values(array_filter($this->allSteps('app-discard'), fn ($s) => $s['id'] === 'remove')));
        $this->assertSame(['cm-app-shop-23001'], $removed);
    }

    public function test_secrets_are_only_in_the_env_file(): void
    {
        $this->make($this->dockerfileInput(['env' => ['STRIPE_KEY' => 'sk_live_SECRET1', 'GREETING' => 'hello']]));
        app(Sites::class)->setEnv($this->site('shop'), "STRIPE_KEY=\"sk_live_SECRET1\"\nGREETING='hello'\n# a comment\nexport OTHER=plain\n");
        $this->recipes = [];
        $this->assertSame('succeeded', $this->deploy('shop')['status']);

        $env = $this->steps('app-prepare')['env'];
        $this->assertTrue($env['sensitive']);
        $this->assertSame('0600', $env['mode']);
        $file = base64_decode($env['content_b64']);
        $this->assertStringContainsString("STRIPE_KEY=sk_live_SECRET1\n", $file, 'quotes are taken off for docker --env-file');
        $this->assertStringContainsString("GREETING=hello\n", $file);
        $this->assertStringContainsString("OTHER=plain\n", $file);
        // nowhere else: not an argument, a step id, a path, a description; not the operation, its log or the audit trail
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $s) {
                if ($s['id'] === 'env' && $r['name'] === 'app-prepare-shop') {
                    continue;
                }
                $this->assertStringNotContainsString('sk_live_SECRET1', json_encode($s), $r['name'].'/'.$s['id']);
            }
        }
        $this->assertStringNotContainsString('sk_live_SECRET1', json_encode(DB::table('operations')->get()).json_encode(DB::table('audit_records')->get()));
        $this->assertNotContains('/home/deploy/shop/shared/.env', array_column($this->steps('site-setup'), 'path'), 'no .env in the deploy user\'s home');
    }

    public function test_a_variable_with_a_line_break_in_it_cannot_reach_docker_and_the_deploy_says_so(): void
    {
        $this->make($this->dockerfileInput());
        app(Sites::class)->setEnv($this->site('shop'), "KEY=\"line one\nline two\"\n");
        $d = $this->deploy('shop');

        $this->assertSame('failed', $d['status']);
        $this->assertStringContainsString('starts a quote that does not end on the same line', $this->error($d['op']));
    }

    public function test_the_variables_page_values_include_what_cloud_manager_injects_for_an_attached_database(): void
    {
        $this->make($this->dockerfileInput());
        app(Sites::class)->setInjected($this->site('shop'), ['DB_HOST' => 'db.internal', 'DB_PASSWORD' => 'pw-injected-1'], 'Attached database');
        $this->recipes = [];
        $this->assertSame('succeeded', $this->deploy('shop')['status']);

        $file = base64_decode($this->steps('app-prepare')['env']['content_b64']);
        $this->assertStringContainsString("DB_HOST=db.internal\n", $file);
        $this->assertStringContainsString("DB_PASSWORD=pw-injected-1\n", $file);
    }

    public function test_the_read_only_option_and_the_memory_limit_and_named_volumes_follow_the_settings(): void
    {
        $this->make($this->dockerfileInput(['read_only' => true, 'memory' => '1GB', 'volumes' => "/data\n/var/uploads"]));
        $this->recipes = [];
        $this->assertSame('succeeded', $this->deploy('shop')['status']);

        $run = $this->dockerRun();
        $this->assertContains('--read-only', $run);
        $this->assertSame(['--tmpfs', '/tmp:rw,noexec,nosuid,size=64m'], array_slice($run, array_search('--tmpfs', $run, true), 2));
        $this->assertSame('1073741824', $run[array_search('--memory', $run, true) + 1]);
        $this->assertSame(['-v', 'cm-app-shop-data0:/data', '-v', 'cm-app-shop-data1:/var/uploads'], array_slice($run, array_search('-v', $run, true), 4));
        $volumes = $this->steps('app-prepare');
        $this->assertSame(['volume', 'create', '--label', 'cloud-manager.app=shop', 'cm-app-shop-data0'], $volumes['volume-0']['args']);
        $mounts = [];
        foreach ($run as $i => $arg) {
            if ($arg === '-v') {
                $mounts[] = $run[$i + 1];
            }
        }
        foreach ($mounts as $m) {
            $this->assertStringStartsWith('cm-app-shop-data', $m, 'only named volumes, never a host path');
        }
        $this->assertNotContains('/var/run/docker.sock', $run);
    }

    public function test_services_and_applications_use_one_confinement_list(): void
    {
        $flags = ContainerRecipes::confinement('536870912', null, false, null);
        $this->assertSame(['--memory', '536870912', '--memory-swap', '536870912', '--pids-limit', '512', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges'], $flags);
        $app = AppRecipes::runArguments(['name' => 'a', 'container' => 'cm-app-a-23000', 'image' => 'x@sha256:1', 'env_file' => '/e', 'memory' => '536870912', 'read_only' => false, 'volumes' => [], 'host_port' => 23000, 'container_port' => 80]);
        $service = ContainerRecipes::runArguments(['name' => 's', 'container' => 'cm-svc-s', 'image' => 'x@sha256:1', 'env_file' => '/e', 'limits' => ['memory' => '512MB'], 'read_only' => false, 'run_as' => null, 'volumes' => [], 'host_ports' => [80 => 23001]]);
        $this->assertSame($flags, array_slice($app, 10, 10));
        $this->assertSame($flags, array_slice($service, 10, 10));
    }

    public function test_an_unprepared_server_is_refused_at_deploy_time_too(): void
    {
        $this->make($this->dockerfileInput());
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['containers_ready_at' => null]);
        $d = $this->deploy('shop');

        $this->assertSame('failed', $d['status']);
        $this->assertStringContainsString('not prepared for containers', $this->error($d['op']));
        $this->assertSame([], $this->recipes);
    }

    // ---- rollback ------------------------------------------------------------------------------

    public function test_rollback_restarts_the_previous_image_on_a_new_port_and_switches_nginx_back(): void
    {
        $this->make($this->dockerfileInput());
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $releaseA = $this->site('shop')->current_release;
        $this->commit = self::COMMIT_B;
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $this->recipes = [];

        $op = $this->runner()->recordRollback((int) $this->site('shop')->id, 'console', 'm@example.com');
        $this->assertSame(['rollback'], array_column(json_decode((string) DB::table('operations')->where('id', $op)->value('stages'), true), 'name'));
        $this->assertSame('succeeded', $this->operate($op), $this->error($op));

        $this->assertSame(['app-prepare-shop', 'app-run-shop', 'app-health-shop', 'web-server-shop', 'app-discard-shop'], $this->recipeOrder());
        $run = $this->dockerRun();
        $this->assertSame(['cm-app-shop-23000', 'cm-app-shop:aaaaaaaaaaaa'], [$run[3], end($run)], 'the previous image, on the port the old container freed');
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:23000;', base64_decode($this->steps('web-server')['vhost']['content_b64']));
        $this->assertSame('cm-app-shop-23001', end($this->steps('app-discard')['remove']['args']), 'the container that was live is removed');
        $state = $this->state('shop');
        $this->assertSame(23000, $state['host_port']);
        $this->assertSame(['cm-app-shop:aaaaaaaaaaaa', 'cm-app-shop:bbbbbbbbbbbb'], array_column($state['images'], 'image'), 'the two swap places, so a second rollback goes forward again');
        $this->assertSame($releaseA, $this->site('shop')->current_release);
        $this->assertNotNull(DB::table('audit_records')->where('action', 'site.rolled_back')->first());
    }

    public function test_rollback_with_nothing_kept_or_a_pruned_image_fails_clearly_and_changes_nothing(): void
    {
        $this->make($this->dockerfileInput());
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $op = $this->runner()->recordRollback((int) $this->site('shop')->id, 'console', 'm@example.com');
        $this->assertSame('failed', $this->operate($op));
        $this->assertStringContainsString('has no previous version kept', $this->error($op));

        $this->commit = self::COMMIT_B;
        $this->assertSame('succeeded', $this->deploy('shop')['status']);
        $this->execOutput = fn ($n, $a) => $n === 'docker' && ($a[1] ?? '') === 'inspect' ? throw new RuntimeException('No such image') : 'x';
        $this->recipes = [];
        $op = $this->runner()->recordRollback((int) $this->site('shop')->id, 'console', 'm@example.com');
        $this->assertSame('failed', $this->operate($op));
        $this->assertSame([], $this->recipes, 'nothing was started');
        $this->assertSame(23001, $this->state('shop')['host_port']);
    }

    // ---- image applications ---------------------------------------------------------------------

    public function test_the_first_deploy_pulls_pins_the_digest_and_runs_by_digest(): void
    {
        $this->make($this->imageInput());
        $this->recipes = [];
        $d = $this->deploy('tool');
        $this->assertSame('succeeded', $d['status'], $this->error($d['op']));

        $this->assertSame(['setup', 'pull', 'start', 'health', 'switch', 'prune'], array_column(json_decode((string) DB::table('operations')->where('id', $d['op'])->value('stages'), true), 'name'));
        $this->assertSame(self::DIGEST_1, $this->state('tool')['digest']);
        $pull = $this->steps('app-pull')['pull']['args'];
        $this->assertSame(['sh', 'ghcr.io/acme/tool:2.1'], [$pull[2], end($pull)]);
        $run = $this->dockerRun();
        $this->assertSame('ghcr.io/acme/tool@'.self::DIGEST_1, end($run), 'run by digest, not by the tag');
        $this->assertSame(['-p', '127.0.0.1:23000:8080'], array_slice($run, -3, 2));
        $this->assertNotContains('app-build-tool', $this->recipeNames());
        // a second deploy runs the pinned digest even if the tag has moved: no pull, no change
        $this->tagDigest = self::DIGEST_2;
        $this->recipes = [];
        $this->assertSame('succeeded', $this->deploy('tool')['status']);
        $this->assertNotContains('app-pull-tool', $this->recipeNames(), 'the pinned image is already on the server');
        $run = $this->dockerRun();
        $this->assertSame('ghcr.io/acme/tool@'.self::DIGEST_1, end($run));
        $this->assertSame(self::DIGEST_1, $this->state('tool')['digest']);
    }

    public function test_a_digest_given_in_the_reference_is_verified_on_the_pull(): void
    {
        $ref = 'ghcr.io/acme/tool@'.self::DIGEST_2;
        $this->make($this->imageInput(['image' => $ref]));
        $this->assertSame(self::DIGEST_2, $this->state('tool')['digest']);
        $first = true;
        $this->execOutput = function ($n, $a) use (&$first) {
            if ($n === 'docker' && str_contains((string) ($a[3] ?? ''), 'RepoDigests')) {
                return 'ghcr.io/acme/tool@'.self::DIGEST_1."\n";
            }
            if ($n === 'docker' && ($a[3] ?? '') === '{{.Id}}' && $first) {
                $first = false;
                throw new RuntimeException('No such image: not pulled yet');
            }

            return 'sha256:id';
        };
        $this->recipes = [];
        $d = $this->deploy('tool');
        $this->assertSame('failed', $d['status']);
        $this->assertContains('app-pull-tool', $this->recipeNames(), 'the pinned digest was not on the server, so it was pulled by that digest');
        $this->assertStringContainsString('The registry answered '.self::DIGEST_1.' for the pinned digest', $this->error($d['op']));
        $this->assertNotContains('app-run-tool', $this->recipeNames());
    }

    public function test_a_tag_that_cannot_be_pulled_or_has_no_digest_fails_clearly(): void
    {
        $this->make($this->imageInput());
        $this->failSteps = ['pull' => 'manifest unknown: ghcr.io/acme/tool:2.1 not found'];
        $d = $this->deploy('tool');
        $this->assertSame('failed', $d['status']);
        $this->assertStringContainsString('manifest unknown', $this->error($d['op']));
        $this->assertNull($this->state('tool')['digest'], 'nothing was pinned');

        $this->failSteps = [];
        $this->execOutput = fn ($n, $a) => $n === 'docker' && str_contains((string) ($a[3] ?? ''), 'RepoDigests') ? "\n" : 'sha256:id';
        $d = $this->deploy('tool');
        $this->assertSame('failed', $d['status']);
        $this->assertStringContainsString('reports no digest for it, so it cannot be pinned', $this->error($d['op']));
        $this->assertNull($this->state('tool')['digest']);
    }

    public function test_a_private_registry_login_uses_a_per_app_config_dir_a_token_file_and_removes_both(): void
    {
        $this->make($this->imageInput(['registry_user' => 'bot', 'registry_token' => self::TOKEN]));
        $this->recipes = [];
        $d = $this->deploy('tool');
        $this->assertSame('succeeded', $d['status'], $this->error($d['op']));

        $names = $this->recipeNames();
        $this->assertSame(['app-login-tool', 'app-pull-tool', 'app-logout-tool'], array_values(array_filter($names, fn ($n) => preg_match('/^app-(login|pull|logout)/', $n) === 1)));
        $login = $this->steps('app-login');
        $this->assertSame(['dir-base', 'dir-config', 'token', 'login'], array_keys($login));
        $this->assertSame(['/etc/cloud-manager/docker-auth', '0700'], [$login['dir-base']['path'], $login['dir-base']['mode']]);
        $this->assertSame(['/etc/cloud-manager/docker-auth/tool', '0700'], [$login['dir-config']['path'], $login['dir-config']['mode']]);
        $this->assertSame(['/etc/cloud-manager/docker-auth/tool.token', '0600', true, self::TOKEN], [$login['token']['path'], $login['token']['mode'], $login['token']['sensitive'], base64_decode($login['token']['content_b64'])]);
        $this->assertSame('DOCKER_CONFIG="$1" exec docker login --username "$2" --password-stdin "$3" < "$4"', $login['login']['args'][1]);
        $this->assertSame(['/etc/cloud-manager/docker-auth/tool', 'bot', 'ghcr.io', '/etc/cloud-manager/docker-auth/tool.token'], array_slice($login['login']['args'], 3));
        // the pull uses that config dir
        $this->assertSame('/etc/cloud-manager/docker-auth/tool', $this->steps('app-pull')['pull']['args'][3]);
        // and both are removed afterwards
        $this->assertSame([['absent', '/etc/cloud-manager/docker-auth/tool.token'], ['absent', '/etc/cloud-manager/docker-auth/tool']],
            array_map(fn ($s) => [$s['type'], $s['path']], array_values($this->steps('app-logout'))));
        // the token appears only as the content of that one file step: no argument, no description, no path
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $s) {
                $copy = $s;
                if ($r['name'] === 'app-login-tool' && $s['id'] === 'token') {
                    unset($copy['content_b64']);
                }
                $this->assertStringNotContainsString(self::TOKEN, json_encode($copy), $r['name'].'/'.$s['id']);
            }
        }
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('operations')->get()).json_encode(DB::table('audit_records')->get()).json_encode(DB::table('sites')->get()));
        $this->assertStringNotContainsString(self::TOKEN, (string) DB::table('operations')->where('id', $d['op'])->value('log'));
        $this->assertStringNotContainsString('--password ', json_encode($this->recipes));
    }

    public function test_a_failed_pull_still_removes_the_config_dir_and_token_and_hides_the_token(): void
    {
        $this->make($this->imageInput(['registry_user' => 'bot', 'registry_token' => self::TOKEN]));
        $this->failSteps = ['pull' => 'unauthorized: bad credentials '.self::TOKEN];
        $this->recipes = [];
        $d = $this->deploy('tool');

        $this->assertSame('failed', $d['status']);
        $this->assertContains('app-logout-tool', $this->recipeNames(), 'removed even though the pull failed');
        $this->assertStringContainsString('unauthorized', $this->error($d['op']));
        $this->assertStringNotContainsString(self::TOKEN, $this->error($d['op']));
        // The gateway audits a failed command with what the server said; the control plane's own records never carry the token.
        foreach (DB::table('audit_records')->where('resource_type', 'site')->get() as $a) {
            $this->assertStringNotContainsString(self::TOKEN, json_encode($a), $a->action);
        }
    }

    public function test_update_shows_old_and_new_digest_and_runs_only_what_was_confirmed(): void
    {
        $this->make($this->imageInput());
        $this->assertSame('succeeded', $this->deploy('tool')['status']);
        $site = $this->site('tool');

        $this->tagDigest = self::DIGEST_2;
        $this->recipes = [];
        $found = $this->apps()->checkUpdate($site, 'm@example.com');
        $this->assertSame(['ghcr.io/acme/tool:2.1', self::DIGEST_1, self::DIGEST_2, true], [$found['ref'], $found['old'], $found['new'], $found['changed']]);
        $this->assertSame(self::DIGEST_1, $this->state('tool')['digest'], 'checking changes nothing it runs');
        $this->assertSame(self::DIGEST_2, $this->state('tool')['candidate']['digest']);
        $this->assertSame(['app-pull-tool'], $this->recipeNames());

        // confirmation must be the digest that was shown
        try {
            $this->apps()->update($this->site('tool'), self::DIGEST_1, 'm@example.com', false);
            $this->fail('wrong digest');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not the digest that was shown', $e->getMessage());
        }
        $op = $this->apps()->update($this->site('tool'), self::DIGEST_2, 'm@example.com', false);
        $this->recipes = [];
        $this->assertSame('succeeded', $this->operate($op), $this->error($op));
        $run = $this->dockerRun();
        $this->assertSame('ghcr.io/acme/tool@'.self::DIGEST_2, end($run));
        $state = $this->state('tool');
        $this->assertSame([self::DIGEST_2, null, null], [$state['digest'], $state['pending_digest'], $state['candidate']]);
        $this->assertSame(['ghcr.io/acme/tool@'.self::DIGEST_2, 'ghcr.io/acme/tool@'.self::DIGEST_1], array_column($state['images'], 'image'));
        $audit = DB::table('audit_records')->whereIn('action', ['site.image_update_checked', 'site.image_update_confirmed'])->orderBy('id')->get();
        $this->assertCount(2, $audit);
        $this->assertSame(self::DIGEST_2, json_decode($audit[1]->metadata, true)['to']);
    }

    public function test_update_needs_a_check_first_and_has_nothing_to_do_when_the_tag_has_not_moved(): void
    {
        $this->make($this->imageInput());
        $this->assertSame('succeeded', $this->deploy('tool')['status']);
        $site = $this->site('tool');
        try {
            $this->apps()->update($site, self::DIGEST_2, 'm@example.com', false);
            $this->fail('no check yet');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Check for an update first', $e->getMessage());
        }
        $found = $this->apps()->checkUpdate($site, 'm@example.com');
        $this->assertFalse($found['changed']);
        $this->expectExceptionMessage('There is nothing to update');
        $this->apps()->update($this->site('tool'), self::DIGEST_1, 'm@example.com', false);
    }

    public function test_a_failed_update_keeps_the_old_digest_and_the_old_container(): void
    {
        $this->make($this->imageInput());
        $this->assertSame('succeeded', $this->deploy('tool')['status']);
        $this->tagDigest = self::DIGEST_2;
        $this->apps()->checkUpdate($this->site('tool'), 'm@example.com');
        $op = $this->apps()->update($this->site('tool'), self::DIGEST_2, 'm@example.com', false);
        $this->failSteps = ['health' => 'no answer'];
        $this->assertSame('failed', $this->operate($op));

        $state = $this->state('tool');
        $this->assertSame([self::DIGEST_1, null, 23000], [$state['digest'], $state['pending_digest'], $state['host_port']]);
    }

    public function test_an_unresolvable_tag_fails_the_check_with_the_registry_message(): void
    {
        $this->make($this->imageInput());
        $this->assertSame('succeeded', $this->deploy('tool')['status']);
        $this->failSteps = ['pull' => 'manifest unknown'];
        $this->expectExceptionMessage('manifest unknown');
        $this->apps()->checkUpdate($this->site('tool'), 'm@example.com');
    }

    public function test_the_registry_login_can_be_changed_and_removed_and_the_token_never_comes_back(): void
    {
        $this->make($this->imageInput());
        $site = $this->site('tool');
        $this->apps()->setRegistry($site, 'bot', self::TOKEN, 'm@example.com');
        $this->assertEqualsCanonicalizing(['ghcr.io', 'bot'], array_values($this->state('tool')['registry']));
        $this->assertSame(self::TOKEN, app(Vault::class)->get('org-1', ContainerApps::registrySecret($site)));
        // a new user with no new token keeps the stored one
        $this->apps()->setRegistry($this->site('tool'), 'bot2', '', 'm@example.com');
        $this->assertSame('bot2', $this->state('tool')['registry']['user']);
        $this->assertSame(self::TOKEN, app(Vault::class)->get('org-1', ContainerApps::registrySecret($site)));
        $this->assertStringNotContainsString(self::TOKEN, json_encode(DB::table('audit_records')->get()));
        $this->apps()->setRegistry($this->site('tool'), '', null, 'm@example.com');
        $this->assertNull($this->state('tool')['registry']);
        $this->assertNull(app(Vault::class)->get('org-1', ContainerApps::registrySecret($site)));
        $this->expectExceptionMessage('Only an application from a Docker image has a registry login.');
        $this->make($this->dockerfileInput());
        $this->apps()->setRegistry($this->site('shop'), 'bot', 'x', 'm@example.com');
    }

    // ---- scope refusals ----------------------------------------------------------------------------

    public function test_a_container_application_refuses_what_it_does_not_support_with_a_plain_message(): void
    {
        $this->make($this->dockerfileInput());
        $site = $this->site('shop');
        $sites = app(Sites::class);
        $refused = [
            'a scheduler' => fn () => $sites->setScheduler($site, true),
            'queue workers' => fn () => $sites->setWorkers($site, 2),
            'background processes' => fn () => $sites->setDaemon($site, 'ws', 'node ws.js'),
            'Reverb' => fn () => $sites->enableReverb($site),
            'a server script' => fn () => $sites->saveServerScript($site, 'echo hi', 'm@example.com'),
            'a different type' => fn () => $sites->setRuntime($site, 'node'),
            'previews' => fn () => $this->runner()->queuePreviewCreate((int) $site->id, 'feature', false, 7, 'console', 'm@example.com'),
            'a load balancer' => fn () => $this->runner()->queueLoadBalancer((int) $site->id, 'console', 'm@example.com'),
            'an autoscaling pool' => fn () => $this->runner()->queuePoolCreate((int) $site->id, ['min' => 1, 'max' => 2, 'size' => 's', 'cpu' => 0.7], 'console', 'm@example.com'),
            'a second server' => fn () => $this->runner()->queueServerAdd((int) $site->id, 'web-2.org-1', 'console', 'm@example.com'),
            'the scheduler operation' => fn () => $this->runner()->queueScheduler((int) $site->id, 'console', 'm@example.com'),
        ];
        foreach ($refused as $what => $do) {
            try {
                $do();
                $this->fail("{$what} was not refused");
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('An application from a Dockerfile cannot have', $e->getMessage(), $what);
                $this->assertStringContainsString('in this version', $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('operations')->whereIn('kind', ['scheduler', 'load_balancer', 'pool_create', 'server_add', 'preview_create'])->count());
        // turning the scheduler off is not a feature, so it is not refused
        $this->assertFalse((bool) $sites->setScheduler($site, false)->scheduler);
    }

    // ---- ports --------------------------------------------------------------------------------------

    public function test_host_ports_are_unique_across_services_applications_and_background_processes(): void
    {
        $this->make($this->dockerfileInput());
        $this->assertSame('succeeded', $this->deploy('shop')['status']);          // 23000
        $kuma = $this->install('uptime-kuma', ['name' => 'kuma', 'host' => 'kuma.example.com']);
        $this->assertSame('succeeded', $kuma['status']);
        $kumaPort = (int) DB::table('service_endpoints')->value('port');
        $this->assertSame(23001, $kumaPort, 'the service skips the application\'s port');

        $this->make($this->imageInput());
        $this->assertSame('succeeded', $this->deploy('tool')['status']);
        $this->assertSame(23002, $this->state('tool')['host_port']);
        // used ports as the next allocation sees them
        $used = $this->services()->usedPorts('web-1.org-1');
        $this->assertSame('the application shop', $used[23000]);
        $this->assertSame('the service kuma', $used[23001]);
        $this->assertSame('the application tool', $used[23002]);
        // a deploy's pending port is held until it is given back
        ContainerApps::merge((int) $this->site('tool')->id, ['pending_port' => 23003]);
        $this->assertSame(23004, $this->services()->reserveHostPort('web-1.org-1', fn (int $p) => null));
        ContainerApps::merge((int) $this->site('tool')->id, ['pending_port' => null]);
        $this->assertSame(23003, $this->services()->reserveHostPort('web-1.org-1', fn (int $p) => null));
    }

    public function test_a_background_process_port_is_not_given_to_a_container(): void
    {
        $php = app(Sites::class)->create('org-1', 'web-1.org-1', 'blog', 'blog.example.com', 'git@github.com:acme/blog.git');
        DB::table('sites')->where('id', $php->id)->update(['daemons' => json_encode([['name' => 'ws', 'command' => 'php ws.php', 'processes' => 1, 'port' => 23000]])]);
        $this->assertSame('a background process of blog', $this->services()->usedPorts('web-1.org-1')[23000] ?? null);
        $this->assertSame(23001, $this->services()->reserveHostPort('web-1.org-1', fn (int $p) => null));
    }

    public function test_applications_cannot_be_given_a_port_in_the_reserved_band(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Runtimes::validate('node', ['port' => 23001]);
    }

    // ---- the vhost ------------------------------------------------------------------------------------

    public function test_the_vhost_before_a_first_deploy_answers_503_and_after_it_proxies_to_the_current_host_port(): void
    {
        $this->make($this->dockerfileInput());
        $site = $this->site('shop');
        $conf = app(Sites::class)->generatedVhost($site);
        $this->assertStringContainsString('return 503;', $conf);
        $this->assertStringContainsString('server_name shop.example.com;', $conf);
        $this->assertStringNotContainsString('fastcgi', $conf);

        ContainerApps::merge((int) $site->id, ['host_port' => 23456]);
        $conf = app(Sites::class)->generatedVhost($this->site('shop'));
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:23456;', $conf);
        $this->assertStringContainsString('proxy_set_header Host $host;', $conf);
        $this->assertStringContainsString('proxy_read_timeout 300s;', $conf);
        $this->assertStringContainsString('/.well-known/acme-challenge/', $conf, 'the certificate challenge still works');
        $this->assertStringNotContainsString('return 503', $conf);
    }

    public function test_domain_https_password_protection_and_certificate_control_keep_working_on_a_proxy_vhost(): void
    {
        $this->make($this->imageInput());
        $this->assertSame('succeeded', $this->deploy('tool')['status']);
        $site = $this->site('tool');

        // HTTPS: the existing operation, certbot, then a vhost that redirects 80 and proxies 443 to the same host port
        $this->recipes = [];
        $op = $this->runner()->queueHttps((int) $site->id, 'console', 'm@example.com');
        $this->assertSame('succeeded', $this->operate($op), $this->error($op));
        $this->assertTrue((bool) $this->site('tool')->tls);
        $conf = app(Sites::class)->generatedVhost($this->site('tool'));
        $this->assertStringContainsString('return 301 https://$host$request_uri;', $conf);
        $this->assertStringContainsString('ssl_certificate /etc/letsencrypt/live/tool.example.com/fullchain.pem;', $conf);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:23000;', $conf);
        $this->assertStringContainsString('Strict-Transport-Security', $conf);

        // password protection and the request-size setting are in the same server block
        app(Sites::class)->setBasicAuth($this->site('tool'), 'me', 'a-long-password-1');
        $conf = app(Sites::class)->generatedVhost($this->site('tool'));
        $this->assertStringContainsString('auth_basic "Restricted";', $conf);
        $this->assertStringContainsString('client_max_body_size 64m;', $conf);
        // the challenge location is outside the password
        $this->assertMatchesRegularExpression('#acme-challenge/ \{\s+auth_basic off;#', $conf);

        // a domain move rewrites the vhost for the new name and keeps proxying to the live port
        $moved = app(Sites::class)->changeDomain($this->site('tool'), 'tool2.example.com');
        $this->assertSame('tool2.example.com', $moved->domain);
        $this->assertStringContainsString('server_name tool2.example.com;', app(Sites::class)->generatedVhost($moved));
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:23000;', app(Sites::class)->generatedVhost($moved));
    }

    public function test_the_setup_recipe_of_an_image_application_has_no_git_key_and_no_env_file_in_the_deploy_home(): void
    {
        $this->make($this->imageInput());
        $ids = array_column(app(Sites::class)->setupRecipe($this->site('tool'))['steps'], 'id');
        foreach (['deploy-key', 'known-hosts', 'env'] as $gone) {
            $this->assertNotContains($gone, $ids);
        }
        $this->assertContains('vhost', $ids);
        $this->make($this->dockerfileInput());
        $ids = array_column(app(Sites::class)->setupRecipe($this->site('shop'))['steps'], 'id');
        $this->assertNotContains('env', $ids);
        $this->assertNotContains('deploy-key', $ids, 'a public https repository is cloned with no key');
    }

    // ---- removal --------------------------------------------------------------------------------------

    public function test_removing_an_application_takes_its_containers_env_file_and_registry_login_off_the_server(): void
    {
        $this->make($this->imageInput(['registry_user' => 'bot', 'registry_token' => self::TOKEN]));
        $this->assertSame('succeeded', $this->deploy('tool')['status']);
        $site = $this->site('tool');
        $this->recipes = [];
        $recipe = app(Sites::class)->removalRecipe($site, false);
        $steps = array_column($recipe['steps'], null, 'id');
        $this->assertStringContainsString("docker ps -aq --filter 'label=cloud-manager.app=tool' | xargs -r docker rm -f", $steps['containers']['args'][1]);
        $this->assertSame('/etc/cloud-manager/apps/tool.env', $steps['app-env']['path']);
        $this->assertSame('/etc/cloud-manager/docker-auth/tool', $steps['app-auth-dir']['path']);
        $this->assertArrayHasKey('volumes', $steps);
        $this->assertSame(['sh', ...ContainerApps::imageIds($site)], array_slice($steps['pulled-images']['args'], 2));
        $kept = array_column(app(Sites::class)->removalRecipe($site, true)['steps'], null, 'id');
        $this->assertArrayNotHasKey('volumes', $kept, 'the data is kept when the data is kept');
        $this->assertArrayHasKey('containers', $kept);

        app(Sites::class)->forget($site);
        $this->assertNull(app(Vault::class)->get('org-1', ContainerApps::registrySecret($site)));
    }
}
