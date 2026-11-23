<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Services\Services;
use App\Lib\Vault\Vault;
use Illuminate\Support\Facades\DB;

/** Step 2: what a native install sends to the server, and what it leaves behind when it fails. */
final class NativeInstallTest extends ServiceTestCase
{
    public function test_the_install_recipes_are_what_the_definition_says(): void
    {
        $made = $this->install();
        $this->assertSame('succeeded', $made['status'], (string) DB::table('operations')->value('error'));

        $this->assertSame(['service-check-files', 'service-fetch-files', 'service-prepare-files', 'service-run-files', 'service-vhost-files-9000', 'certificate-service-files', 'service-vhost-files-9000', 'service-vhost-files-9001', 'certificate-service-files', 'service-vhost-files-9001', 'service-health-files'],
            array_values(array_filter($this->recipeNames(), fn ($n) => ! str_starts_with($n, 'clear-stale'))));

        // Fetch: download, then sha256, then unpack, in one script, with the temp file removed on any exit.
        $script = $this->steps('service-fetch')['download']['args'][1];
        $curl = strpos($script, 'curl -fsSL');
        $sha = strpos($script, 'sha256sum -c');
        $unzip = strpos($script, 'unzip -q');
        $this->assertTrue($curl < $sha && $sha < $unzip, 'download, check, then unpack');
        $this->assertStringContainsString("trap 'rm -f \"\$tmp\"' EXIT", $script);
        $this->assertStringContainsString('3d9d42bb4cb7a1589266090d4f9f8238465506cdd9d5e897193377dd6f610aac', $script);
        $this->assertStringContainsString('https://github.com/rustfs/rustfs/releases/download/1.0.5/rustfs-linux-x86_64-musl-v1.0.5.zip', $script);
        $this->assertSame(['test', '-e', '/srv/cloud-manager/services/files/releases/1/rustfs'], $this->steps('service-fetch')['download']['unless']);

        // Prepare: no-login user, data folder, env file 0600 sensitive, unit.
        $p = $this->steps('service-prepare');
        $this->assertSame(['useradd', ['--system', '--no-create-home', '--home-dir', '/nonexistent', '--shell', '/usr/sbin/nologin', 'cm-rustfs']], [$p['user']['name'], $p['user']['args']]);
        $this->assertSame(['dir', '/srv/cloud-manager/data/files', '0750', 'cm-rustfs'], [$p['dir-data']['type'], $p['dir-data']['path'], $p['dir-data']['mode'], $p['dir-data']['owner']]);
        $this->assertSame(['/etc/cloud-manager/services/files.env', '0600', true], [$p['env']['path'], $p['env']['mode'], $p['env']['sensitive']]);
        $env = base64_decode($p['env']['content_b64']);
        $access = app(Vault::class)->get('org-1', Services::secretName((int) $made['service_id'], 'access_key'));
        $this->assertStringContainsString('RUSTFS_ACCESS_KEY="'.$access.'"', $env);
        $this->assertStringContainsString('RUSTFS_SECRET_KEY="', $env);

        $this->assertSame('/etc/systemd/system/cm-svc-files.service', $p['unit']['path']);
        $unit = base64_decode($p['unit']['content_b64']);
        foreach (['User=cm-rustfs', 'NoNewPrivileges=yes', 'ProtectSystem=strict', 'PrivateTmp=yes', 'ReadWritePaths=/srv/cloud-manager/data/files', 'MemoryMax=1073741824', 'Restart=on-failure', 'EnvironmentFile=/etc/cloud-manager/services/files.env',
            'ExecStart=/srv/cloud-manager/services/files/releases/1/rustfs --address 127.0.0.1:9000 --console-address 127.0.0.1:9001 /srv/cloud-manager/data/files'] as $line) {
            $this->assertStringContainsString($line, $unit);
        }
        $this->assertStringContainsString("ReadWritePaths=/srv/cloud-manager/data/files\n", $unit, 'a service that does not register keeps its release read-only');

        $this->assertSame('running', $this->steps('service-run')['start']['state']);

        // Proxy: both addresses, HTTPS through certbot, the definition's proxy options and websocket headers.
        $vhost = base64_decode($this->steps('service-vhost-files-9000', 1)['vhost']['content_b64']);
        foreach (['server_name files-s3.example.com;', 'proxy_pass http://127.0.0.1:9000;', 'client_max_body_size 0;', 'proxy_request_buffering off;', 'proxy_set_header Upgrade $http_upgrade;',
            'ssl_certificate /etc/letsencrypt/live/files-s3.example.com/fullchain.pem;', 'return 301 https://$host$request_uri;'] as $line) {
            $this->assertStringContainsString($line, $vhost);
        }
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:9001;', base64_decode($this->steps('service-vhost-files-9001', 1)['vhost']['content_b64']));
        $this->assertSame('certbot', $this->steps('certificate-service')['certbot']['name']);
        $this->assertTrue((bool) DB::table('service_endpoints')->where('port', 9000)->value('tls'));
        $this->assertStringContainsString('/dev/tcp/127.0.0.1/9000', $this->steps('service-health')['health']['args'][1]);

        $row = $this->service();
        $this->assertSame(['running', 'rustfs', 1, 'builtin', '/srv/cloud-manager/data/files', 'cm-rustfs', 'native'], [$row->status, $row->template_id, $row->template_version, $row->template_source, $row->data_path, $row->run_user, $row->runtime]);
        $this->assertEquals(['name' => 'files', 's3_host' => 'files-s3.example.com', 'ui_host' => 'files-ui.example.com', 'storage' => '10GB', 'backup' => 'snapshot'], json_decode($row->inputs, true));
    }

