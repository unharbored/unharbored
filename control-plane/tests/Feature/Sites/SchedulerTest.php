<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Sites\Deployer;
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
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * The Laravel scheduler as a switch, and the list of what the application's
 * code schedules, read from the live release.
 */
final class SchedulerTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    /** What `artisan schedule:list` prints. */
    private string $output = '';

    /** When set, an `exec` without --json fails, as it does on a Laravel with no such option. */
    private bool $noJson = false;

    /** When set, writing a recipe fails with this. */
    private ?string $failApply = null;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            $this->gateway[] = ['kind' => $r['kind'] ?? null, 'args' => $r['args'] ?? []];
            if (($r['kind'] ?? null) === 'exec' && $this->noJson && in_array('--json', $r['args']['args'] ?? [], true)) {
                return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'The "--json" option does not exist.']);
            }

            if ($this->failApply !== null && ($r['kind'] ?? null) === 'apply_recipe') {
                return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => $this->failApply]);
            }

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $this->output]]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
    }

    private function site(): object
    {
        return $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
    }

    public function test_the_scheduler_is_on_by_default_and_writes_the_cron_entry_on_the_primary(): void
    {
        $site = $this->site();
        $this->assertTrue((bool) $site->scheduler);

        $step = $this->app->make(Deployer::class)->schedulerStep($site);
        $this->assertSame('file', $step['type']);
        $this->assertStringContainsString('artisan schedule:run', base64_decode($step['content_b64']));

        $this->assertSame('absent', $this->app->make(Deployer::class)->schedulerStep($site, primary: false)['type'], 'Pool servers never run it.');
    }

    public function test_switched_off_the_entry_is_removed_by_the_operation_and_by_every_deploy(): void
    {
        $site = $this->app->make(Sites::class)->setScheduler($this->site(), false);
        $this->assertSame('absent', $this->app->make(Deployer::class)->schedulerStep($site)['type']);

        $steps = $this->app->make(Deployer::class)->afterSwitchRecipe($site)['steps'];
        $this->assertSame('absent', collect($steps)->firstWhere('id', 'scheduler')['type'], 'A deploy does not put it back.');

        $id = $this->app->make(OperationRunner::class)->queueScheduler((int) $site->id, 'console', 'me');
        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($id));
        $sent = collect($this->gateway)->firstWhere('kind', 'apply_recipe');
        $this->assertSame('absent', $sent['args']['spec']['steps'][0]['type']);
    }

    public function test_a_json_schedule_is_read_and_kept(): void
    {
        $site = $this->site();
        $this->output = json_encode([
            ['expression' => '0 * * * *', 'command' => "'php' 'artisan' inspire", 'description' => 'Hourly quote', 'next_due_date' => '2026-11-10 14:00:00 +00:00', 'next_due_date_human' => '12 minutes from now'],
        ]);

        $tasks = $this->app->make(Deployer::class)->readSchedule($site);

        $this->assertSame([['command' => "'php' 'artisan' inspire", 'expression' => '0 * * * *', 'description' => 'Hourly quote', 'next' => '12 minutes from now']], $tasks);
        $row = DB::table('sites')->where('id', $site->id)->first();
        $this->assertSame($tasks, Sites::scheduleTasks($row));
        $this->assertNotNull($row->schedule_tasks_at);
    }

    public function test_the_printed_table_is_read_when_the_laravel_version_has_no_json(): void
    {
        $site = $this->site();
        $this->noJson = true;
        $this->output = "  0 * * * *  php artisan inspire ............ Next Due: 12 minutes from now\n  30 3 * * *  php artisan reports:daily ........ Next Due: 13 hours from now\n";

        $tasks = $this->app->make(Deployer::class)->readSchedule($site);

        $this->assertSame(['php artisan inspire', 'php artisan reports:daily'], array_column($tasks, 'command'));
        $this->assertSame('30 3 * * *', $tasks[1]['expression']);
        $this->assertSame('13 hours from now', $tasks[1]['next']);
    }

    public function test_the_console_switches_it_queues_the_operation_and_reads_the_tasks(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();
        $url = "/site/settings/{$site->id}";

        $this->get("/site/settings?site={$site->id}&tab=scheduler")->assertInertia(fn (Assert $page) => $page
            ->component('Site/Settings')->where('data.scheduler', true)->where('data.scheduleTasks', [])->where('data.scheduleAge', ''));

        $this->output = json_encode([['expression' => '* * * * *', 'command' => 'php artisan pulse:check', 'description' => '', 'next_due_date_human' => '1 second from now']]);
        $tasks = $this->postJson("{$url}/scheduler/tasks")->assertOk()->json('tasks');
        $this->assertSame('php artisan pulse:check', $tasks[0]['command']);

        $this->post("{$url}/scheduler", ['on' => false])->assertRedirect();
        $this->assertFalse((bool) DB::table('sites')->where('id', $site->id)->value('scheduler'));
        $this->assertSame(1, DB::table('operations')->where('kind', 'scheduler')->count());
    }

    private function job(object $site, array $over = []): int
    {
        return $this->app->make(Sites::class)->saveJob($site, 0, $over + ['name' => 'Prune exports', 'command' => '{php} artisan exports:prune', 'frequency' => 'daily', 'at' => '02:30', 'cron' => '', 'everywhere' => false]);
    }

    private function cron(object $site, bool $primary = true): ?string
    {
        $step = $this->app->make(Deployer::class)->schedulerStep($site, $primary);

        return $step['type'] === 'file' ? base64_decode($step['content_b64']) : null;
    }

    public function test_a_job_becomes_a_locked_cron_line_with_the_applications_php(): void
    {
        $site = $this->site();
        $id = $this->job($site);

        $cron = (string) $this->cron($site);
        $this->assertStringContainsString("30 2 * * * deploy cd /home/deploy/shop/current && flock -n /tmp/cm-job-{$id}.lock -c ", $cron);
        $this->assertStringContainsString("'/usr/bin/php8.4 artisan exports:prune'", $cron, '{php} is this application\'s PHP.');
        $this->assertStringContainsString("cron-{$id}.log 2>&1", $cron);
        $this->assertStringContainsString('schedule:run', $cron, 'The Laravel scheduler stays in the same file.');
    }

    public function test_a_percent_sign_is_escaped_and_a_paused_job_is_left_out(): void
    {
        $site = $this->site();
        $id = $this->job($site, ['command' => "date +%F > /dev/null"]);
        $this->assertStringContainsString('date +\\%F', (string) $this->cron($site));

        $this->app->make(Sites::class)->setJobPaused($site, $id, true);
        $this->assertStringNotContainsString('flock', (string) $this->cron($site));
    }

    public function test_only_the_primary_runs_a_job_unless_it_says_every_server(): void
    {
        $site = $this->site();
        $this->job($site, ['name' => 'Primary only']);
        $this->job($site, ['name' => 'Everywhere', 'everywhere' => true, 'command' => 'df -h']);

        $this->assertStringNotContainsString('schedule:run', (string) $this->cron($site, primary: false));
        $this->assertStringContainsString("'df -h'", (string) $this->cron($site, primary: false));
        $this->assertStringNotContainsString('exports:prune', (string) $this->cron($site, primary: false));
    }

    public function test_the_file_is_absent_when_nothing_is_left_to_run(): void
    {
        $site = $this->app->make(Sites::class)->setScheduler($this->site(), false);
        $this->assertNull($this->cron($site));
        $this->job($site);
        $this->assertNotNull($this->cron($site));
    }

    public function test_frequencies_come_to_cron_expressions_and_bad_ones_are_refused(): void
    {
        $this->assertSame('*/5 * * * *', Sites::cronFor('every_five', '', ''));
        $this->assertSame('15 3 * * 0', Sites::cronFor('weekly', '3:15', ''));
        $this->assertSame('0 9 1 * *', Sites::cronFor('monthly', '09:00', ''));
        $this->assertSame('*/15 8-18 * * 1-5', Sites::cronFor('custom', '', ' */15  8-18 * * 1-5 '));
        foreach ([['custom', '', '@daily'], ['custom', '', '61 * * * *'], ['daily', '25:00', ''], ['yearly', '', '']] as [$f, $at, $c]) {
            try {
                Sites::cronFor($f, $at, $c);
                $this->fail("{$f} {$at} {$c} should be refused");
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertCount(5, Sites::nextRuns('*/15 * * * *'));
    }

    public function test_a_multiline_or_empty_job_is_refused(): void
    {
        $site = $this->site();
        foreach ([['command' => "ls\n* * * * * root reboot"], ['command' => ' '], ['name' => '']] as $bad) {
            try {
                $this->job($site, $bad);
                $this->fail('refused');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, DB::table('scheduled_jobs')->count());
    }

    public function test_applying_marks_jobs_installed_and_a_failure_marks_them_failed_with_the_reason(): void
    {
        $site = $this->site();
        $id = $this->job($site);
        $this->assertSame('pending', DB::table('scheduled_jobs')->where('id', $id)->value('state'));

        $op = $this->app->make(OperationRunner::class)->queueScheduler((int) $site->id, 'console', 'me');
        $this->assertSame('succeeded', $this->app->make(OperationRunner::class)->run($op));
        $this->assertSame('installed', DB::table('scheduled_jobs')->where('id', $id)->value('state'));

        $this->app->make(Sites::class)->setJobPaused($site, $id, false);
        $this->failApply = 'no space left on device';
        $op = $this->app->make(OperationRunner::class)->queueScheduler((int) $site->id, 'console', 'me');
        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($op));
        $this->assertSame('failed', DB::table('scheduled_jobs')->where('id', $id)->value('state'));
        $this->assertStringContainsString('no space left', (string) DB::table('scheduled_jobs')->where('id', $id)->value('error'));
    }

    public function test_output_and_run_now_read_through_the_agent(): void
    {
        $site = $this->site();
        $id = $this->job($site);
        $this->output = "Pruned 3 exports\n";

        $this->assertSame("Pruned 3 exports\n", $this->app->make(Deployer::class)->jobOutput($site, $id));
        $tail = collect($this->gateway)->last()['args'];
        $this->assertSame('tail', $tail['name']);
        $this->assertStringEndsWith("cron-{$id}.log", end($tail['args']));

        $this->app->make(Deployer::class)->runJob($site, '{php} artisan exports:prune');
        $run = collect($this->gateway)->last()['args'];
        $this->assertSame('runuser', $run['name']);
        $this->assertSame('cd /home/deploy/shop/current && /usr/bin/php8.4 artisan exports:prune', end($run['args']));
    }

    public function test_the_console_adds_edits_pauses_runs_and_deletes_a_job(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();
        $url = "/site/settings/{$site->id}";
        $page = fn () => $this->get("/site/settings?site={$site->id}&tab=scheduler");
        $new = ['id' => 0, 'name' => 'Prune exports', 'command' => '{php} artisan exports:prune', 'frequency' => 'daily', 'at' => '02:30', 'cron' => '', 'everywhere' => false, 'monitored' => true];

        $page()->assertInertia(fn (Assert $p) => $p->has('data.jobs', 0));
        $this->post("{$url}/jobs", ['frequency' => 'custom', 'cron' => 'nope'] + $new)->assertSessionHasErrors('job');
        $this->assertSame(0, DB::table('scheduled_jobs')->count());

        $this->post("{$url}/jobs", $new)->assertRedirect();
        $id = (int) DB::table('scheduled_jobs')->value('id');
        $this->assertSame('30 2 * * *', DB::table('scheduled_jobs')->value('cron'));
        $this->assertSame(1, DB::table('operations')->where('kind', 'scheduler')->count());

        $page()->assertInertia(fn (Assert $p) => $p->where('data.jobs.0.name', 'Prune exports')->where('data.jobFrequencies.daily', fn ($label) => str_starts_with($label, 'Daily')));
        $this->post("{$url}/jobs", ['id' => $id, 'at' => '04:00'] + $new)->assertRedirect();
        $this->assertSame('0 4 * * *', DB::table('scheduled_jobs')->value('cron'));

        $this->output = 'ok';
        $this->postJson("{$url}/jobs/{$id}/next")->assertOk()->assertJsonPath('title', 'Next runs of Prune exports');
        $this->postJson("{$url}/jobs/{$id}/output")->assertOk()->assertJsonPath('title', 'Last output of Prune exports');
        $this->postJson("{$url}/jobs/{$id}/run")->assertOk()->assertJsonPath('title', 'Ran Prune exports just now');

        $this->post("{$url}/jobs/{$id}/pause", ['paused' => true]);
        $this->assertTrue((bool) DB::table('scheduled_jobs')->value('paused'));

        $this->post("{$url}/jobs/{$id}/drop");
        $this->assertSame(0, DB::table('scheduled_jobs')->count());
    }

    public function test_jobs_go_with_their_application(): void
    {
        $site = $this->site();
        $this->job($site);
        DB::table('sites')->where('id', $site->id)->delete();
        $this->assertSame(0, DB::table('scheduled_jobs')->count());
    }
}
