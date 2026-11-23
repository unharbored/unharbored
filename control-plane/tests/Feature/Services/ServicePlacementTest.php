<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Services\Services;
use App\Lib\Sites\Sites;
use App\Lib\Vault\Vault;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** What may be installed where: memory, disk, ports, what a version cannot do yet; and that secrets stay in the vault. */
final class ServicePlacementTest extends ServiceTestCase
{
    private function refuses(string $contains, ?array $inputs = null, string $node = 'web-1.org-1', string $template = 'rustfs', bool $acknowledged = true): void
    {
        $rows = [DB::table('services')->count(), DB::table('operations')->count()];
        try {
            $this->services()->install('org-1', $node, $template, $inputs ?? $this->rustfs(), 'm', 'manager', $acknowledged, dispatch: false);
            $this->fail("expected a refusal containing: {$contains}");
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
            $this->assertSame($rows, [DB::table('services')->count(), DB::table('operations')->count()], 'a refused install leaves no row and no operation');
        }
    }

    public function test_a_server_with_too_little_memory_is_refused_with_the_numbers(): void
    {
        $this->node('tiny.org-1', 'org-1', memGb: 0);
        DB::table('node_metrics')->where('node_id', 'tiny.org-1')->update(['mem_total' => 512 * 1024 ** 2]);
        $this->refuses('RustFS needs at least 1GB of memory and this server has 512 MB', node: 'tiny.org-1');
    }

    public function test_a_one_gigabyte_server_passes_a_one_gigabyte_floor(): void
    {
        $this->node('small.org-1', 'org-1');
        DB::table('node_metrics')->where('node_id', 'small.org-1')->update(['mem_total' => 983 * 1024 ** 2]); // what a "1 GB" Droplet reports
        $this->assertSame('succeeded', $this->install('rustfs', $this->rustfs(), 'small.org-1')['status']);
    }

    public function test_a_server_without_enough_free_disk_for_the_storage_is_refused(): void
    {
        $this->node('full.org-1', 'org-1');
        DB::table('node_metrics')->where('node_id', 'full.org-1')->update(['disk_free' => 5 * 1024 ** 3]);
        $this->refuses('free disk', $this->rustfs('files', ['storage' => '50GB']), 'full.org-1');
    }

    public function test_an_offline_server_and_a_server_that_does_not_report_are_refused(): void
    {
        $this->connected = false;
        $this->refuses('is not online');

        $this->connected = true;
        DB::table('node_metrics')->update(['collected_at' => now()->subHours(2)]);
        $this->refuses('has not reported its memory and disk recently');
    }

    public function test_the_fit_check_runs_again_in_the_operation(): void
    {
        $made = $this->services()->install('org-1', 'web-1.org-1', 'rustfs', $this->rustfs(), 'm', 'manager', dispatch: false);
        DB::table('node_metrics')->update(['mem_total' => 256 * 1024 ** 2]);

        $this->assertSame('failed', $this->operate($made['operation_id']));
        $this->assertSame('failed', $this->service()->status);
        $this->assertSame(['service-stop-files'], $this->recipeNames(), 'nothing was written or started; only a harmless stop');
    }

    public function test_a_second_service_wanting_the_same_port_on_one_server_is_refused_not_reallocated(): void
    {
        $this->assertSame('succeeded', $this->install()['status']);
        $this->refuses('Port 9000 on this server is already used by the service files', $this->rustfs('more'));
    }

    public function test_a_port_an_application_uses_is_refused(): void
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'api', 'api.example.com', 'git@github.com:acme/api.git');
        DB::table('sites')->where('id', $site->id)->update(['daemons' => json_encode([['name' => 'ws', 'command' => 'x', 'processes' => 1, 'port' => 9001]])]);
        $this->refuses('Port 9001 on this server is already used by a background process of api');

        DB::table('sites')->where('id', $site->id)->update(['daemons' => null, 'runtime' => 'node', 'runtime_config' => json_encode(['port' => 9000])]);
        $this->refuses('Port 9000 on this server is already used by the application api');
    }

    public function test_bad_inputs_are_refused_before_anything_is_recorded(): void
    {
        $this->refuses('s3_host must be a domain name', $this->rustfs('files', ['s3_host' => 'not a domain']));
        $this->refuses('is not an input of this template', $this->rustfs('files', ['colour' => 'red']));
        $this->refuses('is used for two addresses', $this->rustfs('files', ['ui_host' => 'files-s3.example.com']));
        $this->refuses('There is no template called nope', template: 'nope');
    }

    public function test_what_this_version_cannot_do_yet_is_refused_not_skipped(): void
    {
        $this->refuses('is not prepared for containers', ['name' => 'kuma', 'host' => 'kuma.example.com'], template: 'uptime-kuma');
        $this->refuses('needs the firewall to open 2222/tcp', ['name' => 'git', 'host' => 'git.example.com'], template: 'gitea');
        $this->refuses('Acknowledge first: This runner executes code', ['name' => 'ci', 'url' => 'https://github.com/acme/app', 'token' => 'x'], template: 'github-runner', acknowledged: false);
    }

    public function test_secrets_are_in_the_vault_and_nowhere_else(): void
    {
        $this->node('ci.org-1', 'org-1');
        $token = 'AAAA-registration-token-ZZZZ';
        $made = $this->install('github-runner', ['name' => 'ci', 'url' => 'https://github.com/acme/app', 'token' => $token], 'ci.org-1');
        $this->assertSame('succeeded', $made['status'], (string) DB::table('operations')->value('error'));
        $rust = $this->install('rustfs', $this->rustfs());
        $this->assertSame('succeeded', $rust['status']);

        $vault = app(Vault::class);
        $secrets = [$token, $vault->get('org-1', Services::secretName($rust['service_id'], 'access_key')), $vault->get('org-1', Services::secretName($rust['service_id'], 'secret_key'))];
        $this->assertSame($token, $vault->get('org-1', Services::secretName($made['service_id'], 'token')));
        $this->assertSame(40, strlen($secrets[2]));

        foreach (['operations', 'audit_records', 'services', 'service_endpoints', 'log_lines'] as $table) {
            $dump = json_encode(DB::table($table)->get());
            foreach ($secrets as $s) {
                $this->assertStringNotContainsString($s, (string) $dump, "a secret is in {$table}");
            }
        }
        $this->assertNotContains('token', array_keys(json_decode($this->service('ci')->inputs, true)), 'a secret input is not stored with the inputs');

        // On the server it is only in the environment file (sensitive), never in a command's arguments.
        $env = $this->steps('service-prepare', 0)['env'];
        $this->assertTrue($env['sensitive']);
        $this->assertStringContainsString($token, base64_decode($env['content_b64']));
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $step) {
                if ($step['type'] === 'command') {
                    $this->assertStringNotContainsString($token, json_encode($step['args'] ?? []), 'a secret is in an argument');
                    foreach ($secrets as $s) {
                        $this->assertStringNotContainsString($s, json_encode($step['args'] ?? []));
                    }
                }
            }
        }
    }
}