    public function test_a_checksum_mismatch_fails_the_install_and_leaves_nothing_running(): void
    {
        $this->failSteps = ['download' => 'the download does not match the sha256 in the template; nothing was unpacked'];
        $made = $this->install();

        $this->assertSame('failed', $made['status']);
        $row = $this->service();
        $this->assertSame('failed', $row->status);
        $this->assertStringContainsString('does not match the sha256', $row->error);
        $names = $this->recipeNames();
        $this->assertNotContains('service-prepare-files', $names, 'nothing was written after the failed check');
        $this->assertNotContains('service-run-files', $names, 'nothing was started');
        $this->assertContains('service-stop-files', $names);
        $this->assertSame(0, DB::table('service_endpoints')->count());
    }

    public function test_a_failed_health_check_fails_the_install_stops_the_unit_and_keeps_the_data(): void
    {
        $this->failSteps = ['health' => 'no answer on 127.0.0.1:9000 in 60 seconds'];
        $made = $this->install();

        $this->assertSame('failed', $made['status']);
        $this->assertSame('failed', $this->service()->status);
        $this->assertStringContainsString('no answer', DB::table('operations')->value('error'));
        $stop = $this->steps('service-stop');
        $this->assertSame('stopped', $stop['stop']['state']);
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $s) {
                $this->assertFalse($s['type'] === 'absent' && str_starts_with($s['path'] ?? '', '/srv/cloud-manager/data'), 'the data folder is never removed by a failure');
            }
        }
    }

    public function test_a_retry_of_the_same_install_converges(): void
    {
        $this->failSteps = ['health' => 'no answer'];
        $first = $this->install();
        $this->assertSame('failed', $first['status']);
        $access = app(Vault::class)->get('org-1', Services::secretName($first['service_id'], 'access_key'));

        $this->failSteps = [];
        $second = $this->install();

        $this->assertSame('succeeded', $second['status']);
        $this->assertSame($first['service_id'], $second['service_id'], 'the same service, not a second one');
        $this->assertSame(1, DB::table('services')->count());
        $this->assertSame(2, DB::table('service_endpoints')->count());
        $row = $this->service();
        $this->assertSame(['running', null], [$row->status, $row->error]);
        $this->assertSame($access, app(Vault::class)->get('org-1', Services::secretName($first['service_id'], 'access_key')), 'the generated secrets survive a retry');
    }

    public function test_an_install_that_already_exists_is_refused(): void
    {
        $this->install();
        $this->expectExceptionMessage('already exists');
        $this->services()->install('org-1', 'web-1.org-1', 'rustfs', $this->rustfs(), 'm', 'manager', dispatch: false);
    }
}
