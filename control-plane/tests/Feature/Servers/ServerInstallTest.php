<?php

declare(strict_types=1);

namespace Tests\Feature\Servers;

use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use App\Lib\Servers\ServerInstall;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Bring your own server. The link is the credential, so it works once and
 * briefly; the script runs as root on someone's machine, so it must at least
 * be valid shell; and following the install must never hold the worker.
 */
final class ServerInstallTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/byo-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/cloud-manager-agent-linux-amd64", 'amd64 build');
        file_put_contents("{$this->dir}/cloud-manager-agent-linux-arm64", 'arm64 build');
        config([
            'vault.master_key' => LocalKeyManager::generateMasterKey(),
            'control_plane.agent_directory' => $this->dir,
            'control_plane.public_url' => 'https://agent.example.com',
            'control_plane.server_name' => 'agent.example.com',
            'control_plane.gateway_token' => 't',
            'control_plane.gateway_url' => 'http://gw.test',
        ]);
        $this->http = new HttpFactory();
        $this->app->instance(HttpFactory::class, $this->http);
        foreach ([KeyManager::class, Vault::class, \App\Lib\Agent\AgentRelease::class, \App\Lib\Cloud\CloudInit::class, \App\Lib\Gateway\GatewayClient::class, OperationRunner::class, ServerInstall::class] as $c) {
            $this->app->forgetInstance($c);
        }
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function issue(string $name = 'hetzner-1'): array
    {
        Queue::fake();

        return $this->app->make(ServerInstall::class)->issue('org-1', $name, 'https://cp.example.com', 'matt@example.com');
    }

    private function secret(array $issued): string
    {
        return substr($issued['command'], strrpos($issued['command'], '/') + 1, 48);
    }

    public function test_issuing_gives_a_one_line_command_and_starts_following_it(): void
    {
        $issued = $this->issue();

        $this->assertMatchesRegularExpression('#^curl -fsSL https://cp\.example\.com/install/[0-9a-f]{48} \| sudo bash$#', $issued['command']);
        $op = DB::table('operations')->find($issued['operation']);
        $this->assertSame(['adopt', 'hetzner-1.org-1'], [$op->kind, $op->node_id]);
        $this->assertNotSame($this->secret($issued), DB::table('server_installs')->value('secret_hash'), 'Only a hash of the secret is kept.');
    }

    public function test_the_script_is_valid_shell_with_everything_it_needs(): void
    {
        $script = $this->app->make(ServerInstall::class)->script($this->secret($this->issue()), '203.0.113.9');

        $path = "{$this->dir}/install.sh";
        file_put_contents($path, $script);
        $syntax = new Process(['bash', '-n', $path]);
        $syntax->run();
        $this->assertTrue($syntax->isSuccessful(), "bash -n: {$syntax->getErrorOutput()}");

        $this->assertStringContainsString('BEGIN CERTIFICATE', $script);
        $this->assertStringContainsString(hash('sha256', 'amd64 build'), $script);
        $this->assertStringContainsString(hash('sha256', 'arm64 build'), $script);
        $this->assertStringContainsString('--cacert /var/lib/cloud-manager/ca.crt', $script);
        $this->assertStringContainsString('-server-name agent.example.com', $script);
        $this->assertStringContainsString('already enrolled', $script);
        $this->assertStringNotContainsString('hostname', strtolower(preg_replace('/server-name|hostname\)/', '', $script)), 'A server we did not create keeps its name.');
    }

    public function test_a_link_works_once(): void
    {
        $secret = $this->secret($this->issue());

        $this->get("/install/{$secret}")->assertOk()->assertSee('#!/usr/bin/env bash', false);
        $this->get("/install/{$secret}")->assertStatus(410);
        $this->assertDatabaseHas('audit_records', ['action' => 'server.install_script_fetched']);
    }

    public function test_an_expired_or_unknown_link_gets_nothing(): void
    {
        $secret = $this->secret($this->issue());
        DB::table('server_installs')->update(['expires_at' => now()->subMinute()]);

        $this->get("/install/{$secret}")->assertStatus(410);
        $this->get('/install/'.str_repeat('a', 48))->assertStatus(410);
    }

    public function test_a_name_already_in_use_is_refused(): void
    {
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $this->expectException(\InvalidArgumentException::class);
        $this->issue('web-1');
    }

    public function test_following_an_unfetched_link_waits_without_failing(): void
    {
        $issued = $this->issue();

        $this->assertSame('waiting', $this->app->make(OperationRunner::class)->run($issued['operation']));
        $op = DB::table('operations')->find($issued['operation']);
        $this->assertSame('running', $op->status);
        $this->assertSame('install', (new Operations())->currentStage($issued['operation']));
    }

    public function test_an_unused_link_that_expires_fails_the_operation(): void
    {
        $issued = $this->issue();
        DB::table('server_installs')->update(['expires_at' => now()->subMinute()]);

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($issued['operation']));
        $this->assertStringContainsString('was not run within', DB::table('operations')->find($issued['operation'])->error);
    }

    public function test_once_fetched_and_connected_the_stack_is_installed(): void
    {
        $issued = $this->issue();
        DB::table('server_installs')->update(['fetched_at' => now(), 'fetched_by' => '203.0.113.9']);
        DB::table('nodes')->updateOrInsert(['node_id' => 'hetzner-1.org-1'], ['organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->http->fake([
            'gw.test/v1/nodes/hetzner-1.org-1' => $this->http->sequence()
                ->push(['connected' => false], 200)
                ->push(['connected' => true], 200),
            'gw.test/v1/nodes/hetzner-1.org-1/commands' => $this->http->response(['status' => 'succeeded', 'outcome_known' => true,
                'detail' => ['run' => ['steps' => [['action' => 'create', 'description' => 'package nginx installed']]]]]),
        ]);

        $runner = $this->app->make(OperationRunner::class);
        $this->assertSame('waiting', $runner->run($issued['operation']), 'Fetched but not yet connected.');
        $this->assertSame('succeeded', $runner->run($issued['operation']));

        $op = DB::table('operations')->find($issued['operation']);
        $this->assertSame(['done', 'done', 'done'], array_column(json_decode($op->stages, true), 'status'));
        $this->assertStringContainsString('script fetched from 203.0.113.9', $op->log);
        $this->assertStringContainsString('package nginx installed', $op->log);
    }
}
