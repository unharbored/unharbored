<?php

declare(strict_types=1);

namespace Tests\Feature\Cloud;

use App\Lib\Agent\AgentRelease;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\AutoscalePools;
use App\Lib\Cloud\CloudInit;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Enrollment\Exceptions\EnrollmentRejected;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Sites\Sites;
use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use InvalidArgumentException;
use phpseclib3\Crypt\EC;
use phpseclib3\File\X509;
use stdClass;
use Tests\TestCase;

/**
 * Servers DigitalOcean adds on its own must prove which Droplet they are,
 * come up on the live release without building, and leave when it removes
 * them.
 */
final class AutoscalePoolsTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    private HttpFactory $http;

    /** @var list<array<string, mixed>> */
    private array $members = [];

    /** @var list<array<string, mixed>> */
    private array $tagged = [];

    /** @var list<Request> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pool-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        file_put_contents("{$this->dir}/cloud-manager-agent-linux-amd64", 'amd64 build');
        config([
            'vault.master_key' => LocalKeyManager::generateMasterKey(),
            'control_plane.agent_directory' => $this->dir,
            'control_plane.public_url' => 'https://agent.example.com',
            'control_plane.server_name' => 'agent.example.com',
        ]);
        $this->http = new HttpFactory;
        $this->app->instance(HttpFactory::class, $this->http);
        foreach ([KeyManager::class, Vault::class, AgentRelease::class, CloudInit::class, CredentialStore::class, AutoscalePools::class, EnrollmentService::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $this->app->instance(CredentialStore::class, new CredentialStore($this->app->make(Vault::class), $this->http, Cache::store('array'), new AuditLog));
        Cache::store('array')->clear();

        $this->http->fake(function (Request $r) {
            $this->sent[] = $r;
            $url = $r->url();

            return match (true) {
                str_contains($url, '/account/keys') && $r->method() === 'GET' => $this->http->response(['ssh_keys' => []]),
                str_contains($url, '/account/keys') => $this->http->response(['ssh_key' => ['id' => 9]], 201),
                str_ends_with($url, '/droplets/autoscale') => $this->http->response(['autoscale_pool' => ['id' => 'pool-uuid']], 202),
                str_ends_with($url, '/droplets/autoscale/pool-uuid') && $r->method() === 'GET' => $this->http->response(['autoscale_pool' => [
                    'name' => 'cm-shop-pool', 'config' => ['min_instances' => 1, 'max_instances' => 3], 'droplet_template' => ['size' => 's-1vcpu-1gb']]]),
                str_contains($url, '/members') => $this->http->response(['droplets' => $this->members]),
                str_contains($url, 'tag_name=') => $this->http->response(['droplets' => $this->tagged]),
                default => $this->http->response([], 204),
            };
        });
        DB::table('organizations')->insert(['id' => 'org-1', 'name' => 'Org', 'created_at' => now(), 'updated_at' => now()]);
        $this->app->make(CredentialStore::class)->connect('org-1', 'dop_v1_test_token');
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob("{$this->dir}/*"));
        rmdir($this->dir);
        parent::tearDown();
    }

    private function site(bool $ready = true): stdClass
    {
        $resources = new ResourceManager(new AuditLog);
        $web1 = $resources->declare('org-1', 'server', 'web-1', ['region' => 'nyc3']);
        $resources->recordExternalId($web1, '111');
        $site = (new Sites($this->app->make(Vault::class), new AuditLog))->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        if ($ready) {
            DB::table('sites')->where('id', $site->id)->update(['load_balancer_id' => 1, 'bucket_id' => 2, 'database_cluster_id' => 3, 'current_release' => 'r1']);
        }

        return DB::table('sites')->find($site->id);
    }

    private function pools(): AutoscalePools
    {
        return $this->app->make(AutoscalePools::class);
    }

    /** The token the pool's members boot with, read back out of their user_data. */
    private function poolToken(): string
    {
        $create = collect($this->sent)->first(fn (Request $r) => str_ends_with($r->url(), '/droplets/autoscale'));
        preg_match('#enroll\.token\n\s+owner: root:root\n\s+permissions: .0600.\n\s+content: (\S+)#', $create['droplet_template']['user_data'], $m);

        return $m[1];
    }

    private function csr(): string
    {
        $csr = new X509;
        $csr->setPrivateKey(EC::createKey('secp256r1'));
        $csr->setDN(['CN' => 'whatever-i-like']);

        return $csr->saveCSR($csr->signCSR());
    }

    public function test_a_site_autoscales_only_once_everything_it_needs_is_off_the_server(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a load balancer');
        $this->pools()->create($this->site(ready: false), ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
    }

    public function test_a_pool_boots_members_with_its_token_and_a_key_nobody_holds(): void
    {
        $site = $this->site();
        $pool = $this->pools()->create($site, ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);

        $key = collect($this->sent)->first(fn (Request $r) => $r->method() === 'POST' && str_contains($r->url(), '/account/keys'));
        $this->assertStringStartsWith('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5', $key['public_key']);
        $create = collect($this->sent)->first(fn (Request $r) => str_ends_with($r->url(), '/droplets/autoscale'));
        $template = $create['droplet_template'];
        $this->assertSame(['9'], $template['ssh_keys']);
        $this->assertSame('nyc3', $template['region']);
        $this->assertTrue($template['with_droplet_agent']);
        $this->assertContains('resource:shop-pool', $template['tags']);
        $this->assertStringNotContainsString('hostname:', $template['user_data'], 'DigitalOcean names pool Droplets.');
        $this->assertSame(['min_instances' => 1, 'max_instances' => 3, 'target_cpu_utilization' => 0.6, 'cooldown_minutes' => 5], $create['config']);

        $this->assertSame(ResourceState::Active, $pool->state);
        $this->assertSame($pool->id, (int) DB::table('sites')->where('id', $site->id)->value('autoscale_pool_id'));
        $this->assertSame(hash('sha256', $this->poolToken()), DB::table('enrollment_grants')->where('autoscale_pool_id', $pool->id)->value('token_hash'));
    }

    public function test_a_member_enrolls_once_as_the_droplet_with_its_address_and_is_queued_to_join(): void
    {
        Queue::fake();
        $this->pools()->create($this->site(), ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
        $this->members = [['droplet_id' => 555, 'status' => 'active']];
        $this->tagged = [
            ['id' => 555, 'networks' => ['v4' => [['type' => 'private', 'ip_address' => '10.0.0.9'], ['type' => 'public', 'ip_address' => '203.0.113.9']]]],
            ['id' => 777, 'networks' => ['v4' => [['type' => 'public', 'ip_address' => '203.0.113.7']]]],
        ];
        $enrollment = $this->app->make(EnrollmentService::class);
        $token = $this->poolToken();

        $result = $enrollment->enroll($token, $this->csr(), ['hostname' => 'cm-shop-pool-x'], '203.0.113.9');

        $this->assertSame('shop-555.org-1', $result->nodeId, 'Named after the Droplet DigitalOcean says has the address, not the CSR.');
        $droplet = (new ResourceManager(new AuditLog))->find('org-1', 'server', 'shop-555');
        $this->assertSame(['555', ResourceState::Active], [$droplet->externalId, $droplet->state]);
        $op = DB::table('operations')->where('kind', 'pool_member')->sole();
        $this->assertSame('shop-555.org-1', $op->node_id);

        foreach (['203.0.113.9' => 'enrolled already', '203.0.113.7' => 'tagged but not a member', '198.51.100.1' => 'not in the pool at all'] as $ip => $why) {
            try {
                $enrollment->enroll($token, $this->csr(), [], $ip);
                $this->fail("{$ip} should be refused: {$why}.");
            } catch (EnrollmentRejected) {
            }
        }
        $this->assertSame(1, DB::table('operations')->where('kind', 'pool_member')->count());
    }

    public function test_members_digitalocean_removed_are_found_to_leave(): void
    {
        $site = $this->site();
        $pool = $this->pools()->create($site, ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
        foreach (['shop-555.org-1' => $pool->id, 'shop-556.org-1' => $pool->id, 'shop-557.org-1' => $pool->id, 'web-2.org-1' => null] as $node => $p) {
            DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => $node, 'autoscale_pool_id' => $p, 'created_at' => now(), 'updated_at' => now()]);
        }
        Queue::fake();
        $this->pools()->enrolled($pool->id, '558', 'shop-558.org-1');
        $this->members = [['droplet_id' => 555, 'status' => 'active'], ['droplet_id' => 556, 'status' => 'deleting']];

        $this->assertSame(['shop-556.org-1', 'shop-557.org-1', 'shop-558.org-1'], $this->pools()->departed(DB::table('sites')->find($site->id)),
            'shop-558 enrolled and was removed before it joined.');
    }

    public function test_a_member_whose_join_failed_is_retried_after_ten_minutes(): void
    {
        Queue::fake();
        $site = $this->site();
        $pool = $this->pools()->create($site, ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
        $this->pools()->enrolled($pool->id, '555', 'shop-555.org-1');
        DB::table('nodes')->insert(['node_id' => 'shop-555.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);
        $site = DB::table('sites')->find($site->id);

        $this->assertSame([], $this->pools()->retryStranded($site), 'Still being prepared.');

        DB::table('operations')->update(['status' => 'failed', 'finished_at' => now()->subMinutes(5)]);
        $this->assertSame([], $this->pools()->retryStranded($site), 'Failed only just now.');

        DB::table('operations')->update(['finished_at' => now()->subMinutes(11)]);
        $this->assertSame(['shop-555.org-1'], $this->pools()->retryStranded($site));
        $this->assertSame(2, DB::table('operations')->where('kind', 'pool_member')->count());
    }

    public function test_a_window_raises_the_minimum_only_while_it_is_open(): void
    {
        $pool = ['min' => 1, 'max' => 5, 'schedule' => [['days' => 'weekdays', 'from' => 13, 'to' => 22, 'min' => 3]]];
        $at = fn (string $when) => AutoscalePools::minAt($pool, new \DateTimeImmutable($when, new \DateTimeZone('UTC')));

        $this->assertSame(3, $at('2026-11-06 13:00'), 'Friday, as it opens.');
        $this->assertSame(3, $at('2026-11-06 21:59'));
        $this->assertSame(1, $at('2026-11-06 22:00'), 'The end hour is not in the window.');
        $this->assertSame(1, $at('2026-11-06 12:59'));
        $this->assertSame(1, $at('2026-11-07 15:00'), 'Saturday is not a weekday.');
        $this->assertSame(3, AutoscalePools::minAt($pool, new \DateTimeImmutable('2026-11-06 09:00', new \DateTimeZone('America/New_York'))),
            '09:00 in New York is 14:00 UTC.');
    }

    public function test_a_window_past_midnight_belongs_to_the_day_it_starts(): void
    {
        $pool = ['min' => 1, 'max' => 5, 'schedule' => [['days' => 'fri', 'from' => 22, 'to' => 2, 'min' => 4]]];
        $at = fn (string $when) => AutoscalePools::minAt($pool, new \DateTimeImmutable($when, new \DateTimeZone('UTC')));

        $this->assertSame(4, $at('2026-11-06 23:00'), 'Friday night.');
        $this->assertSame(4, $at('2026-11-07 01:30'), 'Early Saturday is still Friday night.');
        $this->assertSame(1, $at('2026-11-07 02:00'));
        $this->assertSame(1, $at('2026-11-07 23:00'), 'Saturday night is not Friday night.');
        $this->assertSame(1, $at('2026-11-06 01:00'), 'Early Friday belongs to Thursday.');
    }

    public function test_overlapping_windows_take_the_largest_and_nothing_goes_past_the_maximum(): void
    {
        $pool = ['min' => 1, 'max' => 3, 'schedule' => [
            ['days' => 'every', 'from' => 0, 'to' => 0, 'min' => 2],
            ['days' => 'every', 'from' => 9, 'to' => 17, 'min' => 9],
        ]];
        $at = fn (string $when) => AutoscalePools::minAt($pool, new \DateTimeImmutable($when, new \DateTimeZone('UTC')));

        $this->assertSame(2, $at('2026-11-06 03:00'), 'Equal hours run all day.');
        $this->assertSame(3, $at('2026-11-06 10:00'), 'Nine asked for; the pool stops at three.');
    }

    public function test_the_minutely_reconcile_resizes_only_when_the_schedule_wants_a_change(): void
    {
        Queue::fake();
        $site = $this->site();
        $pool = $this->pools()->create($site, ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
        $this->pools()->addSchedule(DB::table('sites')->find($site->id), 'weekdays', 13, 22, 2);
        $resizes = fn () => DB::table('operations')->where('kind', 'pool_resize')->get();

        CarbonImmutable::setTestNow('2026-11-06 08:00:00');
        Carbon::setTestNow('2026-11-06 08:00:00');
        $this->artisan('sites:reconcile-pools')->assertSuccessful();
        $this->assertCount(0, $resizes(), 'Outside the window the pool already has its minimum.');

        CarbonImmutable::setTestNow('2026-11-06 14:00:00');
        Carbon::setTestNow('2026-11-06 14:00:00');
        $this->artisan('sites:reconcile-pools')->assertSuccessful();
        $this->artisan('sites:reconcile-pools')->assertSuccessful();
        $this->assertCount(1, $resizes(), 'Once, not again while the first is still queued.');
        $meta = json_decode((string) $resizes()->first()->meta, true);
        $this->assertEquals(['min' => 2, 'max' => 3, 'scheduled' => true], $meta);

        // It ran: DigitalOcean has 2, and the minimum set by hand is still 1.
        DB::table('operations')->where('kind', 'pool_resize')->update(['status' => 'succeeded']);
        $this->pools()->resize(DB::table('sites')->find($site->id), 2, 3, scheduled: true);
        $attributes = (new ResourceManager(new AuditLog))->findById($pool->id)->attributes;
        $this->assertSame([1, 2], [$attributes['min'], $attributes['live_min']]);
        $this->artisan('sites:reconcile-pools')->assertSuccessful();
        $this->assertCount(1, $resizes(), 'Already right: nothing more to do.');

        CarbonImmutable::setTestNow('2026-11-06 22:30:00');
        Carbon::setTestNow('2026-11-06 22:30:00');
        $this->artisan('sites:reconcile-pools')->assertSuccessful();
        $this->assertSame(1, json_decode((string) $resizes()->last()->meta, true)['min'], 'Back to the set minimum once the window closes.');
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
    }

    public function test_a_window_cannot_ask_for_more_than_the_pool_allows(): void
    {
        $site = $this->site();
        $this->pools()->create($site, ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);

        $this->expectException(InvalidArgumentException::class);
        $this->pools()->addSchedule(DB::table('sites')->find($site->id), 'every', 9, 17, 4);
    }

    public function test_removing_the_pool_deletes_every_droplet_it_made_and_its_token(): void
    {
        $site = $this->site();
        $pool = $this->pools()->create($site, ['min' => 1, 'max' => 3, 'size' => 's-1vcpu-1gb', 'cpu' => 0.6]);
        $this->members = [['droplet_id' => 555, 'status' => 'active']];

        $nodes = $this->pools()->destroy(DB::table('sites')->find($site->id));

        $this->assertSame(['shop-555.org-1'], $nodes);
        $deleted = array_map(fn (Request $r) => parse_url($r->url(), PHP_URL_PATH), array_filter($this->sent, fn (Request $r) => $r->method() === 'DELETE'));
        $this->assertSame(['/v2/droplets/autoscale/pool-uuid', '/v2/droplets/555'], array_values($deleted));
        $this->assertSame(0, DB::table('enrollment_grants')->where('autoscale_pool_id', $pool->id)->count());
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('autoscale_pool_id'));
    }
}
