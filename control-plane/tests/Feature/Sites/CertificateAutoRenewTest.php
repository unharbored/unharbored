<?php

declare(strict_types=1);

namespace Tests\Feature\Sites;

use App\Lib\Alerts\Alerts;
use App\Lib\Audit\AuditLog;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\RunOperation;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/** Step 4 of certificate control: automatic renewal on or off, and the daily expiry read. */
final class CertificateAutoRenewTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    private bool $failSh = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 12, 13)->setTime(10, 0));
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $http = new HttpFactory;
        $http->fake(function (ClientRequest $r) use ($http) {
            $this->gateway[] = ['kind' => $r['kind'] ?? null, 'args' => $r['args'] ?? [], 'spec' => $r['args']['spec'] ?? null];
            if ($this->failSh && ($r['kind'] ?? null) === 'apply_recipe') {
                return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'exit status 1']);
            }

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => "notAfter=Feb 12 10:00:00 2027 GMT\n"]]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog));
        $this->app->forgetInstance(Deployer::class);
        $this->app->forgetInstance(OperationRunner::class);
    }

    private function site(string $name = 'shop', array $set = []): object
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', $name, "{$name}.example.com", 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(array_merge(['tls' => true], $set));

        return DB::table('sites')->where('id', $site->id)->first();
    }

    private function runner(): OperationRunner
    {
        return $this->app->make(OperationRunner::class);
    }

    public function test_turning_it_off_edits_the_renewal_conf_with_quoted_paths_then_flips_the_database_and_audits(): void
    {
        $site = $this->site();
        $id = $this->runner()->queueCertificateAutoRenew((int) $site->id, false, 'console', 'me@example.com');

        $this->assertTrue((bool) DB::table('sites')->where('id', $site->id)->value('cert_auto_renew'), 'Nothing changes until the node has done it.');
        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));

        $call = collect($this->gateway)->firstWhere('kind', 'apply_recipe');
        $step = $call['spec']['steps'][0];
        $this->assertSame('sh', $step['name']);
        $conf = "'/etc/letsencrypt/renewal/shop.example.com.conf'";
        $this->assertSame(['-c', "test -f {$conf} && grep -q '^\\[renewalparams\\]' {$conf} && sed -i '/^autorenew[[:space:]]*=/d;/^\\[renewalparams\\]/a autorenew = False' {$conf}"], $step['args']);
        $this->assertFalse((bool) DB::table('sites')->where('id', $site->id)->value('cert_auto_renew'));
        $audit = DB::table('audit_records')->where('action', 'site.certificate_autorenew')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('"auto_renew":false', (string) $audit->metadata);
    }

    public function test_turning_it_back_on_writes_true(): void
    {
        $site = $this->site(set: ['cert_auto_renew' => false]);
        $id = $this->runner()->queueCertificateAutoRenew((int) $site->id, true, 'console', 'me');

        $this->assertSame('succeeded', $this->runner()->run($id));
        $args = collect($this->gateway)->firstWhere('kind', 'apply_recipe')['spec']['steps'][0]['args'];
        $this->assertStringContainsString('autorenew = True', $args[1]);
        $this->assertTrue((bool) DB::table('sites')->where('id', $site->id)->value('cert_auto_renew'));
    }

    public function test_a_failure_on_the_node_leaves_the_setting_and_the_audit_alone(): void
    {
        $site = $this->site();
        $this->failSh = true;
        $id = $this->runner()->queueCertificateAutoRenew((int) $site->id, false, 'console', 'me');

        $this->assertSame('failed', $this->runner()->run($id));
        $this->assertTrue((bool) DB::table('sites')->where('id', $site->id)->value('cert_auto_renew'));
        $this->assertSame(0, DB::table('audit_records')->where('action', 'site.certificate_autorenew')->count());
    }

    public function test_custom_certificates_and_sites_without_https_are_refused_before_the_node_is_touched(): void
    {
        $custom = $this->site('custom', ['cert_source' => 'custom']);
        $plain = $this->site('plain', ['tls' => false]);
        foreach ([$custom, $plain] as $site) {
            try {
                $this->runner()->queueCertificateAutoRenew((int) $site->id, false, 'console', 'me');
                $this->fail('Expected a refusal.');
            } catch (\InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], $this->gateway);
        $this->assertSame(0, DB::table('operations')->where('kind', 'certificate_autorenew')->count());
    }

    public function test_it_is_refused_while_another_certificate_operation_is_running_and_blocks_one_too(): void
    {
        $site = $this->site();
        $this->runner()->queueCertificateAutoRenew((int) $site->id, false, 'console', 'me');

        try {
            $this->runner()->queueCertificateAutoRenew((int) $site->id, true, 'console', 'me');
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        }
        $this->expectException(\InvalidArgumentException::class);
        $this->runner()->queueCertificateRenew((int) $site->id, 'console', 'me');
    }

    public function test_renew_now_does_not_depend_on_the_setting(): void
    {
        $site = $this->site(set: ['cert_auto_renew' => false]);
        $id = $this->runner()->queueCertificateRenew((int) $site->id, 'console', 'me');

        $this->assertSame('succeeded', $this->runner()->run($id));
        $this->assertContains('--force-renewal', collect($this->gateway)->firstWhere('args.name', 'certbot')['args']['args']);
    }

    public function test_the_alert_rule_ignores_the_setting(): void
    {
        $site = $this->site(set: ['cert_auto_renew' => false, 'cert_expires_at' => now()->addDays(5)]);
        DB::table('organizations')->insertOrIgnore(['id' => 'org-1', 'name' => 'Acme', 'created_at' => now(), 'updated_at' => now()]);

        $conditions = new \ReflectionMethod(Alerts::class, 'conditions');
        $this->assertArrayHasKey("certificate:{$site->id}", $conditions->invoke(app(Alerts::class), 'org-1'));
    }

    public function test_upload_domain_change_and_switching_back_reset_it_to_on(): void
    {
        $sites = $this->app->make(Sites::class);
        $site = $this->site(set: ['cert_auto_renew' => false]);
        $sites->setCertificateSource($site, 'custom', now()->addDays(90));
        $this->assertTrue((bool) DB::table('sites')->where('id', $site->id)->value('cert_auto_renew'));

        DB::table('sites')->where('id', $site->id)->update(['cert_auto_renew' => false]);
        $sites->changeDomain(DB::table('sites')->where('id', $site->id)->first(), 'new.example.com');
        $this->assertTrue((bool) DB::table('sites')->where('id', $site->id)->value('cert_auto_renew'));

        $other = $this->site('other', ['cert_source' => 'custom', 'cert_auto_renew' => false]);
        $id = $this->runner()->queueCertificateRevert((int) $other->id, 'console', 'me');
        $this->assertSame('succeeded', $this->runner()->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertTrue((bool) DB::table('sites')->where('id', $other->id)->value('cert_auto_renew'));
    }

    public function test_the_page_shows_the_toggle_and_the_off_label_and_the_button_queues_the_opposite(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();

        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.canRenew', true)->where('view.autoRenew', true)->etc());
        $this->post('/site/certificate/auto-renew', ['site' => $site->id])->assertRedirect();
        $op = DB::table('operations')->where('kind', 'certificate_autorenew')->first();
        $this->assertNotNull($op);
        $this->assertSame(['on' => false], json_decode((string) $op->meta, true));

        DB::table('operations')->delete();
        DB::table('sites')->where('id', $site->id)->update(['cert_auto_renew' => false]);
        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.canRenew', true)->where('view.autoRenew', false)->etc());

        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'custom']);
        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.canRenew', false)->where('view.certCustom', true)->etc());
    }

    public function test_the_daily_command_queues_each_letsencrypt_https_site_spread_out_and_skips_busy_ones(): void
    {
        $a = $this->site('a');
        $b = $this->site('b');
        $busy = $this->site('busy');
        $this->site('custom', ['cert_source' => 'custom']);
        $this->site('plain', ['tls' => false]);
        $this->runner()->queueCertificateRenew((int) $busy->id, 'console', 'me');
        DB::table('operations')->where('kind', 'certificate_check')->delete();

        $this->artisan('certificates:check')->expectsOutputToContain('skipped')->assertSuccessful();

        $checks = DB::table('operations')->where('kind', 'certificate_check')->orderBy('id')->get();
        $this->assertSame([(int) $a->id, (int) $b->id], $checks->pluck('site_id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame('schedule', $checks[0]->trigger);
        $delays = [];
        Queue::assertPushed(RunOperation::class, function ($job) use (&$delays) {
            $delays[$job->operationId] = $job->delay;

            return true;
        });
        $this->assertNull($delays[$checks[0]->id], 'The first starts at once.');
        $this->assertNotNull($delays[$checks[1]->id], 'The next waits, so the nodes are not all asked together.');

        // Run again straight away: both are still queued, so nothing piles on.
        $this->artisan('certificates:check')->expectsOutputToContain('0 check(s) queued')->assertSuccessful();
    }

    public function test_one_failing_site_does_not_stop_the_rest_and_a_scheduled_read_is_not_audited(): void
    {
        $first = $this->site('first');
        $second = $this->site('second');
        DB::unprepared(DB::connection()->getDriverName() === 'mysql'
            ? "CREATE TRIGGER boom BEFORE INSERT ON operations FOR EACH ROW BEGIN IF NEW.title LIKE '%first%' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'database is down'; END IF; END"
            : "CREATE TRIGGER boom BEFORE INSERT ON operations WHEN NEW.title LIKE '%first%' BEGIN SELECT RAISE(ABORT, 'database is down'); END");

        $this->artisan('certificates:check')->expectsOutputToContain('database is down')->assertFailed();
        $this->assertSame([(int) $second->id], DB::table('operations')->where('kind', 'certificate_check')->pluck('site_id')->map(fn ($i) => (int) $i)->all());

        $id = (int) DB::table('operations')->where('kind', 'certificate_check')->value('id');
        $this->assertSame('succeeded', $this->runner()->run($id));
        $this->assertStringStartsWith('2027-02-12', (string) DB::table('sites')->where('id', $second->id)->value('cert_expires_at'));
        $this->assertSame(0, DB::table('audit_records')->where('action', 'site.certificate_checked')->count());
    }
}
