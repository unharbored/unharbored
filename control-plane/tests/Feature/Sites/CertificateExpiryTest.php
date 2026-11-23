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

/** Step 1 of certificate control: when the certificate ends, read from the server and shown with a colour. */
final class CertificateExpiryTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array<string, mixed>> */
    private array $gateway = [];

    private string $output = "notAfter=Feb 12 10:00:00 2027 GMT\n";

    private bool $failExec = false;

    private bool $failCertbot = false;

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        $this->travelTo(now()->setDate(2026, 12, 13)->setTime(10, 0));
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            $this->gateway[] = ['kind' => $r['kind'] ?? null, 'args' => $r['args'] ?? []];
            if (($r['args']['name'] ?? null) === 'certbot' && $this->failCertbot) {
                return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'too many certificates (5) already issued for this exact set of identifiers in the last 168h0m0s']);
            }
            if (($r['kind'] ?? null) === 'exec' && $this->failExec) {
                return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'no such file']);
            }

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => $this->output]]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog()));
        $this->app->forgetInstance(Deployer::class);
        $this->app->forgetInstance(OperationRunner::class);
    }

    private function site(bool $tls = true): object
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['tls' => $tls]);

        return DB::table('sites')->where('id', $site->id)->first();
    }

    public function test_openssl_end_dates_are_parsed_and_nonsense_is_not(): void
    {
        $this->assertSame('2027-02-12 10:00:00', Deployer::parseCertificateEnd("notAfter=Feb 12 10:00:00 2027 GMT\n")->format('Y-m-d H:i:s'));
        $this->assertSame('2027-02-02 01:02:03', Deployer::parseCertificateEnd("notAfter=Feb  2 01:02:03 2027 GMT")->format('Y-m-d H:i:s'), 'openssl pads single-digit days.');
        $this->assertNull(Deployer::parseCertificateEnd(''));
        $this->assertNull(Deployer::parseCertificateEnd('notAfter=tomorrow-ish'));
        $this->assertNull(Deployer::parseCertificateEnd('Could not open file'));
    }

    public function test_the_operation_reads_the_end_date_from_the_node_stores_it_and_audits_it(): void
    {
        $site = $this->site();
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueCertificateCheck((int) $site->id, 'console', 'me@example.com');

        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $exec = collect($this->gateway)->firstWhere('kind', 'exec');
        $this->assertSame('openssl', $exec['args']['name']);
        $this->assertSame(['x509', '-enddate', '-noout', '-in', '/etc/letsencrypt/live/shop.example.com/fullchain.pem'], $exec['args']['args']);
        $this->assertStringStartsWith('2027-02-12 10:00:00', (string) DB::table('sites')->where('id', $site->id)->value('cert_expires_at'));
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.certificate_checked')->count());
    }

    public function test_a_failed_read_fails_the_operation_and_a_site_without_https_has_no_certificate_to_read(): void
    {
        $runner = $this->app->make(OperationRunner::class);

        $this->output = 'garbage';
        $site = $this->site();
        $this->assertSame('failed', $runner->run($runner->queueCertificateCheck((int) $site->id, 'console', 'me')));
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('cert_expires_at'));

        DB::table('sites')->where('id', $site->id)->update(['tls' => false]);
        $this->output = "notAfter=Feb 12 10:00:00 2027 GMT\n";
        $this->gateway = [];
        $this->assertSame('failed', $runner->run($runner->queueCertificateCheck((int) $site->id, 'console', 'me')));
        $this->assertSame([], $this->gateway, 'The node is not even asked.');
    }

    public function test_issuing_a_certificate_records_the_expiry_and_an_unreadable_one_does_not_fail_issuing(): void
    {
        $site = $this->site(tls: false);
        $secured = $this->app->make(Deployer::class)->secure($site);
        $this->assertTrue((bool) DB::table('sites')->where('id', $site->id)->value('tls'));
        $this->assertStringStartsWith('2027-02-12', (string) DB::table('sites')->where('id', $secured->id)->value('cert_expires_at'));

        DB::table('sites')->where('id', $site->id)->update(['tls' => false, 'cert_expires_at' => null]);
        $this->failExec = true;
        $again = $this->app->make(Deployer::class)->secure(DB::table('sites')->where('id', $site->id)->first());
        $this->assertTrue((bool) $again->tls, 'Issued, even though the date could not be read.');
        $this->assertNull(DB::table('sites')->where('id', $site->id)->value('cert_expires_at'));
    }

    public function test_labels_and_colours(): void
    {
        $at = fn (int $days, int $hours = 0) => now()->addDays($days)->addHours($hours)->toDateTimeString();

        $this->assertSame(['label' => 'unknown', 'class' => 'faint', 'days' => null], Sites::certificateStatus(null));
        $this->assertSame(['label' => 'Expires 12 Feb (in 61 days)', 'class' => 'ok'], array_intersect_key(Sites::certificateStatus('2027-02-12 10:00:00'), ['label' => 1, 'class' => 1]));
        $this->assertSame('ok', Sites::certificateStatus($at(21))['class']);
        $this->assertSame('warn', Sites::certificateStatus($at(20, 23))['class']);
        $this->assertSame('warn', Sites::certificateStatus($at(7))['class']);
        $this->assertSame('bad', Sites::certificateStatus($at(6, 23))['class']);
        $this->assertStringContainsString('(today)', Sites::certificateStatus($at(0, 5))['label']);
        $gone = Sites::certificateStatus(now()->subDays(3)->toDateTimeString());
        $this->assertSame('bad', $gone['class']);
        $this->assertStringContainsString('Expired', $gone['label']);
        $this->assertStringContainsString('3 days ago', $gone['label']);
    }

    public function test_the_site_page_shows_the_expiry_and_refresh_queues_the_check(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();

        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.cert.label', fn ($l) => str_contains($l, 'unknown'))->etc());

        DB::table('sites')->where('id', $site->id)->update(['cert_expires_at' => now()->addDays(5)]);
        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.cert.label', fn ($l) => str_contains($l, 'in 5 days'))->etc());

        $this->post('/site/certificate/check', ['site' => $site->id])->assertRedirect();
        $this->assertSame(1, DB::table('operations')->where('kind', 'certificate_check')->where('site_id', $site->id)->count());

        DB::table('sites')->where('id', $site->id)->update(['tls' => false]);
        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.cert', null)->etc());
        $this->post('/site/certificate/check', ['site' => $site->id])->assertNotFound();
    }

    public function test_renew_now_runs_certbot_without_a_shell_rereads_the_expiry_and_audits(): void
    {
        $site = $this->site();
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueCertificateRenew((int) $site->id, 'console', 'me@example.com');

        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $execs = collect($this->gateway)->where('kind', 'exec')->values();
        $this->assertSame('certbot', $execs[0]['args']['name']);
        $this->assertSame(['renew', '--cert-name', 'shop.example.com', '--force-renewal', '--no-random-sleep-on-renew', '--deploy-hook', 'systemctl reload nginx'], $execs[0]['args']['args']);
        $this->assertSame('openssl', $execs[1]['args']['name']);
        $this->assertStringStartsWith('2027-02-12', (string) DB::table('sites')->where('id', $site->id)->value('cert_expires_at'));
        $audit = DB::table('audit_records')->where('action', 'site.certificate_renewed')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString('2027-02-12', (string) $audit->metadata);
    }

    public function test_a_certbot_failure_shows_its_text_and_is_not_retried(): void
    {
        $site = $this->site();
        $runner = $this->app->make(OperationRunner::class);
        $this->failCertbot = true;
        $id = $runner->queueCertificateRenew((int) $site->id, 'console', 'me');

        $this->assertSame('failed', $runner->run($id));
        $this->assertStringContainsString('too many certificates', (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertCount(1, collect($this->gateway)->where('args.name', 'certbot'));
        $this->assertSame(0, DB::table('audit_records')->where('action', 'site.certificate_renewed')->count());
    }

    public function test_renew_is_refused_without_https_or_for_an_uploaded_certificate_before_the_node_is_touched(): void
    {
        $runner = $this->app->make(OperationRunner::class);
        $site = $this->site(tls: false);
        try {
            $runner->queueCertificateRenew((int) $site->id, 'console', 'me');
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('HTTPS is off', $e->getMessage());
        }

        DB::table('sites')->where('id', $site->id)->update(['tls' => true, 'cert_source' => 'custom']);
        try {
            $runner->queueCertificateRenew((int) $site->id, 'console', 'me');
            $this->fail('Expected a refusal.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('upload a new certificate instead', $e->getMessage());
        }
        $this->assertSame([], $this->gateway);
        $this->assertSame(0, DB::table('operations')->where('kind', 'certificate_renew')->count());
    }

    public function test_the_balancer_stage_runs_only_when_the_site_has_a_balancer(): void
    {
        $site = $this->site();
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueCertificateRenew((int) $site->id, 'console', 'me');
        $runner->run($id);
        $this->assertNull(collect($this->gateway)->first(fn ($g) => str_contains(json_encode($g), 'balancer')));
        $this->assertSame('succeeded', DB::table('operations')->where('id', $id)->value('status'));

        // A balancer id with no balancer behind it: the stage runs and says so (LoadBalancers is final, so it is not faked).
        DB::table('sites')->where('id', $site->id)->update(['load_balancer_id' => 999]);
        $id = $runner->queueCertificateRenew((int) $site->id, 'console', 'me');
        $this->assertSame('failed', $runner->run($id));
        $this->assertStringContainsString('has no load balancer', (string) DB::table('operations')->where('id', $id)->value('error'));
    }

    public function test_a_second_renewal_or_check_is_refused_while_one_is_queued_or_running(): void
    {
        $site = $this->site();
        $runner = $this->app->make(OperationRunner::class);
        $runner->queueCertificateRenew((int) $site->id, 'console', 'me');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('already running');
        $runner->queueCertificateRenew((int) $site->id, 'console', 'me');
    }

    public function test_a_queued_check_blocks_a_renewal_too(): void
    {
        $site = $this->site();
        $runner = $this->app->make(OperationRunner::class);
        $runner->queueCertificateCheck((int) $site->id, 'console', 'me');

        $this->expectException(\InvalidArgumentException::class);
        $runner->queueCertificateRenew((int) $site->id, 'console', 'me');
    }

    public function test_developers_and_above_may_renew(): void
    {
        $this->assertFalse(\App\Lib\Auth\Roles::can(\App\Lib\Auth\Roles::VIEWER, 'deploy'));
        $this->assertTrue(\App\Lib\Auth\Roles::can(\App\Lib\Auth\Roles::DEVELOPER, 'deploy'));
        $this->assertTrue(\App\Lib\Auth\Roles::can(\App\Lib\Auth\Roles::MANAGER, 'deploy'));
        $this->assertTrue(\App\Lib\Auth\Roles::can(\App\Lib\Auth\Roles::OWNER, 'deploy'));
    }

    public function test_the_renew_button_shows_only_for_letsencrypt_https_and_queues_the_operation(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();

        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.canRenew', true)->where('view.canDeploy', true)->etc());
        $this->post('/site/certificate/renew', ['site' => $site->id])->assertRedirect();
        $this->assertSame(1, DB::table('operations')->where('kind', 'certificate_renew')->where('site_id', $site->id)->count());

        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'custom']);
        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.canRenew', false)->etc());

        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'letsencrypt', 'tls' => false]);
        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $page) => $page->where('view.canRenew', false)->etc());
    }
}
