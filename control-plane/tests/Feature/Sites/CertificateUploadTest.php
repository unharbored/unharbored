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
use Illuminate\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use InvalidArgumentException;
use Tests\TestCase;

/** Step 3 of certificate control: an uploaded certificate on the primary server, with the key never kept. */
final class CertificateUploadTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{node: string, kind: string, args: array<string, mixed>, source: ?string}> */
    private array $gateway = [];

    /** Node whose upload recipe fails at nginx -t. */
    private ?string $failNginxOn = null;

    /** Node and step id whose recipe fails (a step of that id in the recipe), once armed. */
    private ?array $failStep = null;

    /** @var array{cert: string, key: string} */
    private array $pair;

    /** @var list<string> */
    private array $tmp = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['vault.master_key' => LocalKeyManager::generateMasterKey(), 'control_plane.gateway_token' => 't', 'control_plane.gateway_url' => 'http://gw.test']);
        $this->app->forgetInstance(KeyManager::class);
        $this->app->forgetInstance(Vault::class);
        Queue::fake();
        DB::table('nodes')->insert(['node_id' => 'web-1.org-1', 'organization_id' => 'org-1', 'created_at' => now(), 'updated_at' => now()]);

        $http = new HttpFactory;
        $http->fake(function (ClientRequest $r) use ($http) {
            $node = (string) preg_replace('#.*/nodes/([^/]+)/commands.*#', '$1', $r->url());
            $this->gateway[] = ['node' => $node, 'kind' => (string) ($r['kind'] ?? ''), 'args' => $r['args'] ?? [], 'source' => DB::table('sites')->value('cert_source')];
            $ids = array_column($r['args']['spec']['steps'] ?? [], 'id');
            if ($this->failNginxOn === $node && in_array('cert-key', $ids, true)) {
                return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => 'nginx: [emerg] cannot load certificate "/etc/cloud-manager/certs/shop.example.com/fullchain.pem"']);
            }

            if ($this->failStep !== null && $this->failStep[0] === $node && in_array($this->failStep[1], $ids, true)) {
                return $http->response(['status' => 'failed', 'outcome_known' => true, 'error' => "{$this->failStep[1]} failed"]);
            }

            return $http->response(['status' => 'succeeded', 'outcome_known' => true, 'detail' => ['output' => "notAfter=Feb 12 10:00:00 2037 GMT\n"]]);
        });
        $this->app->instance(GatewayClient::class, new GatewayClient('http://gw.test', 't', $http, new AuditLog));
        $this->app->forgetInstance(Deployer::class);
        $this->app->forgetInstance(OperationRunner::class);

        $this->pair = $this->issue('shop.example.com', ['shop.example.com']);
    }

    protected function tearDown(): void
    {
        foreach ($this->tmp as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    private function site(bool $secondServer = false): object
    {
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'shop', 'shop.example.com', 'git@github.com:acme/shop.git');
        DB::table('sites')->where('id', $site->id)->update(['tls' => true]);
        if ($secondServer) {
            DB::table('site_nodes')->insert(['site_id' => $site->id, 'node_id' => 'web-2.org-1', 'created_at' => now(), 'updated_at' => now()]);
        }

        return DB::table('sites')->where('id', $site->id)->first();
    }

    private function upload(object $site, ?string $chain = null, ?string $key = null): int
    {
        $runner = $this->app->make(OperationRunner::class);

        return $runner->queueCertificateUpload((int) $site->id, $chain ?? $this->pair['cert'], $key ?? $this->pair['key'], 'console', 'me@example.com')['id'];
    }

    private function operate(int $id): string
    {
        return $this->app->make(OperationRunner::class)->run($id);
    }

    /** @return list<array<string, mixed>> the upload recipes, in the order they were sent */
    private function uploads(): array
    {
        return array_values(array_filter($this->gateway, fn ($g) => $g['kind'] === 'apply_recipe' && in_array('cert-key', array_column($g['args']['spec']['steps'], 'id'), true)));
    }

    public function test_the_recipe_has_the_right_paths_modes_and_marks_the_key_sensitive(): void
    {
        $site = $this->site();
        $this->assertSame('succeeded', $this->operate($this->upload($site)), (string) DB::table('operations')->value('error'));

        $steps = array_column($this->uploads()[0]['args']['spec']['steps'], null, 'id');
        $this->assertSame(['dir', '/etc/cloud-manager/certs/shop.example.com', '0750'], [$steps['cert-dir']['type'], $steps['cert-dir']['path'], $steps['cert-dir']['mode']]);
        $this->assertSame(['/etc/cloud-manager/certs/shop.example.com/fullchain.pem', '0644'], [$steps['cert-chain']['path'], $steps['cert-chain']['mode']]);
        $this->assertSame(['/etc/cloud-manager/certs/shop.example.com/privkey.pem', '0600', true], [$steps['cert-key']['path'], $steps['cert-key']['mode'], $steps['cert-key']['sensitive']]);
        $this->assertArrayNotHasKey('sensitive', $steps['cert-chain']);
        $this->assertStringContainsString('openssl pkey', $steps['cert-verify']['args'][1]);

        $order = array_keys($steps);
        $this->assertLessThan(array_search('vhost', $order), array_search('cert-key', $order));
        $this->assertLessThan(array_search('nginx-test', $order), array_search('vhost', $order));
        $this->assertLessThan(array_search('reload-nginx', $order), array_search('nginx-test', $order));
        $vhost = base64_decode($steps['vhost']['content_b64']);
        $this->assertStringContainsString('ssl_certificate /etc/cloud-manager/certs/shop.example.com/fullchain.pem;', $vhost);
        $this->assertStringContainsString('ssl_certificate_key /etc/cloud-manager/certs/shop.example.com/privkey.pem;', $vhost);
        $this->assertStringNotContainsString('letsencrypt', $vhost);
    }

    public function test_the_database_flips_only_after_nginx_accepted_it_and_certbot_stops_renewing_the_name(): void
    {
        $site = $this->site();
        $this->assertSame('succeeded', $this->operate($this->upload($site)));

        $this->assertSame('letsencrypt', $this->uploads()[0]['source'], 'Still Let\'s Encrypt while the files and vhost were applied.');
        $row = DB::table('sites')->where('id', $site->id)->first();
        $this->assertSame('custom', $row->cert_source);
        $this->assertEqualsWithDelta(openssl_x509_parse($this->pair['cert'])['validTo_time_t'], strtotime((string) $row->cert_expires_at.' UTC'), 2, "The certificate's own end date is stored.");

        $certbot = collect($this->gateway)->first(fn ($g) => $g['kind'] === 'apply_recipe' && ($g['args']['spec']['steps'][0]['id'] ?? '') === 'certbot-delete');
        $this->assertSame(['delete', '--cert-name', 'shop.example.com', '--non-interactive'], $certbot['args']['spec']['steps'][0]['args']);
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.certificate_uploaded')->count());
    }

    public function test_only_the_primary_receives_the_key_because_only_its_vhost_has_a_certificate(): void
    {
        $site = $this->site(secondServer: true);
        $this->assertSame('succeeded', $this->operate($this->upload($site)));

        $this->assertSame(['web-1.org-1'], array_column($this->uploads(), 'node'));
        $this->assertSame([], collect($this->gateway)->where('node', 'web-2.org-1')->all(), 'The second server is not touched at all.');
        $sites = $this->app->make(Sites::class);
        $custom = DB::table('sites')->where('id', $site->id)->first();
        $secondVhost = $sites->generatedVhost(Sites::on($custom, 'web-2.org-1'));
        $this->assertStringNotContainsString('ssl_certificate', $secondVhost, 'Nothing on the second server reads a certificate.');
    }

    public function test_an_nginx_failure_rolls_the_primary_back_and_leaves_the_source_unchanged(): void
    {
        $site = $this->site(secondServer: true);
        $this->failNginxOn = 'web-1.org-1';
        $id = $this->upload($site);

        $this->assertSame('failed', $this->operate($id));
        $this->assertStringContainsString('cannot load certificate', (string) DB::table('operations')->where('id', $id)->value('error'));
        $row = DB::table('sites')->where('id', $site->id)->first();
        $this->assertSame('letsencrypt', $row->cert_source);
        $this->assertNull($row->cert_expires_at);

        $restores = collect($this->gateway)->filter(fn ($g) => ($g['args']['spec']['steps'][0]['id'] ?? '') === 'cert-restore');
        $this->assertSame(['web-1.org-1'], $restores->pluck('node')->all());
        $last = collect($this->gateway)->last();
        $this->assertSame('vhost-backup', $last['args']['spec']['steps'][0]['id'], 'The original vhost is applied again after the restore.');
        $this->assertStringNotContainsString('cloud-manager/certs', base64_decode(array_column($last['args']['spec']['steps'], null, 'id')['vhost']['content_b64']));
        $this->assertSame(0, DB::table('audit_records')->where('action', 'site.certificate_uploaded')->count());
        $this->assertFalse(app(Vault::class)->has('org-1', "certificate-upload:{$id}"), 'The held copy is gone after a failure.');
    }

    public function test_a_database_failure_after_the_server_switched_rolls_the_server_back(): void
    {
        $site = $this->site();
        DB::unprepared(DB::connection()->getDriverName() === 'mysql'
            ? "CREATE TRIGGER fail_custom BEFORE UPDATE ON sites FOR EACH ROW BEGIN IF NEW.cert_source = 'custom' AND NOT (OLD.cert_source <=> NEW.cert_source) THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'db down'; END IF; END"
            : "create trigger fail_custom before update of cert_source on sites when new.cert_source = 'custom' begin select raise(abort, 'db down'); end");
        $id = $this->upload($site);

        $this->assertSame('failed', $this->operate($id));
        $this->assertStringContainsString('db down', (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame('letsencrypt', DB::table('sites')->where('id', $site->id)->value('cert_source'));
        $steps = collect($this->gateway)->pluck('args.spec.steps.0.id')->all();
        $this->assertContains('cert-restore', $steps, 'The server is put back, not left serving a certificate the database does not know.');
        $this->assertSame('vhost-backup', collect($this->gateway)->last()['args']['spec']['steps'][0]['id']);
    }

    public function test_the_restore_only_touches_the_directory_when_the_backup_is_complete(): void
    {
        $sites = $this->app->make(Sites::class);
        $root = sys_get_temp_dir().'/certroot'.bin2hex(random_bytes(4));
        $dir = "{$root}/etc/shop.example.com";
        $backup = "{$root}/bk/shop.example.com";
        $run = function (array $recipe) use ($root): void {
            $script = str_replace(['/etc/cloud-manager/certs', '/var/backups/cloud-manager/certs'], ["{$root}/etc", "{$root}/bk"], $recipe['steps'][0]['args'][1]);
            exec('sh -c '.escapeshellarg($script).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        };
        $upload = $sites->uploadCertificateRecipe($this->site(), 'chain', 'key');
        $backupStep = ['steps' => [$upload['steps'][0]]];
        $restore = $sites->restoreCertificateRecipe('shop.example.com');

        // The backup never completed: only a half-copied .tmp exists. The live directory must survive the restore.
        mkdir($dir, 0700, true);
        file_put_contents("{$dir}/privkey.pem", 'live key');
        mkdir("{$backup}.tmp", 0700, true);
        $run($restore);
        $this->assertSame('live key', file_get_contents("{$dir}/privkey.pem"));
        $this->assertDirectoryDoesNotExist("{$backup}.tmp");

        // A completed backup, a changed directory, then restore: the old files come back.
        $run($backupStep);
        file_put_contents("{$dir}/privkey.pem", 'new key');
        $run($restore);
        $this->assertSame('live key', file_get_contents("{$dir}/privkey.pem"));
        $this->assertDirectoryDoesNotExist($backup);

        // No earlier upload: backup, upload writes the directory, restore removes it (and the marker backup).
        exec('rm -rf '.escapeshellarg($dir));
        $run($backupStep);
        mkdir($dir, 0700, true);
        file_put_contents("{$dir}/privkey.pem", 'uploaded');
        $run($restore);
        $this->assertDirectoryDoesNotExist($dir);
        $this->assertDirectoryDoesNotExist($backup);

        exec('rm -rf '.escapeshellarg($root));
    }

    public function test_the_key_is_never_in_the_operation_logs_audit_or_vault_afterwards(): void
    {
        $site = $this->site();
        $id = $this->upload($site);
        $this->assertTrue(app(Vault::class)->has('org-1', "certificate-upload:{$id}"), 'Held while queued.');
        $this->assertSame('succeeded', $this->operate($id));
        $this->assertFalse(app(Vault::class)->has('org-1', "certificate-upload:{$id}"), 'Gone once applied.');

        $body = trim(preg_replace('/-----[A-Z ]+-----/', '', $this->pair['key']));
        $chainBody = trim(preg_replace('/-----[A-Z ]+-----/', '', $this->pair['cert']));
        $needles = [$body, substr(str_replace("\n", '', $body), 40, 60), substr(str_replace("\n", '', $chainBody), 40, 60), base64_encode($this->pair['key'])];
        foreach (Schema::getTableListing(schemaQualified: false) as $tableName) {
            $dump = json_encode(DB::table($tableName)->get()->map(fn ($row) => array_map(fn ($v) => is_string($v) && ! mb_check_encoding($v, 'UTF-8') ? base64_encode($v) : $v, (array) $row))->all());
            foreach ($needles as $needle) {
                $this->assertStringNotContainsString($needle, (string) $dump, "Certificate material found in the {$tableName} table.");
            }
        }
        $logFile = storage_path('logs/laravel.log');
        if (is_file($logFile)) {
            $this->assertStringNotContainsString($needles[1], (string) file_get_contents($logFile));
        }
    }

    public function test_the_key_is_dropped_even_when_the_operation_fails_before_applying(): void
    {
        $site = $this->site();
        $id = $this->upload($site);
        DB::table('sites')->where('id', $site->id)->update(['domain' => 'other.example.com']); // no longer covered
        $this->assertSame('failed', $this->operate($id));

        $this->assertStringContainsString('does not cover other.example.com', (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertFalse(app(Vault::class)->has('org-1', "certificate-upload:{$id}"));
        $this->assertSame([], $this->uploads(), 'Nothing reached a server: the check ran again inside the operation.');
    }

    public function test_bad_uploads_are_refused_before_anything_is_held_or_queued(): void
    {
        $site = $this->site();
        $other = $this->issue('other.example.com', ['other.example.com']);
        foreach ([
            [$this->pair['cert'], $other['key'], 'does not match'],
            [$other['cert'], $other['key'], 'does not cover shop.example.com'],
            ['hello', $this->pair['key'], 'No certificate found'],
        ] as [$chain, $key, $message]) {
            try {
                $this->upload($site, $chain, $key);
                $this->fail('Expected a refusal.');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString($message, $e->getMessage());
            }
        }
        $this->assertSame(0, DB::table('operations')->where('kind', 'certificate_upload')->count());
        $this->assertFalse(app(Vault::class)->has('org-1', 'certificate-upload:1'));

        DB::table('sites')->where('id', $site->id)->update(['tls' => false]);
        $this->expectExceptionMessage('not on HTTPS yet');
        $this->upload($site);
    }

    public function test_the_vhost_follows_cert_source_everywhere_it_is_generated(): void
    {
        $site = $this->site();
        $sites = $this->app->make(Sites::class);
        $this->assertStringContainsString('/etc/letsencrypt/live/shop.example.com/fullchain.pem', $sites->generatedVhost($site));

        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'custom']);
        $custom = DB::table('sites')->where('id', $site->id)->first();
        $vhost = $sites->generatedVhost($custom);
        $this->assertStringContainsString('ssl_certificate /etc/cloud-manager/certs/shop.example.com/fullchain.pem;', $vhost);
        $this->assertStringNotContainsString('letsencrypt/live', $vhost);
        $setup = array_column($sites->setupRecipe($custom)['steps'], null, 'id');
        $this->assertStringContainsString('cloud-manager/certs', base64_decode($setup['vhost']['content_b64']), 'A deploy regenerates it the same way.');

        DB::table('sites')->where('id', $site->id)->update(['load_balancer_id' => 5]);
        $this->assertStringContainsString('ssl_certificate /etc/cloud-manager/certs/shop.example.com/fullchain.pem;', $sites->generatedVhost(DB::table('sites')->where('id', $site->id)->first()), 'Behind a balancer the primary keeps its own 443 block.');
    }

    public function test_after_an_upload_the_balancer_stage_runs_and_the_expiry_read_follows_the_active_path(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['load_balancer_id' => 999]);
        $id = $this->upload($site);
        $this->assertSame('failed', $this->operate($id));
        $this->assertStringContainsString('has no load balancer', (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame('custom', DB::table('sites')->where('id', $site->id)->value('cert_source'), 'The upload itself is committed before the balancer is told.');

        $this->gateway = [];
        $this->app->make(Deployer::class)->readCertificateExpiry(DB::table('sites')->where('id', $site->id)->first());
        $this->assertSame('/etc/cloud-manager/certs/shop.example.com/fullchain.pem', $this->gateway[0]['args']['args'][4]);
    }

    public function test_switching_back_issues_a_fresh_certificate_flips_the_vhost_and_removes_the_files(): void
    {
        $site = $this->site(secondServer: true);
        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'custom']);
        $runner = $this->app->make(OperationRunner::class);
        $id = $runner->queueCertificateRevert((int) $site->id, 'console', 'me@example.com');

        $this->assertSame('succeeded', $runner->run($id), (string) DB::table('operations')->where('id', $id)->value('error'));
        $this->assertSame('letsencrypt', DB::table('sites')->where('id', $site->id)->value('cert_source'));
        $recipes = collect($this->gateway)->where('kind', 'apply_recipe');
        $this->assertNotNull($recipes->first(fn ($g) => ($g['args']['spec']['steps'][0]['id'] ?? '') === 'certbot' && ($g['args']['spec']['steps'][0]['args'][0] ?? '') === 'certonly'));
        $vhosts = $recipes->filter(fn ($g) => in_array('vhost', array_column($g['args']['spec']['steps'], 'id'), true));
        $this->assertSame(['web-1.org-1'], $vhosts->pluck('node')->values()->all(), 'Only the primary\'s vhost has a certificate.');
        $this->assertStringContainsString('letsencrypt/live', base64_decode(array_column($vhosts->first()['args']['spec']['steps'], null, 'id')['vhost']['content_b64']));
        $removed = $recipes->filter(fn ($g) => ($g['args']['spec']['steps'][0]['id'] ?? '') === 'cert-files');
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], $removed->pluck('node')->values()->all());
        $this->assertSame('/etc/cloud-manager/certs/shop.example.com', $removed->first()['args']['spec']['steps'][0]['path']);
        $this->assertSame(1, DB::table('audit_records')->where('action', 'site.certificate_reverted')->count());

        $this->expectExceptionMessage('does not use an uploaded certificate');
        $runner->queueCertificateRevert((int) $site->id, 'console', 'me');
    }

    public function test_a_domain_change_on_a_custom_certificate_site_drops_back_to_letsencrypt_and_removes_the_old_files(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'custom', 'cert_expires_at' => now()->addYear()]);
        $this->app->make(Deployer::class)->moveDomain(DB::table('sites')->where('id', $site->id)->first(), 'new.example.com');

        $row = DB::table('sites')->where('id', $site->id)->first();
        $this->assertSame(['letsencrypt', true], [$row->cert_source, (bool) $row->tls]);
        $paths = collect($this->gateway)->where('kind', 'apply_recipe')->flatMap(fn ($g) => array_column($g['args']['spec']['steps'], 'path'))->all();
        $this->assertContains('/etc/cloud-manager/certs/shop.example.com', $paths);
        $certbot = collect($this->gateway)->flatMap(fn ($g) => $g['args']['spec']['steps'] ?? [])->where('id', 'certbot')->first();
        $this->assertContains('new.example.com', $certbot['args'], 'A fresh certificate for the new name.');
        $this->assertNull(collect($this->gateway)->flatMap(fn ($g) => $g['args']['spec']['steps'] ?? [])->first(fn ($s) => ($s['id'] ?? '') === 'certbot-delete'), 'There is no lineage for the old name to delete.');
    }

    public function test_a_failed_issue_after_a_domain_change_still_removes_the_old_uploaded_key(): void
    {
        $site = $this->site(secondServer: true);
        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'custom', 'cert_expires_at' => now()->addYear()]);
        $this->failStep = ['web-1.org-1', 'certbot'];
        try {
            $this->app->make(Deployer::class)->moveDomain(DB::table('sites')->where('id', $site->id)->first(), 'new.example.com');
            $this->fail('Expected the certificate issue to fail.');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('certbot failed', $e->getMessage());
        }

        $this->assertSame('new.example.com', DB::table('sites')->where('id', $site->id)->value('domain'), 'The database has forgotten the old name.');
        $removed = collect($this->gateway)->filter(fn ($g) => in_array('/etc/cloud-manager/certs/shop.example.com', array_column($g['args']['spec']['steps'] ?? [], 'path'), true));
        $this->assertSame(['web-1.org-1', 'web-2.org-1'], $removed->pluck('node')->sort()->values()->all(), 'The old key is gone from every server although secure() failed.');
        $keys = array_keys($removed->all());
        $firstVhost = collect($this->gateway)->search(fn ($g) => in_array('vhost', array_column($g['args']['spec']['steps'] ?? [], 'id'), true) && $g['node'] === 'web-1.org-1');
        $this->assertLessThan($keys[0], $firstVhost, 'A vhost that no longer reads the key is written before the key is removed.');
    }

    public function test_each_upload_is_held_under_its_own_operation_and_a_second_is_refused_while_one_is_pending(): void
    {
        $site = $this->site();
        $id = $this->upload($site);
        $this->assertTrue(app(Vault::class)->has('org-1', "certificate-upload:{$id}"));

        try {
            $this->upload($site);
            $this->fail('Expected the second upload to be refused.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('already running', $e->getMessage());
        }
        $this->assertTrue(app(Vault::class)->has('org-1', "certificate-upload:{$id}"), 'The pending upload keeps its held copy.');
        $this->assertSame(1, DB::table('operations')->where('kind', 'certificate_upload')->count());
    }

    public function test_when_dispatch_fails_the_held_copy_is_deleted_and_the_operation_does_not_block_the_next_upload(): void
    {
        $site = $this->site();
        $dispatcher = \Mockery::mock(\Illuminate\Contracts\Bus\Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')->andThrow(new \RuntimeException('queue down'));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $dispatcher);
        $this->app->forgetInstance(OperationRunner::class);
        try {
            $this->upload($site);
            $this->fail('Expected the dispatch to fail.');
        } catch (\RuntimeException $e) {
            $this->assertSame('queue down', $e->getMessage());
        }

        $op = DB::table('operations')->where('kind', 'certificate_upload')->first();
        $this->assertSame('failed', $op->status);
        $this->assertFalse(app(Vault::class)->has('org-1', "certificate-upload:{$op->id}"));
        $this->app->instance(\Illuminate\Contracts\Bus\Dispatcher::class, $this->app->make(Dispatcher::class));
        $this->app->forgetInstance(OperationRunner::class);
        $this->assertGreaterThan($op->id, $this->upload($site), 'The failed row does not block the next upload.');
    }

    public function test_a_retry_after_a_late_failure_does_not_issue_again_and_only_an_expired_lineage_is_cleared(): void
    {
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'custom']);
        $runner = $this->app->make(OperationRunner::class);

        // Attempt one: the certificate is there but the vhost switch fails.
        $this->failStep = ['web-1.org-1', 'vhost'];
        $first = $runner->queueCertificateRevert((int) $site->id, 'console', 'me');
        $this->assertSame('failed', $runner->run($first));
        $this->assertSame('custom', DB::table('sites')->where('id', $site->id)->value('cert_source'));

        // Attempt two: nothing in what is sent deletes the lineage, and issuing keeps its file guard.
        $this->failStep = null;
        $this->gateway = [];
        $second = $runner->queueCertificateRevert((int) $site->id, 'console', 'me');
        $this->assertSame('succeeded', $runner->run($second), (string) DB::table('operations')->where('id', $second)->value('error'));
        $steps = collect($this->gateway)->flatMap(fn ($g) => $g['args']['spec']['steps'] ?? []);
        $this->assertNull($steps->first(fn ($st) => ($st['id'] ?? '') === 'certbot-delete'), 'No unconditional certbot delete.');
        $clear = $steps->firstWhere('id', 'certbot-clear-stale');
        $this->assertStringContainsString('-checkend', $clear['args'][1], 'Only a lineage that has expired is cleared.');
        $certbot = $steps->firstWhere('id', 'certbot');
        $this->assertSame(['test', '-f', '/etc/letsencrypt/live/shop.example.com/fullchain.pem'], $certbot['unless'], 'The issue is skipped when a lineage is there.');
    }

    public function test_the_stale_lineage_script_keeps_a_valid_lineage_and_removes_an_expired_one(): void
    {
        $root = sys_get_temp_dir().'/lineage'.bin2hex(random_bytes(4));
        mkdir("{$root}/bin", 0777, true);
        file_put_contents("{$root}/bin/certbot", "#!/bin/sh\necho \"$@\" >> {$root}/certbot.log\n");
        chmod("{$root}/bin/certbot", 0755);
        $script = str_replace('/etc/letsencrypt', "{$root}/le", $this->app->make(Sites::class)->clearStaleCertificateRecipe('shop.example.com')['steps'][0]['args'][1]);
        $run = function () use ($script, $root): void {
            exec('PATH='.escapeshellarg("{$root}/bin").':$PATH sh -c '.escapeshellarg($script).' 2>&1', $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        };
        mkdir("{$root}/le/live/shop.example.com", 0777, true);

        file_put_contents("{$root}/le/live/shop.example.com/fullchain.pem", $this->pair['cert']); // valid for a year
        $run();
        $this->assertFileDoesNotExist("{$root}/certbot.log", 'A valid lineage is kept, so a retry does not issue a duplicate.');

        $expired = $this->issue('shop.example.com', ['shop.example.com'], days: 0);
        file_put_contents("{$root}/le/live/shop.example.com/fullchain.pem", $expired['cert']);
        $run();
        $this->assertStringContainsString('delete --cert-name shop.example.com', (string) @file_get_contents("{$root}/certbot.log"));

        exec('rm -rf '.escapeshellarg($root));
    }

    public function test_removing_a_site_removes_its_uploaded_key(): void
    {
        $site = $this->site();
        $paths = array_column($this->app->make(Sites::class)->removalRecipe($site)['steps'], 'path');
        $this->assertContains('/etc/cloud-manager/certs/shop.example.com', $paths);
        $this->assertContains('/var/backups/cloud-manager/certs/shop.example.com', $paths, 'The copy kept aside during an upload holds the key too.');
    }

    public function test_the_console_shows_validation_errors_a_summary_and_never_the_key_back(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();
        $other = $this->issue('other.example.com', ['other.example.com']);
        $page = '/site?site='.$site->id;

        $this->from($page)->post('/site/certificate/upload', ['site' => $site->id, 'certChain' => $other['cert'], 'certKey' => $other['key']])
            ->assertRedirect($page)->assertSessionHasErrors('certificate');
        // A refusal does not put the key (or the chain) into the flashed input, so it is not kept in the session.
        $this->assertNull(session()->getOldInput('certKey'));
        $this->assertNull(session()->getOldInput('certChain'));
        $this->assertSame(0, DB::table('operations')->where('kind', 'certificate_upload')->count());

        $this->from($page)->post('/site/certificate/upload', ['site' => $site->id, 'certChain' => '', 'certKey' => ''])->assertSessionHasErrors(['certChain', 'certKey']);

        $this->from($page)->post('/site/certificate/upload', ['site' => $site->id, 'certChain' => $this->pair['cert'], 'certKey' => $this->pair['key']])->assertRedirect($page);
        $response = $this->get($page);
        $response->assertInertia(fn (Assert $p) => $p
            ->where('certSummary.issuer', 'shop.example.com')
            ->where('certSummary.names', ['shop.example.com'])
            ->where('certSummary.warnings', fn ($w) => str_contains(json_encode($w), 'no intermediates')));
        $this->assertStringNotContainsString(trim(preg_replace('/-----[A-Z ]+-----/', '', $this->pair['key'])), $response->getContent());
        $this->assertSame(1, DB::table('operations')->where('kind', 'certificate_upload')->count());
    }

    public function test_the_upload_result_carries_its_expiry_already_formatted(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();
        $page = '/site?site='.$site->id;

        $this->from($page)->post('/site/certificate/upload', ['site' => $site->id, 'certChain' => $this->pair['cert'], 'certKey' => $this->pair['key']]);

        $this->get($page)->assertInertia(fn (Assert $p) => $p->where('certSummary.expires_label', fn ($label) => str_contains($label, now()->addYear()->format('Y')))->etc());
    }

    public function test_a_custom_site_shows_custom_certificate_in_place_of_renew_now_and_a_way_back(): void
    {
        $this->actingAs(User::forceCreate(['name' => 'Matt', 'email' => 'matt@example.com', 'password' => 'correct horse battery']));
        $site = $this->site();
        DB::table('sites')->where('id', $site->id)->update(['cert_source' => 'custom']);

        $this->get('/site?site='.$site->id)->assertInertia(fn (Assert $p) => $p
            ->where('view.certCustom', true)->where('view.canRenew', false)->where('view.canInfra', true)
            ->where('view.topology.Network.0.rows.1.1', 'Your own certificate')->etc());
        $this->post('/site/certificate/revert', ['site' => $site->id]);
        $this->assertSame(1, DB::table('operations')->where('kind', 'certificate_revert')->count());
    }

    /** @return array{cert: string, key: string} */
    private function issue(string $cn, array $sans, int $days = 365): array
    {
        $conf = (string) tempnam(sys_get_temp_dir(), 'cut');
        $this->tmp[] = $conf;
        file_put_contents($conf, "[req]\ndefault_bits = 2048\ndistinguished_name = dn\n[dn]\n[ext]\nbasicConstraints = CA:FALSE\nsubjectAltName = ".implode(',', array_map(fn ($s) => "DNS:{$s}", $sans))."\n");
        $pkey = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 2048, 'config' => $conf]);
        $csr = openssl_csr_new(['commonName' => $cn], $pkey, ['config' => $conf, 'digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $pkey, $days, ['config' => $conf, 'x509_extensions' => 'ext', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));
        openssl_x509_export($x509, $cert);
        openssl_pkey_export($pkey, $key, null, ['config' => $conf]);

        return ['cert' => $cert, 'key' => $key];
    }
}
