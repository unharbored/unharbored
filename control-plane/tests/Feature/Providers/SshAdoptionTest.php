<?php

declare(strict_types=1);

namespace Tests\Feature\Providers;

use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Custom\RemoteShell;
use App\Lib\Providers\Custom\SshAdoption;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use phpseclib3\Crypt\EC;
use RuntimeException;
use Tests\TestCase;

/** A machine we can script: answers by what the command contains. */
final class FakeShell implements RemoteShell
{
    /** @var list<string> */
    public static array $commands = [];

    public static string $fingerprint = 'SHA256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public static bool $loginOk = true;

    /** @var array<string, array{exit: int, output: string}> */
    public static array $answers = [];

    public static ?array $login = null;

    public static function reset(): void
    {
        self::$commands = [];
        self::$loginOk = true;
        self::$login = null;
        self::$fingerprint = 'SHA256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        self::$answers = [
            'VERSION_ID' => ['exit' => 0, 'output' => "ubuntu 24.04\n"],
            'uname -m' => ['exit' => 0, 'output' => "x86_64\n"],
            'run/systemd' => ['exit' => 0, 'output' => ''],
            'MemTotal' => ['exit' => 0, 'output' => "2000000\n"],
            'ss -ltn' => ['exit' => 0, 'output' => ''],
            'apt-get -s' => ['exit' => 0, 'output' => ''],
            '/dev/tcp' => ['exit' => 0, 'output' => ''],
            'base64 -d' => ['exit' => 0, 'output' => "==> Installing prerequisites\n==> Agent enrolled\n"],
            'sudo -n true' => ['exit' => 0, 'output' => ''],
        ];
    }

    public function connect(string $host, int $port): string
    {
        self::$commands[] = "connect {$host}:{$port}";

        return self::$fingerprint;
    }

    public function authenticate(string $user, ?string $privateKey, ?string $passphrase, ?string $password): void
    {
        self::$login = ['user' => $user, 'key' => $privateKey !== null, 'password' => $password];
        if (! self::$loginOk) {
            throw new RuntimeException("{$user} was refused.");
        }
    }

    public function run(string $command, int $timeoutSeconds = 120): array
    {
        self::$commands[] = $command;
        foreach (self::$answers as $needle => $answer) {
            if (str_contains($command, $needle)) {
                return $answer;
            }
        }

        return ['exit' => 1, 'output' => ''];
    }

    public function disconnect(): void
    {
        self::$commands[] = 'disconnect';
    }
}

