<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Services\Services;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/** A control plane with the gateway faked: every recipe sent to a server is recorded, and one step can be made to fail. */
abstract class ServiceTestCase extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{node: string, name: string, steps: list<array<string, mixed>>}> */
    protected array $recipes = [];

    /** step id => error: a recipe containing that step fails with it */
    protected array $failSteps = [];

    protected bool $connected = true;

    /** @var list<array{node: string, name: string, args: list<string>}> every `exec` sent to a server */
    protected array $execs = [];

    /** @var (\Closure(string, array<int, string>): string)|null what an exec prints */
    protected ?\Closure $execOutput = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        $this->node('web-1.org-1', 'org-1');

        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            if ($r->method() === 'GET') {
                return $http->response(['connected' => $this->connected]);
            }
            $node = (string) preg_replace('#.*/nodes/([^/]+)/commands.*#', '$1', $r->url());
            if ($r['kind'] === 'exec') {
                $this->execs[] = ['node' => $node, 'name' => $r['args']['name'], 'args' => $r['args']['args'] ?? []];

                return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $this->execOutput ? ($this->execOutput)($r['args']['name'], $r['args']['args'] ?? []) : '']]);
            }
            $spec = $r['args']['spec'] ?? null;
            if ($spec !== null) {
                $this->recipes[] = ['node' => $node, 'name' => $spec['name'], 'steps' => $spec['steps']];
                foreach ($spec['steps'] as $step) {
                    if (isset($this->failSteps[$step['id']])) {
                        return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => $this->failSteps[$step['id']]]);
                    }
                }
            }

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => '']]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
        $this->app->forgetInstance(OperationRunner::class);
        $this->app->forgetInstance(Services::class);
        $this->app->forgetInstance(\App\Lib\Services\ServiceRunner::class);
    }

    protected ?string $templateDir = null;

    protected function tearDown(): void
    {
        if ($this->templateDir !== null) {
            array_map('unlink', glob($this->templateDir.'/*') ?: []);
            @rmdir($this->templateDir);
        }
        parent::tearDown();
    }

    public const RUSTFS_SHA = '3d9d42bb4cb7a1589266090d4f9f8238465506cdd9d5e897193377dd6f610aac';

    public const KUMA_DIGEST = '7554dba313c84e44fee921ef3b5626ba1c711e412e82815e8c957517026112a9';

    /**
     * Makes a (newer) version of a built-in template the one the catalogue offers: the built-in source is pointed at a copy
     * of the file with its version changed and the given text replaced. Every replacement must change something.
     *
     * @param  array<string, string>  $replace
     */
    protected function publish(string $template, int $version, array $replace = []): void
    {
        $this->templateDir ??= sys_get_temp_dir().'/cm-tpl-'.uniqid();
        @mkdir($this->templateDir);
        $yaml = (string) preg_replace('/^version: \d+$/m', "version: {$version}", (string) file_get_contents(base_path("templates/{$template}.yml")));
        foreach ($replace as $from => $to) {
            $yaml = str_replace($from, $to, $yaml, $n);
            $this->assertGreaterThan(0, $n, "publish: {$from} is not in {$template}.yml");
        }
        file_put_contents($this->templateDir."/{$template}.yml", $yaml);
        config(['templates.builtin_path' => $this->templateDir]);
        foreach ([\App\Lib\Templates\Sources::class, Services::class, \App\Lib\Services\ServiceRunner::class, \App\Lib\Services\ServiceBackups::class, OperationRunner::class] as $c) {
            $this->app->forgetInstance($c);
        }
    }

    /** A newer RustFS: a different download and checksum, tagged by $n (2, 3, ...). @return array<string, string> */
    protected function rustfsRelease(int $n): array
    {
        return ['1.0.5' => "1.0.{$n}0", self::RUSTFS_SHA => str_repeat(dechex($n + 9), 64)];
    }

    protected function kumaDigest(int $n): string
    {
        return str_repeat(dechex($n + 9), 64);
    }

    protected function node(string $id, string $org, int $memGb = 4, int $diskGb = 100): void
    {
        DB::table('nodes')->insert(['node_id' => $id, 'organization_id' => $org, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('node_metrics')->insert(['organization_id' => $org, 'node_id' => $id, 'collected_at' => now()->subMinute(),
            'cpu' => 1, 'load_1' => 0.1, 'mem_total' => $memGb * 1024 ** 3, 'mem_available' => $memGb * 1024 ** 3 / 2, 'disk_total' => $diskGb * 1024 ** 3, 'disk_free' => $diskGb * 1024 ** 3, 'uptime' => 1000]);
    }

    protected function services(): Services
    {
        return $this->app->make(Services::class);
    }

    /** @return array<string, string> */
    protected function rustfs(string $name = 'files', array $extra = []): array
    {
        return $extra + ['name' => $name, 's3_host' => "{$name}-s3.example.com", 'ui_host' => "{$name}-ui.example.com", 'storage' => '10GB'];
    }

    /** Installs and runs the operation inline. @return array{service_id: int, operation_id: int, status: string} */
    protected function install(string $template = 'rustfs', ?array $inputs = null, string $node = 'web-1.org-1', string $org = 'org-1', string $role = 'manager'): array
    {
        $made = $this->services()->install($org, $node, $template, $inputs ?? $this->rustfs(), 'matt@example.com', $role, acknowledged: true, dispatch: false);
        $made['status'] = $this->app->make(OperationRunner::class)->run($made['operation_id']);

        return $made;
    }

    protected function operate(int $operationId): string
    {
        return $this->app->make(OperationRunner::class)->run($operationId);
    }

    /** @return array<string, mixed> the steps of the first recipe with this name prefix, by step id */
    protected function steps(string $namePrefix, int $nth = 0): array
    {
        $found = array_values(array_filter($this->recipes, fn ($r) => str_starts_with($r['name'], $namePrefix)));

        return array_column($found[$nth]['steps'] ?? [], null, 'id');
    }

    /** @return list<string> */
    protected function recipeNames(): array
    {
        return array_column($this->recipes, 'name');
    }

    protected function service(string $name = 'files'): ?object
    {
        return DB::table('services')->where('name', $name)->first();
    }
}
