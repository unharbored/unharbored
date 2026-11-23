<?php

declare(strict_types=1);

namespace Tests\Feature\Alerts;

use App\Lib\Alerts\Alerts;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Someone hears once when something needs them and once when it stops,
 * and a restart is not an outage.
 */
final class AlertsTest extends TestCase
{
    use RefreshDatabase;

    private HttpFactory $http;

    /** @var list<string> node ids the gateway says are connected */
    private array $connected = ['web-1.org-1', 'web-2.org-1'];

    private bool $gatewayDown = false;

    /** @var list<string> */
    private array $posted = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey()]);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Cache::flush();
        $this->http = new HttpFactory();
        $this->http->fake(function (Request $r) {
            if (str_contains($r->url(), 'gw.test')) {
                return $this->gatewayDown ? $this->http->response('', 502)
                    : $this->http->response(['nodes' => array_map(fn ($n) => ['node_id' => $n], $this->connected)]);
            }
            $this->posted[] = (string) $r['text'];

            return $this->http->response('ok');
        });
        $this->app->instance(HttpFactory::class, $this->http);
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $this->http, new AuditLog()));
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['web-1.org-1', 'web-2.org-1'] as $n) {
            DB::table('nodes')->insert(['organization_id' => 'org-1', 'node_id' => $n, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->app->make(Vault::class)->put('org-1', Alerts::WEBHOOK_SECRET, 'https://hooks.example.com/x');
    }

    private function check(): array
    {
        return $this->app->make(Alerts::class)->check('org-1');
    }

    private function sample(string $node, float $cpu, int $diskFree = 50, int $minutesAgo = 0): void
    {
        DB::table('node_metrics')->insert(['organization_id' => 'org-1', 'node_id' => $node, 'collected_at' => now()->subMinutes($minutesAgo),
            'cpu' => $cpu, 'load_1' => 0, 'mem_total' => 100, 'mem_available' => 50, 'disk_total' => 100, 'disk_free' => $diskFree, 'uptime' => 1]);
    }

    public function test_a_server_is_offline_only_after_three_minutes_gone_and_clears_when_back(): void
    {
        $this->connected = ['web-1.org-1'];
        $this->assertSame([], $this->check(), 'Just gone: a restart, maybe.');
        $this->travel(2)->minutes();
        $this->assertSame([], $this->check());
        $this->travel(2)->minutes();
        $this->assertSame(['opened: web-2 is offline'], $this->check());
        $this->assertSame([], $this->check(), 'Told once.');

        $this->connected = ['web-1.org-1', 'web-2.org-1'];
        $this->assertSame(['cleared: web-2 is offline'], $this->check());
        $this->assertCount(2, $this->posted);
        $this->assertStringStartsWith('✗ web-2 is offline', $this->posted[0]);
        $this->assertStringStartsWith('✓ Cleared: web-2 is offline', $this->posted[1]);
    }

    public function test_a_full_disk_and_a_pinned_cpu_alert(): void
    {
        $this->sample('web-1.org-1', 10, diskFree: 5);
        for ($i = 0; $i < 10; $i++) {
            $this->sample('web-2.org-1', 97, minutesAgo: $i);
        }
        $this->assertEqualsCanonicalizing([
            "opened: web-1's disk is 95% full",
            "opened: web-2's CPU has been above 90% for 10 minutes",
        ], $this->check());
    }

    public function test_a_short_spike_is_not_a_pinned_cpu(): void
    {
        $this->sample('web-1.org-1', 99);
        $this->assertSame([], $this->check());
    }

    public function test_failed_operations_are_told_once_from_the_first_check_on(): void
    {
        $op = fn (string $status) => DB::table('operations')->insertGetId(['organization_id' => 'org-1', 'kind' => 'deploy', 'title' => 'Deploy shop',
            'trigger' => 'push', 'actor' => 'x', 'status' => $status, 'stages' => '[]', 'error' => 'composer install failed', 'created_at' => now(), 'updated_at' => now()]);
        $op('failed');
        $this->assertSame([], $this->check(), 'History is not news.');

        $op('succeeded');
        $op('failed');
        $this->assertSame(['failed: Failed: Deploy shop'], $this->check());
        $this->assertSame([], $this->check());
        $this->assertSame(0, DB::table('alerts')->whereNull('resolved_at')->count(), 'An event, not a condition: nothing stays open.');
    }

    public function test_an_operation_stuck_for_hours_does_not_hide_later_failures(): void
    {
        $op = fn (string $status, $at) => DB::table('operations')->insertGetId(['organization_id' => 'org-1', 'kind' => 'deploy', 'title' => 'Deploy shop',
            'trigger' => 'push', 'actor' => 'x', 'status' => $status, 'stages' => '[]', 'error' => 'composer install failed', 'created_at' => $at, 'updated_at' => $at]);
        $op('succeeded', now());
        $this->assertSame([], $this->check());

        $op('running', now()->subHours(5));
        $op('failed', now());
        $this->assertSame(['failed: Failed: Deploy shop'], $this->check(), 'A stuck operation is not a reason to stay silent about the one after it.');

        $op('running', now()->subMinutes(2));
        $op('failed', now());
        $this->assertSame([], $this->check(), 'A recent running operation is still waited for.');
    }

    public function test_an_unreachable_gateway_is_one_alert_not_one_per_server(): void
    {
        $this->gatewayDown = true;
        $this->assertSame(['opened: The gateway is unreachable'], $this->check());
    }

    public function test_thresholds_are_the_organisations_own_and_zero_turns_a_rule_off(): void
    {
        $this->sample('web-1.org-1', 10, diskFree: 25);
        $this->assertSame([], $this->check(), '75% is under the default 90%.');

        Alerts::save('org-1', ['offline_minutes' => 3, 'disk_percent' => 70, 'cpu_percent' => 90, 'cpu_minutes' => 10, 'failed_operations' => true]);
        $this->assertSame(["opened: web-1's disk is 75% full"], $this->check());

        Alerts::save('org-1', ['offline_minutes' => 3, 'disk_percent' => 0, 'cpu_percent' => 90, 'cpu_minutes' => 10, 'failed_operations' => true]);
        $this->assertSame(["cleared: web-1's disk is 75% full"], $this->check(), 'Off: the open alert clears.');
    }

    public function test_a_threshold_out_of_range_is_refused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Alerts::save('org-1', ['offline_minutes' => 3, 'disk_percent' => 150, 'cpu_percent' => 90, 'cpu_minutes' => 10]);
    }

    private function tlsSite(string $name, ?string $expires, bool $tls = true): int
    {
        return DB::table('sites')->insertGetId(['project_id' => 1, 'organization_id' => 'org-1', 'node_id' => 'web-1.org-1', 'name' => $name, 'domain' => "{$name}.example.com", 'repository' => 'x/y', 'deploy_key_public' => 'k',
            'tls' => $tls, 'cert_expires_at' => $expires, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_a_certificate_ending_inside_the_window_alerts_once_and_clears_when_it_renews(): void
    {
        $id = $this->tlsSite('shop', now()->addDays(30)->toDateTimeString());
        $this->tlsSite('later', now()->addDays(15)->toDateTimeString());
        $this->tlsSite('unread', null);
        $this->tlsSite('nohttps', now()->addDays(2)->toDateTimeString(), tls: false);
        $this->assertSame([], $this->check(), 'Outside 14 days, unread, or no HTTPS: quiet.');

        DB::table('sites')->where('id', $id)->update(['cert_expires_at' => now()->addDays(10)]);
        $this->assertSame(["opened: shop's certificate expires soon"], $this->check());
        $this->assertSame([], $this->check(), 'Told once.');
        $this->assertStringContainsString('Expires', $this->posted[0]);
        $this->assertStringContainsString("/site?site={$id}", $this->posted[0]);

        DB::table('sites')->where('id', $id)->update(['cert_expires_at' => now()->subDay()]);
        $this->assertSame([], $this->check(), 'Same alert stays open as it lapses.');
        $this->assertSame('warning', DB::table('alerts')->where('key', "certificate:{$id}")->value('severity'), 'Opened at 10 days, so stays what it was.');

        DB::table('sites')->where('id', $id)->update(['cert_expires_at' => now()->addDays(80)]);
        $this->assertSame(["cleared: shop's certificate expires soon"], $this->check());
    }

    public function test_an_already_expired_certificate_alerts_as_critical_and_the_window_is_the_organisations_own(): void
    {
        $id = $this->tlsSite('shop', now()->subDays(2)->toDateTimeString());
        $this->assertSame(["opened: shop's certificate has expired"], $this->check());
        $this->assertSame('critical', DB::table('alerts')->where('key', "certificate:{$id}")->value('severity'));

        DB::table('alerts')->delete();
        DB::table('sites')->where('id', $id)->update(['cert_expires_at' => now()->addDays(40)]);
        $this->assertSame([], $this->check());
        Alerts::save('org-1', ['offline_minutes' => 3, 'disk_percent' => 90, 'cpu_percent' => 90, 'cpu_minutes' => 10, 'certificate_days' => 45, 'failed_operations' => true]);
        $this->assertSame(["opened: shop's certificate expires soon"], $this->check());
    }

    public function test_zero_turns_the_certificate_rule_off_and_clears_what_it_opened(): void
    {
        $this->tlsSite('shop', now()->addDays(3)->toDateTimeString());
        $this->assertCount(1, $this->check());

        Alerts::save('org-1', ['offline_minutes' => 3, 'disk_percent' => 90, 'cpu_percent' => 90, 'cpu_minutes' => 10, 'certificate_days' => 0, 'failed_operations' => true]);
        $this->assertSame(["cleared: shop's certificate expires soon"], $this->check());
        $this->assertSame(0, Alerts::settings('org-1')['certificate_days']);

        $this->expectException(\InvalidArgumentException::class);
        Alerts::save('org-1', ['certificate_days' => 91]);
    }
}
