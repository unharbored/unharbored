<?php

declare(strict_types=1);

namespace Tests\Feature\Operations;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * An operation is the only record of a long action the console shows, so it
 * must say truthfully where the action got to and how it ended, whoever
 * started it and whether or not anyone was watching.
 */
final class OperationsTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        $this->http = new HttpFactory();
        $this->app->instance(HttpFactory::class, $this->http);
        foreach ([GatewayClient::class, OperationRunner::class, \App\Lib\Sites\Deployer::class, \App\Lib\Servers\AgentUpgrade::class] as $class) {
            $this->app->forgetInstance($class);
        }
    }

    private function ops(): Operations
    {
        return new Operations();
    }

    private function site(): object
    {
        return (new Sites($this->app->make(Vault::class), new AuditLog()))
            ->create('org-1', 'web-1.org-1', 'demo', 'demo.example.com', 'git@github.com:acme/demo.git');
    }

    public function test_stages_move_forward_one_at_a_time(): void
    {
        $id = $this->ops()->create('org-1', 'deploy', 'Deploy demo', 'console', 'me', ['setup', 'fetch', 'build']);

        $this->ops()->start($id);
        $this->ops()->stage($id, 'setup');
        $this->ops()->stage($id, 'fetch');

        $stages = array_column(json_decode($this->ops()->find($id)->stages, true), 'status', 'name');
        $this->assertSame(['setup' => 'done', 'fetch' => 'running', 'build' => 'waiting'], $stages);
    }

    public function test_a_failure_marks_the_stage_it_happened_in(): void
    {
        $id = $this->ops()->create('org-1', 'deploy', 'Deploy demo', 'console', 'me', ['setup', 'build', 'activate']);
        $this->ops()->stage($id, 'setup');
        $this->ops()->stage($id, 'build');

        $this->ops()->fail($id, 'composer exited 1');

        $op = $this->ops()->find($id);
        $this->assertSame('failed', $op->status);
        $this->assertSame('composer exited 1', $op->error);
        $this->assertSame(['setup' => 'done', 'build' => 'failed', 'activate' => 'waiting'], array_column(json_decode($op->stages, true), 'status', 'name'));
    }

    public function test_log_lines_carry_the_running_stage(): void
    {
        $id = $this->ops()->create('org-1', 'deploy', 'Deploy demo', 'console', 'me', ['fetch']);
        $this->ops()->stage($id, 'fetch');

        $this->ops()->log($id, 'run', "clone repo\nwith a newline");

        $this->assertMatchesRegularExpression('/^\d\d:\d\d:\d\d  fetch    run     clone repo with a newline\n$/', $this->ops()->find($id)->log);
    }

    public function test_queueing_a_deploy_records_it_before_it_runs(): void
    {
        Queue::fake();
        $site = $this->site();

        $id = $this->app->make(OperationRunner::class)->queueDeploy((int) $site->id, 'console', 'matt@example.com');

        $op = $this->ops()->find($id);
        $this->assertSame(['deploy', 'queued', 'console', 'matt@example.com'], [$op->kind, $op->status, $op->trigger, $op->actor]);
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z-main$/', $op->release_id, 'The release id is known before the deploy runs, so the page can show it.');
        Queue::assertPushed(\App\Lib\Operations\RunOperation::class);
    }

    public function test_a_run_records_a_successful_deploy(): void
    {
        Queue::fake();
        $site = $this->site();
        $id = $this->app->make(OperationRunner::class)->queueDeploy((int) $site->id, 'console', 'me');
        $step = fn ($action, $desc) => ['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['run' => ['steps' => [['action' => $action, 'description' => $desc]]]]];
        $this->http->fake(['gw.test/*' => $this->http->sequence()
            ->push($step('noop', 'directory /home/deploy/demo'))       // setup
            ->push($step('run', 'clone demo'))                         // fetch
            ->push(['status' => 'succeeded', 'outcome_known' => true]) // prepare
            ->push($step('run', 'composer install'))                   // build
            ->push(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => "abc1234\n"]])
            ->push(['status' => 'succeeded', 'outcome_known' => true]) // activate
            ->push($step('run', 'supervisor picks up program changes'))
            ->push(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['deleted' => ['old-1']]])]);

        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($id));

        $op = $this->ops()->find($id);
        $this->assertSame(['succeeded', 'abc1234'], [$op->status, $op->commit]);
        $this->assertSame(array_fill(0, 7, 'done'), array_column(json_decode($op->stages, true), 'status'));
        $this->assertStringContainsString('build    run     composer install', $op->log);
        $this->assertStringContainsString('prune    prune   removed old-1', $op->log);
    }

    public function test_a_failed_run_is_recorded_and_does_not_throw(): void
    {
        Queue::fake();
        $site = $this->site();
        $id = $this->app->make(OperationRunner::class)->queueDeploy((int) $site->id, 'console', 'me');
        $this->http->fake(['gw.test/*' => $this->http->response(['status' => 'failed', 'error' => 'node said no', 'outcome_known' => true])]);

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($id));

        $op = $this->ops()->find($id);
        $this->assertSame('failed', $op->status);
        $this->assertStringContainsString('node said no', $op->error);
        $this->assertSame('failed', json_decode($op->stages, true)[0]['status']);
    }

    public function test_a_finished_operation_is_never_run_again(): void
    {
        Queue::fake();
        $site = $this->site();
        $id = $this->app->make(OperationRunner::class)->queueDeploy((int) $site->id, 'console', 'me');
        $this->ops()->fail($id, 'stopped by hand');
        $this->http->fake();

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($id));

        $this->http->assertNothingSent();
        $this->assertSame('stopped by hand', $this->ops()->find($id)->error);
    }

    public function test_waiting_for_a_new_droplet_releases_the_worker_and_never_creates_twice(): void
    {
        Queue::fake();
        DB::table('nodes')->insert(['node_id' => 'web-2.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $id = $this->ops()->create('org-1', 'provision', 'Create server web-2', 'console', 'me', ['preflight', 'create', 'enroll', 'stack'],
            nodeId: 'web-2.org-1', meta: ['slug' => 'web-2', 'spec' => ['region' => 'nyc3', 'size' => 's-1vcpu-1gb']]);
        // As if preflight and create already ran: the record says enroll.
        $this->ops()->start($id);
        $this->ops()->stage($id, 'enroll');
        $this->http->fake(['gw.test/v1/nodes/web-2.org-1' => $this->http->response(['connected' => false])]);

        $this->assertSame('waiting', $this->app->make(OperationRunner::class)->run($id));

        $this->http->assertNotSent(fn ($r) => str_contains($r->url(), 'digitalocean'));
        $this->assertSame('enroll', $this->ops()->currentStage($id));
    }
}