final class SshAdoptionTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private HttpFactory $http;

    private string $key;

    protected function setUp(): void
    {
        parent::setUp();
        FakeShell::reset();
        $this->dir = sys_get_temp_dir().'/ssh-adopt-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/cloud-manager-agent-linux-amd64", 'amd64 build');
        file_put_contents("{$this->dir}/cloud-manager-agent-linux-arm64", 'arm64 build');
        config([
            'vault.master_key' => LocalKeyManager::generateMasterKey(),
            'control_plane.agent_directory' => $this->dir, 'control_plane.public_url' => 'https://agent.example.com',
            'control_plane.server_name' => 'agent.example.com', 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test',
        ]);
        $this->http = new HttpFactory();
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->bind(RemoteShell::class, FakeShell::class);
        foreach ([KeyManager::class, Vault::class, \App\Lib\Agent\AgentRelease::class, \App\Lib\Cloud\CloudInit::class, \App\Lib\Gateway\GatewayClient::class, OperationRunner::class, SshAdoption::class] as $c) {
            $this->app->forgetInstance($c);
        }
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        $this->key = EC::createKey('Ed25519')->toString('OpenSSH');
        Queue::fake();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function start(array $over = []): int
    {
        $a = $over + ['name' => 'hetzner-1', 'host' => '203.0.113.7', 'port' => 22, 'user' => 'root', 'key' => $this->key, 'pass' => null, 'password' => null, 'fp' => null];

        return $this->app->make(SshAdoption::class)->start('org-1', $a['name'], $a['host'], $a['port'], $a['user'], $a['key'], $a['pass'], $a['password'], $a['fp'], 'matt@example.com');
    }

    public function test_starting_holds_the_login_in_the_vault_and_nowhere_else(): void
    {
        $op = $this->start();

        $row = DB::table('ssh_adoptions')->first();
        $this->assertNotNull($this->app->make(Vault::class)->get('org-1', $row->vault_key));
        $everything = json_encode([DB::table('operations')->get(), DB::table('audit_records')->get(), $row]);
        $this->assertStringNotContainsString('OPENSSH PRIVATE KEY', $everything);
        $this->assertStringNotContainsString('b3BlbnNzaC1rZXk', $everything);
        $this->assertSame(['adopt_ssh', 'hetzner-1.org-1'], [DB::table('operations')->find($op)->kind, DB::table('operations')->find($op)->node_id]);
        $this->assertDatabaseHas('audit_records', ['action' => 'server.ssh_adoption_started']);
    }

    public function test_bad_input_is_refused_by_field(): void
    {
        foreach ([
            [['name' => 'Bad Name'], 'name:'], [['host' => 'not a host!'], 'host:'], [['port' => 70000], 'port:'], [['user' => 'ro ot'], 'user:'],
            [['key' => null, 'password' => null], 'key:'], [['key' => 'garbage'], 'key:'], [['password' => 'also'], 'key:'], [['fp' => 'SHA256:short'], 'fingerprint:'],
        ] as [$over, $prefix]) {
            try {
                $this->start($over);
                $this->fail('expected '.$prefix);
            } catch (\InvalidArgumentException $e) {
                $this->assertStringStartsWith($prefix, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('ssh_adoptions')->count());
    }

    public function test_it_connects_checks_installs_discards_the_login_and_then_waits_for_the_agent(): void
    {
        $op = $this->start();
        $this->http->fake(['gw.test/*' => $this->http->response(['connected' => false], 200)]);

        $this->assertSame('waiting', $this->app->make(OperationRunner::class)->run($op));

        $row = DB::table('ssh_adoptions')->first();
        $this->assertNull($row->vault_key, 'The login is discarded once the agent is installed.');
        $this->assertNotNull($row->installed_at);
        $this->assertSame('SHA256:aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', $row->fingerprint);
        $log = DB::table('operations')->find($op)->log;
        $this->assertStringContainsString('host key SHA256:', $log);
        $this->assertStringContainsString('ok    Ubuntu 24.04', $log);
        $this->assertStringContainsString('the login has been discarded', $log);
        $this->assertStringNotContainsString('PRIVATE KEY', $log);
        $this->assertSame('disconnect', end(FakeShell::$commands));
        $install = collect(FakeShell::$commands)->first(fn ($c) => str_contains($c, 'base64 -d'));
        $this->assertStringContainsString("bash \"\$f\"", $install);
        $this->assertStringNotContainsString('sudo', $install, 'root needs no sudo');
    }

    public function test_the_second_pass_does_not_log_in_again_and_installs_the_stack(): void
    {
        $op = $this->start();
        // The agent's enrolment is what records the node; the stack install waits for it.
        DB::table('nodes')->updateOrInsert(['node_id' => 'hetzner-1.org-1'], ['organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $this->http->fake([
            'gw.test/v1/nodes/hetzner-1.org-1' => $this->http->sequence()->push(['connected' => false], 200)->push(['connected' => true], 200),
            'gw.test/v1/nodes/hetzner-1.org-1/commands' => $this->http->response(['status' => 'succeeded', 'outcome_known' => true,
                'detail' => ['run' => ['steps' => [['action' => 'create', 'description' => 'package nginx installed']]]]]),
        ]);
        $runner = $this->app->make(OperationRunner::class);

        $this->assertSame('waiting', $runner->run($op));
        $connects = count(array_filter(FakeShell::$commands, fn ($c) => str_starts_with($c, 'connect ')));
        $this->assertSame('succeeded', $runner->run($op));

        $this->assertSame($connects, count(array_filter(FakeShell::$commands, fn ($c) => str_starts_with($c, 'connect '))));
        $this->assertSame(['done', 'done', 'done', 'done', 'done'], array_column(json_decode(DB::table('operations')->find($op)->stages, true), 'status'));
    }

    public function test_a_problem_machine_fails_before_anything_is_installed_and_the_login_is_still_discarded(): void
    {
        FakeShell::$answers['VERSION_ID'] = ['exit' => 0, 'output' => "debian 12\n"];
        FakeShell::$answers['ss -ltn'] = ['exit' => 0, 'output' => '0.0.0.0:80 '];
        $op = $this->start();

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($op));

        $error = DB::table('operations')->find($op)->error;
        $this->assertStringContainsString('nothing was changed', $error);
        $this->assertStringContainsString('debian 12', $error);
        $this->assertStringContainsString('0.0.0.0:80', $error);
        $this->assertNull(DB::table('ssh_adoptions')->first()->vault_key);
        $this->assertNull(collect(FakeShell::$commands)->first(fn ($c) => str_contains($c, 'base64 -d')), 'Nothing was installed.');
    }

    public function test_a_different_host_key_than_expected_stops_before_login(): void
    {
        $op = $this->start(['fp' => 'SHA256:'.str_repeat('b', 43)]);

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($op));

        $this->assertStringContainsString('not the SHA256:', DB::table('operations')->find($op)->error);
        $this->assertNull(FakeShell::$login, 'No login was sent to a host with the wrong key.');
        $this->assertNull(DB::table('ssh_adoptions')->first()->vault_key);
    }

    public function test_a_refused_login_fails_plainly_and_discards_the_login(): void
    {
        FakeShell::$loginOk = false;
        $op = $this->start(['key' => null, 'password' => 'hunter2hunter2']);

        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($op));

        $this->assertStringContainsString('was refused', DB::table('operations')->find($op)->error);
        $this->assertStringNotContainsString('hunter2', json_encode(DB::table('operations')->get()));
        $this->assertNull(DB::table('ssh_adoptions')->first()->vault_key);
    }

    public function test_a_non_root_user_installs_through_sudo_and_must_have_it_without_a_password(): void
    {
        $op = $this->start(['user' => 'ubuntu']);
        $this->http->fake(['gw.test/*' => $this->http->response(['connected' => false], 200)]);

        $this->assertSame('waiting', $this->app->make(OperationRunner::class)->run($op));
        $this->assertStringContainsString('sudo -n bash', collect(FakeShell::$commands)->first(fn ($c) => str_contains($c, 'base64 -d')));

        FakeShell::reset();
        FakeShell::$answers['sudo -n true'] = ['exit' => 1, 'output' => ''];
        $second = $this->start(['name' => 'hetzner-2', 'user' => 'ubuntu']);
        $this->assertSame('failed', $this->app->make(OperationRunner::class)->run($second));
        $this->assertStringContainsString('cannot run sudo without a password', DB::table('operations')->find($second)->error);
    }

    public function test_the_sweep_deletes_a_login_left_behind_after_an_hour(): void
    {
        $this->start();
        $this->start(['name' => 'hetzner-2']);
        DB::table('ssh_adoptions')->where('node_id', 'hetzner-1.org-1')->update(['created_at' => now()->subMinutes(61)]);

        $this->assertSame(1, $this->app->make(SshAdoption::class)->sweep());

        $this->assertNull(DB::table('ssh_adoptions')->where('node_id', 'hetzner-1.org-1')->value('vault_key'));
        $this->assertNotNull(DB::table('ssh_adoptions')->where('node_id', 'hetzner-2.org-1')->value('vault_key'));
    }

    public function test_the_form_validates_and_never_flashes_the_secret_back(): void
    {
        $this->actingAs(\App\Models\User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        config(['control_plane.outbound_ip' => '198.51.100.9']);

        $this->get('/servers/connect')->assertOk()->assertInertia(fn ($page) => $page->component('Servers/Connect')->where('outboundIp', '198.51.100.9'));

        $this->from('/servers/connect')->post('/servers/connect', ['name' => 'Bad Name', 'host' => '203.0.113.7', 'password' => 'sup3rsecretpw'])
            ->assertSessionHasErrors('name')->assertSessionMissing('_old_input.password');

        $this->post('/servers/connect', ['name' => 'hetzner-1', 'host' => '203.0.113.7', 'private_key' => $this->key])
            ->assertRedirectContains('/operation?operation=');
        $this->assertSame(1, DB::table('ssh_adoptions')->count());
    }
}
