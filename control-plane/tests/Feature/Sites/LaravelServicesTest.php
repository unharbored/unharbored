<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\LaravelServices;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;
use Tests\TestCase;

/** Laravel's own services (named queues, Horizon, Pulse, Nightwatch) added from a list. */
final class LaravelServicesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
    }

    private function sites(): Sites
    {
        return $this->app->make(Sites::class);
    }

    private function services(): LaravelServices
    {
        return $this->app->make(LaravelServices::class);
    }

    private function site(string $name = 'demo'): stdClass
    {
        return $this->sites()->create('org-1', 'web-1.org-1', $name, "{$name}.example.com", 'git@github.com:acme/demo.git');
    }

    /** @return array<string, array<string, mixed>> the after-switch steps by id */
    private function afterSwitch(stdClass $site): array
    {
        $deployer = new Deployer($this->sites(), new GatewayClient('http://gateway.test', 'secret', new HttpFactory, new AuditLog), new AuditLog, new GitHubApp(new HttpFactory, $this->app->make(Vault::class), new AuditLog));

        return array_column($deployer->afterSwitchRecipe($site)['steps'], null, 'id');
    }

    private function conf(array $steps): string
    {
        return base64_decode($steps['supervisor-conf']['content_b64']);
    }

    public function test_a_named_queue_worker_is_a_process_that_lets_a_job_finish_and_follows_queue_restart(): void
    {
        $site = $this->services()->add($this->site(), 'queue', ['queues' => 'Emails,default', 'processes' => 3, 'tries' => 5]);
        $steps = $this->afterSwitch($site);
        $conf = $this->conf($steps);

        $daemon = Sites::daemons($site)[0];
        $this->assertSame('queue-emails', $daemon['name']);
        $this->assertSame('php artisan queue:work --queue=emails,default --sleep=3 --tries=5 --max-time=3600', $daemon['command']);
        $this->assertSame(3, $daemon['processes']);
        $this->assertStringContainsString('[program:cm-demo-queue-emails]', $conf);
        $this->assertStringContainsString('numprocs=3', $conf);
        $this->assertStringContainsString('stopwaitsecs=3600', $conf);
        $this->assertArrayNotHasKey('restart-cm-demo-queue-emails', $steps, 'supervisor restarting it would hold the deploy for as long as a job runs');
        $this->assertArrayHasKey('queue-restart', $steps);
        $this->assertCount(0, array_filter(array_keys($steps), fn ($id) => str_starts_with($id, 'reload-') && $id !== 'reload-php-fpm'), 'queue:restart is not sent twice');
    }

    public function test_horizon_is_told_to_terminate_on_deploy_and_replaces_the_built_in_workers(): void
    {
        $site = $this->site();
        $this->assertGreaterThan(0, (int) $site->queue_workers);

        $site = $this->services()->add($site, 'horizon', ['stopBuiltIn' => true]);
        $steps = $this->afterSwitch($site);

        $this->assertSame(0, (int) $site->queue_workers);
        $this->assertStringContainsString('horizon:terminate', implode(' ', $steps['reload-horizon-terminate']['args']));
        $this->assertArrayNotHasKey('restart-cm-demo-horizon', $steps);
        $this->assertStringContainsString('stopwaitsecs=3600', $this->conf($steps));
        $this->assertStringContainsString('command=/usr/bin/php8.4 artisan horizon', $this->conf($steps));
    }

    public function test_horizon_can_leave_the_built_in_workers_alone(): void
    {
        $before = (int) $this->site()->queue_workers;
        $site = $this->services()->add($this->sites()->find('web-1.org-1', 'demo'), 'horizon', ['stopBuiltIn' => false]);

        $this->assertSame($before, (int) $site->queue_workers);
    }

    public function test_horizon_turns_off_worker_scaling_with_the_built_in_workers(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['queue_workers_min' => 0, 'queue_workers_max' => 4]);

        $site = $this->services()->add($this->sites()->find('web-1.org-1', 'demo'), 'horizon', ['stopBuiltIn' => true]);

        $this->assertNull($site->queue_workers_max);
        $this->assertSame(0, (int) $site->queue_workers);
    }

    public function test_pulse_is_a_plain_process_that_restarts_with_each_release(): void
    {
        $site = $this->services()->add($this->site(), 'pulse', []);
        $steps = $this->afterSwitch($site);

        $this->assertStringContainsString('command=/usr/bin/php8.4 artisan pulse:check', $this->conf($steps));
        $this->assertSame(['restart', 'cm-demo-pulse:*'], $steps['restart-cm-demo-pulse']['args']);
    }

    public function test_nightwatch_keeps_its_token_in_the_environment_and_nowhere_else(): void
    {
        $token = 'nw_'.bin2hex(random_bytes(12));
        $site = $this->services()->add($this->site(), 'nightwatch', ['token' => $token]);

        $daemon = Sites::daemons($site)[0];
        $this->assertSame('php artisan nightwatch:agent', $daemon['command']);
        $this->assertSame(2407, $daemon['port']);
        $env = $this->sites()->customEnv($site);
        $this->assertStringContainsString("NIGHTWATCH_TOKEN={$token}", $env);
        $this->assertStringContainsString('NIGHTWATCH_INGEST_URI=127.0.0.1:2407', $env);

        $this->assertStringNotContainsString($token, (string) DB::table('sites')->where('id', $site->id)->value('daemons'));
        $this->assertStringNotContainsString($token, json_encode(DB::table('audit_records')->get()), 'the audit log names the variable, not its value');
        $this->assertStringNotContainsString($token, json_encode(DB::table('site_env_versions')->get()), 'versions of the variables are in the vault');
    }

    public function test_a_second_application_on_the_server_gets_the_next_free_agent_port_and_a_re_add_keeps_its_own(): void
    {
        $first = $this->services()->add($this->site('one'), 'nightwatch', ['token' => 'token-one-1234']);
        $second = $this->services()->add($this->site('two'), 'nightwatch', ['token' => 'token-two-1234']);

        $this->assertSame(2407, Sites::daemons($first)[0]['port']);
        $this->assertSame(2408, Sites::daemons($second)[0]['port']);
        $this->assertStringContainsString('NIGHTWATCH_INGEST_URI=127.0.0.1:2408', $this->sites()->customEnv($second));

        $again = $this->services()->add($this->sites()->find('web-1.org-1', 'two'), 'nightwatch', ['token' => 'token-new-5678']);
        $this->assertSame(2408, Sites::daemons($again)[0]['port']);
    }

    /** @return array<string, array{string, array<string, mixed>}> */
    public static function badInput(): array
    {
        return [
            'unknown preset' => ['octane', []],
            'queue with a space' => ['queue', ['queues' => 'a b']],
            'queue with a newline' => ['queue', ['queues' => "emails\nuser=root"]],
            'no processes' => ['queue', ['queues' => 'emails', 'processes' => 0]],
            'too many tries' => ['queue', ['queues' => 'emails', 'tries' => 99]],
            'a field sent as a list' => ['queue', ['queues' => ['emails']]],
            'no token' => ['nightwatch', []],
            'token that is not one' => ['nightwatch', ['token' => 'abc def ghi jkl']],
        ];
    }

    /** @param  array<string, mixed>  $input */
    #[DataProvider('badInput')]
    public function test_bad_input_is_refused_and_changes_nothing(string $key, array $input): void
    {
        $site = $this->site();

        try {
            $this->services()->add($site, $key, $input);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException) {
            $this->assertSame([], Sites::daemons($this->sites()->find('web-1.org-1', 'demo')));
        }
    }

    public function test_the_console_adds_a_preset_and_a_viewer_cannot(): void
    {
        $site = $this->site();
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);
        $developer = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $developer->id, 'organization_id' => 'org-1', 'role' => 'developer', 'created_at' => now(), 'updated_at' => now()]);

        $this->actingAs($developer)->post("/site/settings/{$site->id}/daemons/preset", ['preset' => 'pulse'])->assertRedirect();
        $this->assertSame(['pulse'], array_column(Sites::daemons($this->sites()->find('web-1.org-1', 'demo')), 'name'));

        $this->actingAs($developer)->post("/site/settings/{$site->id}/daemons/preset", ['preset' => 'queue', 'fields' => ['queues' => 'a b']])->assertSessionHasErrors('preset');

        $viewer = User::factory()->create();
        DB::table('memberships')->insert(['user_id' => $viewer->id, 'organization_id' => 'org-1', 'role' => 'viewer', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($viewer)->postJson("/site/settings/{$site->id}/daemons/preset", ['preset' => 'pulse'])->assertForbidden();
    }

    public function test_every_preset_in_the_list_can_be_added(): void
    {
        foreach (LaravelServices::catalogue() as $preset) {
            $input = array_column(array_map(fn ($f) => ['n' => $f['name'], 'v' => $f['type'] === 'secret' ? 'token-1234567' : $f['default']], $preset['fields']), 'v', 'n');
            $this->assertNotNull($this->services()->add($this->site('app-'.$preset['key']), $preset['key'], $input), $preset['key']);
        }
    }
}
