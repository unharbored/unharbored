<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Containers\Containers;
use App\Lib\Containers\Docker;
use App\Lib\Services\ContainerRecipes;
use App\Lib\Services\Services;
use App\Lib\Sites\Sites;
use App\Lib\Vault\Vault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Step 4: preparing a server for containers, and the container runner behind the service operations. */
final class ContainersTest extends ServiceTestCase
{
    private const KUMA = ['name' => 'kuma', 'host' => 'kuma.example.com'];

    private const DIGEST = '7554dba313c84e44fee921ef3b5626ba1c711e412e82815e8c957517026112a9';

    private function containers(): Containers
    {
        return $this->app->make(Containers::class);
    }

    private function prepared(string $node = 'web-1.org-1'): void
    {
        DB::table('nodes')->where('node_id', $node)->update(['containers_ready_at' => now(), 'docker_version' => '29.0.0']);
    }

    private function kuma(array $inputs = self::KUMA, string $node = 'web-1.org-1'): array
    {
        return $this->install('uptime-kuma', $inputs, $node);
    }

    private function prepare(bool $ack = false, string $node = 'web-1.org-1'): string
    {
        return $this->operate($this->containers()->prepare('org-1', $node, 'm@example.com', 'manager', $ack, false));
    }

    /** @return list<string> */
    private function allArgs(): array
    {
        $out = [];
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $s) {
                foreach ($s['args'] ?? [] as $a) {
                    $out[] = (string) $a;
                }
            }
        }

        return $out;
    }

    // ---- server_containers --------------------------------------------------

    public function test_preparing_a_server_sends_exactly_these_recipes_and_records_the_flag(): void
    {
        $this->execOutput = fn ($name, $args) => "##version\n29.0.1\n##ps\n##stats\n##df\n";
        $this->assertSame('succeeded', $this->prepare(), (string) DB::table('operations')->value('error'));

        $this->assertSame(['containers-check', 'containers-install', 'containers-configure', 'containers-verify'], $this->recipeNames());

        $check = $this->steps('containers-check')['os']['args'][1];
        $this->assertStringContainsString('ubuntu|debian', $check);
        $this->assertStringContainsString('docker.io', $check);

        $install = $this->steps('containers-install');
        $key = $install['docker-key']['args'][1];
        $this->assertStringContainsString('"$got" = "9DC858229FC7DD38854AE2D88D81803C0EBFCD88"', $key);
        $this->assertStringContainsString('gpg --show-keys --with-colons', $key);
        $this->assertLessThan(strpos($key, 'install -m 0644'), strpos($key, '"$got" ='), 'the fingerprint is checked before the key is installed');
        $this->assertStringContainsString('https://download.docker.com/linux/$ID/gpg', $key);
        $this->assertStringContainsString('signed-by=/etc/apt/keyrings/docker.asc', $install['docker-source']['args'][1]);
        $this->assertStringContainsString('$VERSION_CODENAME stable', $install['docker-source']['args'][1]);
        $this->assertNotEmpty($install['docker-source']['unless'], 'the repository is not rewritten when it is there');
        foreach (['docker-ce', 'docker-ce-cli', 'containerd.io', 'docker-buildx-plugin'] as $p) {
            $this->assertSame(['package', $p], [$install["pkg-{$p}"]['type'], $install["pkg-{$p}"]['name']], 'package steps are idempotent by nature');
        }

        $conf = $this->steps('containers-configure');
        $daemon = json_decode(base64_decode($conf['daemon-json']['content_b64']), true);
        $this->assertSame(['live-restore' => true, 'log-driver' => 'json-file', 'log-opts' => ['max-size' => '10m', 'max-file' => '3']], $daemon);
        $this->assertArrayNotHasKey('hosts', $daemon, 'no TCP socket');
        $this->assertArrayNotHasKey('iptables', $daemon, 'Docker keeps its own firewall rules');
        $this->assertNotEmpty($conf['restart']['unless'], 'the daemon is restarted only when its settings changed');
        $this->assertStringContainsString('LiveRestoreEnabled', $this->steps('containers-verify')['verify']['args'][1]);
        $this->assertStringContainsString('2375', $this->steps('containers-verify')['verify']['args'][1]);

        $node = DB::table('nodes')->where('node_id', 'web-1.org-1')->first();
        $this->assertNotNull($node->containers_ready_at);
        $this->assertSame('29.0.1', $node->docker_version);
        $audit = DB::table('audit_records')->where('action', 'server.containers_prepared')->get();
        $this->assertCount(1, $audit);
        $this->assertSame(['docker_version' => '29.0.1', 'small_server_acknowledged' => false], json_decode($audit[0]->metadata, true));
    }

    public function test_a_failed_check_leaves_the_server_unprepared(): void
    {
        $this->failSteps = ['os' => 'Docker is set up on Ubuntu and Debian servers only; this one runs Fedora'];
        $this->assertSame('failed', $this->prepare());
        $this->assertStringContainsString('Ubuntu and Debian servers only', DB::table('operations')->value('error'));
        $this->assertNull(DB::table('nodes')->where('node_id', 'web-1.org-1')->value('containers_ready_at'));
        $this->assertSame(['containers-check'], $this->recipeNames());
    }

    /** The key step is real shell: run it with a stub curl that serves a local file, and gpg for real. */
    private function runKeyStep(string $served): array
    {
        $dir = sys_get_temp_dir().'/cm-key-'.uniqid();
        mkdir($dir.'/bin', 0777, true);
        file_put_contents($dir.'/bin/curl', "#!/bin/sh\nwhile [ \$# -gt 0 ]; do if [ \"\$1\" = -o ]; then out=\$2; fi; shift; done\ncp ".escapeshellarg($served)." \"\$out\"\n");
        file_put_contents($dir.'/bin/install', "#!/bin/sh\necho INSTALLED > ".escapeshellarg($dir.'/installed')."\n");
        file_put_contents($dir.'/os-release', "ID=ubuntu\nVERSION_CODENAME=noble\n");
        chmod($dir.'/bin/curl', 0755);
        chmod($dir.'/bin/install', 0755);
        $script = str_replace('. /etc/os-release', '. '.$dir.'/os-release', Docker::install()['steps'][4]['args'][1]);
        exec('PATH='.escapeshellarg($dir.'/bin').':$PATH sh -c '.escapeshellarg($script).' 2>&1', $out, $code);
        $installed = file_exists($dir.'/installed');
        array_map('unlink', glob($dir.'/bin/*'));
        array_map('unlink', array_filter(glob($dir.'/*'), 'is_file'));
        @rmdir($dir.'/bin');
        @rmdir($dir);

        return [$code, implode("\n", $out), $installed];
    }

    public function test_the_pinned_fingerprint_is_verified_against_the_real_key_and_a_different_key_is_refused(): void
    {
        exec('command -v gpg', $o, $has);
        if ($has !== 0) {
            $this->markTestSkipped('gpg is not installed here');
        }
        [$code, $out, $installed] = $this->runKeyStep(__DIR__.'/../../fixtures/docker-release.asc');
        $this->assertSame([0, true], [$code, $installed], $out);

        [$code, $out, $installed] = $this->runKeyStep(__DIR__.'/../../fixtures/other-key.asc');
        $this->assertSame(1, $code);
        $this->assertFalse($installed, 'a key with another fingerprint is never installed');
        $this->assertStringContainsString('B97B0AFCAA1A47F044F244A07FCC7D46ACCC4CF8, not the pinned 9DC858229FC7DD38854AE2D88D81803C0EBFCD88', $out);
    }

    public function test_preparing_is_idempotent_a_second_run_sends_the_same_steps_and_does_not_fail(): void
    {
        $this->assertSame('succeeded', $this->prepare());
        $first = $this->recipes;
        $this->recipes = [];
        $this->assertSame('succeeded', $this->prepare());
        $this->assertSame($first, $this->recipes);
        $this->assertSame(2, DB::table('audit_records')->where('action', 'server.containers_prepared')->count());
    }

    public function test_a_small_server_is_refused_without_the_acknowledgement_and_accepted_with_it(): void
    {
        $this->node('small.org-1', 'org-1');
        DB::table('node_metrics')->where('node_id', 'small.org-1')->update(['mem_total' => 983 * 1024 ** 2]);
        $ops = DB::table('operations')->count();
        try {
            $this->containers()->prepare('org-1', 'small.org-1', 'm', 'manager', false, false);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('roughly 100 MB', $e->getMessage());
        }
        $this->assertSame($ops, DB::table('operations')->count());

        $this->assertSame('succeeded', $this->prepare(true, 'small.org-1'));
        $audit = DB::table('audit_records')->where('action', 'server.containers_prepared')->first();
        $this->assertTrue(json_decode($audit->metadata, true)['small_server_acknowledged']);

        // A server with 2 GB needs nothing, and the flag is recorded as not used.
        $this->node('mid.org-1', 'org-1', memGb: 2);
        $id = $this->containers()->prepare('org-1', 'mid.org-1', 'm', 'manager', true, false);
        $this->assertFalse(json_decode((string) DB::table('operations')->where('id', $id)->value('meta'), true)['acknowledged_small']);
    }

    public function test_who_may_prepare_and_which_servers(): void
    {
        $this->expectOperationsUnchanged(function () {
            foreach (['developer', 'viewer'] as $role) {
                try {
                    $this->containers()->prepare('org-1', 'web-1.org-1', 'd', $role, false, false);
                    $this->fail("{$role} must be refused");
                } catch (AuthorizationException) {
                }
            }
            $this->node('other.org-2', 'org-2');
            foreach ([['org-1', 'other.org-2'], ['org-1', 'nope.org-1']] as [$org, $node]) {
                try {
                    $this->containers()->prepare($org, $node, 'm', 'manager', false, false);
                    $this->fail('another team\'s server is not found');
                } catch (InvalidArgumentException $e) {
                    $this->assertStringContainsString('no server', $e->getMessage());
                }
            }
            $this->connected = false;
            try {
                $this->containers()->prepare('org-1', 'web-1.org-1', 'm', 'manager', false, false);
                $this->fail('an offline server is refused');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('not online', $e->getMessage());
            }
        });
    }

    private function expectOperationsUnchanged(\Closure $do): void
    {
        $n = DB::table('operations')->count();
        $do();
        $this->assertSame($n, DB::table('operations')->count());
    }

    public function test_a_second_container_operation_on_the_server_is_refused_while_one_is_queued(): void
    {
        $this->containers()->prepare('org-1', 'web-1.org-1', 'm', 'manager', false, false);
        $this->expectExceptionMessage('already running');
        $this->containers()->prepare('org-1', 'web-1.org-1', 'm', 'manager', false, false);
    }

    // ---- cleanup and the read --------------------------------------------------

    public function test_cleanup_runs_only_the_two_prune_commands(): void
    {
        $this->prepared();
        $this->execOutput = fn ($name, $args) => $name === 'docker' ? "Total reclaimed space: 12.5MB\n" : "##version\n29.0.0\n##ps\n##stats\n##df\n";
        $id = $this->containers()->cleanup('org-1', 'web-1.org-1', 'm@example.com', 'manager', false);
        $this->assertSame('succeeded', $this->operate($id), (string) DB::table('operations')->value('error'));

        $docker = array_values(array_filter($this->execs, fn ($e) => $e['name'] === 'docker'));
        $this->assertSame([['image', 'prune', '-f'], ['builder', 'prune', '-f']], array_column($docker, 'args'));
        $this->assertSame([], $this->recipes, 'no recipe, so no volume, container or file was touched');
        foreach ($this->execs as $e) {
            $this->assertStringNotContainsString('volume', implode(' ', $e['args']));
            $this->assertStringNotContainsString('system prune', implode(' ', $e['args']));
            $this->assertStringNotContainsString('-a', implode(' ', $docker ? array_merge(...array_column($docker, 'args')) : []));
        }
        $audit = DB::table('audit_records')->where('action', 'server.containers_cleaned')->get();
        $this->assertSame('12.5MB', json_decode($audit[0]->metadata, true)['images_reclaimed']);
    }

    public function test_cleanup_needs_a_prepared_server_and_the_infrastructure_role(): void
    {
        try {
            $this->containers()->cleanup('org-1', 'web-1.org-1', 'm', 'manager', false);
            $this->fail('unprepared');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not prepared', $e->getMessage());
        }
        $this->prepared();
        $this->expectException(AuthorizationException::class);
        $this->containers()->cleanup('org-1', 'web-1.org-1', 'd', 'developer', false);
    }

    public function test_the_read_is_parsed_cached_on_the_node_and_throttled(): void
    {
        $this->prepared();
        $calls = 0;
        $this->execOutput = function () use (&$calls) {
            $calls++;

            return implode("\n", ['##version', '29.0.0', '##ps',
                json_encode(['Names' => 'cm-svc-kuma', 'State' => 'running', 'Status' => 'Up 2 minutes', 'Image' => 'louislam/uptime-kuma@sha256:'.self::DIGEST]),
                '##stats', json_encode(['Name' => 'cm-svc-kuma', 'MemUsage' => '90MiB / 512MiB']),
                '##df', json_encode(['Type' => 'Images', 'TotalCount' => '2', 'Size' => '600MB', 'Reclaimable' => '100MB (16%)']), '']);
        };
        $status = $this->containers()->refresh('org-1', 'web-1.org-1');
        $this->assertSame(['cm-svc-kuma', 'running', '90MiB / 512MiB'], [$status['containers'][0]['name'], $status['containers'][0]['state'], $status['containers'][0]['memory']]);
        $this->assertSame('100MB (16%)', $status['df'][0]['reclaimable']);

        $this->containers()->refresh('org-1', 'web-1.org-1');
        $this->assertSame(1, $calls, 'a read under ten seconds old is not repeated');
        $this->assertSame(0, count($this->recipes));
        // status() never runs a command
        $this->containers()->status('org-1', 'web-1.org-1');
        $this->assertSame(1, $calls);
    }

    // ---- the container install -------------------------------------------------

    public function test_a_container_template_is_refused_on_a_server_that_is_not_prepared(): void
    {
        try {
            $this->services()->install('org-1', 'web-1.org-1', 'uptime-kuma', self::KUMA, 'm', 'manager', dispatch: false);
            $this->fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('not prepared for containers', $e->getMessage());
        }
        $this->assertSame(0, DB::table('services')->count());
        $this->assertSame([], $this->recipes);
    }

    public function test_the_install_recipes_are_exactly_the_hardened_container(): void
    {
        $this->prepared();
        $made = $this->kuma();
        $this->assertSame('succeeded', $made['status'], (string) DB::table('operations')->value('error'));

        $this->assertSame(['service-check-kuma', 'service-fetch-kuma', 'service-prepare-kuma', 'service-run-kuma', 'service-vhost-kuma-23000', 'certificate-service-kuma', 'service-vhost-kuma-23000', 'service-health-kuma'],
            array_values(array_filter($this->recipeNames(), fn ($n) => ! str_starts_with($n, 'clear-stale'))));

        // fetch: pulled by digest, then verified, one script
        $pull = $this->steps('service-fetch')['pull']['args'][1];
        $ref = 'louislam/uptime-kuma@sha256:'.self::DIGEST;
        $this->assertStringContainsString("docker pull --quiet '{$ref}'", $pull);
        $this->assertStringContainsString("docker image inspect --format '{{range .RepoDigests}}{{println .}}{{end}}' '{$ref}' | grep -Fq '@sha256:".self::DIGEST."'", $pull);
        $this->assertStringContainsString('nothing was started', $pull);
        $this->assertStringNotContainsString(':2.5.11', $pull, 'the tag is not what is pulled');

        // prepare: env file 0600 sensitive, named volume
        $p = $this->steps('service-prepare');
        $this->assertSame(['/etc/cloud-manager/services/kuma.env', '0600', true], [$p['env']['path'], $p['env']['mode'], $p['env']['sensitive']]);
        $this->assertSame(['volume', 'create', '--label', 'cloud-manager.service=kuma', 'cm-svc-kuma-kuma'], $p['volume-0']['args']);
        $this->assertSame(['docker', 'volume', 'inspect', 'cm-svc-kuma-kuma'], $p['volume-0']['unless']);

        // run: the old container goes, the new one has every flag
        $run = $this->steps('service-run');
        $this->assertStringContainsString('docker rm -f', $run['remove-old']['args'][1]);
        $this->assertSame('docker', $run['run']['name']);
        $args = $run['run']['args'];
        $this->assertSame(['run', '-d', '--name', 'cm-svc-kuma', '--restart', 'unless-stopped', '--label', 'cloud-manager.service=kuma', '--env-file', '/etc/cloud-manager/services/kuma.env',
            '--memory', '536870912', '--memory-swap', '536870912', '--pids-limit', '512', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges', '--cap-add', 'DAC_OVERRIDE', '--cpus', '1',
            '-v', 'cm-svc-kuma-kuma:/app/data', '-p', '127.0.0.1:23000:3001', $ref], $args);
        $joined = implode(' ', $args);
        foreach (['--privileged', ' --network', ' --pid ', 'SYS_ADMIN', 'docker.sock', '--read-only', ' -p 3001', '0.0.0.0'] as $never) {
            $this->assertStringNotContainsString($never, $joined);
        }

        // proxy: nginx upstream is the ALLOCATED host port, never the container's
        $vhost = base64_decode($this->steps('service-vhost-kuma-23000', 1)['vhost']['content_b64']);
        $this->assertStringContainsString('proxy_pass http://127.0.0.1:23000;', $vhost);
        $this->assertStringNotContainsString('3001', $vhost);
        $this->assertStringContainsString('/dev/tcp/127.0.0.1/23000', $this->steps('service-health')['health']['args'][1]);
        $this->assertStringContainsString("docker inspect -f '{{.State.Running}}' 'cm-svc-kuma'", $this->steps('service-health')['health']['args'][1]);

        $row = $this->service('kuma');
        $this->assertSame(['running', 'container', 'uptime-kuma', $ref, ''], [$row->status, $row->runtime, $row->template_id, $row->image, $row->run_user]);
        $e = DB::table('service_endpoints')->first();
        $this->assertSame([23000, 3001, 'kuma.example.com', 1], [(int) $e->port, (int) $e->container_port, $e->domain, (int) $e->tls]);
        $audit = DB::table('audit_records')->where('action', 'service.installed')->first();
        $this->assertSame('sha256:'.self::DIGEST, json_decode($audit->metadata, true)['digest']);
    }

    public function test_a_read_only_template_gets_a_read_only_root_with_a_tmpfs(): void
    {
        $this->prepared();
        $dir = sys_get_temp_dir().'/cm-ro-'.uniqid();
        mkdir($dir);
        file_put_contents($dir.'/ro-box.yml', str_replace(['id: uptime-kuma', 'read_only: false'], ['id: ro-box', 'read_only: true'], (string) file_get_contents(base_path('templates/uptime-kuma.yml'))));
        config(['templates.path' => $dir, 'templates.sources' => [['kind' => 'builtin'], ['kind' => 'local']], 'templates.allow_custom' => true]);
        $this->app->forgetInstance(\App\Lib\Templates\Sources::class);
        $this->app->forgetInstance(Services::class);
        $this->app->forgetInstance(\App\Lib\Services\ServiceRunner::class);
        $this->app->forgetInstance(\App\Lib\Operations\OperationRunner::class);

        $made = $this->install('ro-box', self::KUMA);
        @unlink($dir.'/ro-box.yml');
        @rmdir($dir);
        $this->assertSame('succeeded', $made['status'], (string) DB::table('operations')->value('error'));
        $args = $this->steps('service-run')['run']['args'];
        $at = array_search('--read-only', $args, true);
        $this->assertNotFalse($at);
        $this->assertSame(['--tmpfs', '/tmp:rw,noexec,nosuid,size=64m'], array_slice($args, $at + 1, 2));
    }

    public function test_a_digest_mismatch_fails_the_install_and_nothing_runs(): void
    {
        $this->prepared();
        $this->failSteps = ['pull' => 'the pulled image does not carry the digest in the template (sha256:7554dba313c84e44...); nothing was started'];
        $made = $this->kuma();

        $this->assertSame('failed', $made['status']);
        $this->assertSame('failed', $this->service('kuma')->status);
        $this->assertStringContainsString('digest', $this->service('kuma')->error);
        $names = $this->recipeNames();
        $this->assertNotContains('service-prepare-kuma', $names);
        $this->assertNotContains('service-run-kuma', $names);
        $this->assertContains('service-stop-kuma', $names);
    }

    public function test_a_failed_health_check_removes_the_container_and_keeps_the_volume(): void
    {
        $this->prepared();
        $this->failSteps = ['health' => 'no answer on 127.0.0.1:23000 in 60 seconds'];
        $made = $this->kuma();

        $this->assertSame('failed', $made['status']);
        $stop = $this->steps('service-stop');
        $this->assertStringContainsString("docker rm -f 'cm-svc-kuma'", $stop['stop']['args'][1]);
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $s) {
                $this->assertStringNotContainsString('volume rm', implode(' ', $s['args'] ?? []), 'the volume is never removed by a failure');
                $this->assertStringNotContainsString('xargs', implode(' ', $s['args'] ?? []));
            }
        }
        $this->assertSame('failed', $this->service('kuma')->status);
    }

    public function test_a_retry_converges_on_one_container_and_the_same_port(): void
    {
        $this->prepared();
        $this->failSteps = ['health' => 'no answer'];
        $first = $this->kuma();
        $this->assertSame('failed', $first['status']);

        $this->failSteps = [];
        $this->recipes = [];
        $second = $this->kuma();
        $this->assertSame('succeeded', $second['status']);
        $this->assertSame($first['service_id'], $second['service_id']);
        $this->assertSame(1, DB::table('services')->count());
        $this->assertSame([23000], DB::table('service_endpoints')->pluck('port')->map(fn ($p) => (int) $p)->all());
        $this->assertStringContainsString('docker rm -f', $this->steps('service-run')['remove-old']['args'][1], 'the old container is removed before the new one is run');
        $this->assertSame('running', $this->service('kuma')->status);
    }

    // ---- start, stop, restart, remove --------------------------------------------

    private function installedKuma(): void
    {
        $this->prepared();
        $this->assertSame('succeeded', $this->kuma()['status'], (string) DB::table('operations')->value('error'));
        $this->recipes = [];
    }

    public function test_start_stop_and_restart_use_docker(): void
    {
        $this->installedKuma();
        foreach (['stop' => 'stopped', 'start' => 'running', 'restart' => 'running'] as $action => $status) {
            $id = $this->services()->action('org-1', 'web-1.org-1', 'kuma', $action, 'd@example.com', 'developer', dispatch: false);
            $this->assertSame('succeeded', $this->operate($id));
            $step = $this->steps("service-{$action}")[$action];
            $this->assertSame(['docker', [$action, 'cm-svc-kuma']], [$step['name'], $step['args']]);
            $this->assertSame($status, $this->service('kuma')->status);
        }
        $this->expectException(AuthorizationException::class);
        $this->services()->action('org-1', 'web-1.org-1', 'kuma', 'stop', 'v', 'viewer', dispatch: false);
    }

    public function test_remove_keeping_the_data_never_touches_the_volume(): void
    {
        $this->installedKuma();
        $op = $this->services()->remove('org-1', 'web-1.org-1', 'kuma', false, 'm@example.com', 'manager', dispatch: false);
        $this->assertSame('succeeded', $this->operate($op), (string) DB::table('operations')->where('id', $op)->value('error'));

        $this->assertStringContainsString('docker rm -f', $this->steps('service-remove-unit')['stop']['args'][1]);
        $this->assertSame('/etc/cloud-manager/services/kuma.env', $this->steps('service-remove-unit')['env']['path']);
        $this->assertNotContains('service-remove-files-kuma', $this->recipeNames());
        $this->assertStringNotContainsString('volume rm', implode(' ', $this->allArgs()));
        $this->assertNull($this->service('kuma'));
        $this->assertSame(0, DB::table('service_endpoints')->count());
        $this->assertSame('kept', json_decode(DB::table('audit_records')->where('action', 'service.removed')->value('metadata'), true)['data']);
    }

    public function test_remove_deleting_the_data_removes_this_services_volumes_only(): void
    {
        $this->installedKuma();
        $op = $this->services()->remove('org-1', 'web-1.org-1', 'kuma', true, 'm@example.com', 'manager', dispatch: false);
        $this->assertSame('succeeded', $this->operate($op));
        $script = $this->steps('service-remove-files')['volumes']['args'][1];
        $this->assertSame("docker volume ls -q --filter 'label=cloud-manager.service=kuma' | xargs -r docker volume rm", $script);

        $this->expectExceptionMessage('keep it, or delete it');
        $this->services()->remove('org-1', 'web-1.org-1', 'kuma', null, 'm', 'manager', dispatch: false);
    }

    // ---- secrets ------------------------------------------------------------------

    public function test_a_secret_is_only_in_the_vault_and_the_sensitive_env_file(): void
    {
        $this->prepared();
        $dir = sys_get_temp_dir().'/cm-sec-'.uniqid();
        mkdir($dir);
        $yaml = (string) file_get_contents(base_path('templates/uptime-kuma.yml'));
        $yaml = str_replace(['id: uptime-kuma', "  env: {}\n", "inputs:\n"], ['id: secret-box', "  env: { ADMIN_TOKEN: \"{{ secrets.token }}\" }\n", "secrets:\n  - { key: token, generate: password }\ninputs:\n"], $yaml);
        file_put_contents($dir.'/secret-box.yml', $yaml);
        config(['templates.path' => $dir, 'templates.sources' => [['kind' => 'builtin'], ['kind' => 'local']], 'templates.allow_custom' => true]);
        foreach ([\App\Lib\Templates\Sources::class, Services::class, \App\Lib\Services\ServiceRunner::class, \App\Lib\Operations\OperationRunner::class] as $c) {
            $this->app->forgetInstance($c);
        }
        $made = $this->install('secret-box', self::KUMA);
        @unlink($dir.'/secret-box.yml');
        @rmdir($dir);
        $this->assertSame('succeeded', $made['status'], (string) DB::table('operations')->value('error'));

        $secret = app(Vault::class)->get('org-1', Services::secretName($made['service_id'], 'token'));
        $this->assertSame(40, strlen($secret));
        $env = $this->steps('service-prepare')['env'];
        $this->assertTrue($env['sensitive']);
        $this->assertStringContainsString("ADMIN_TOKEN={$secret}\n", base64_decode($env['content_b64']), 'docker --env-file takes NAME=value literally');
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $s) {
                if ($s['id'] !== 'env') {
                    $this->assertStringNotContainsString($secret, json_encode($s));
                }
            }
        }
        foreach (['operations', 'audit_records', 'services', 'service_endpoints', 'log_lines'] as $table) {
            $this->assertStringNotContainsString($secret, (string) json_encode(DB::table($table)->get()), "the secret is in {$table}");
        }
    }

    // ---- host ports ---------------------------------------------------------------

    public function test_host_ports_are_allocated_unique_and_skip_what_the_node_already_uses(): void
    {
        $this->prepared();
        $site = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'api', 'api.example.com', 'git@github.com:acme/api.git');
        DB::table('sites')->where('id', $site->id)->update(['runtime' => 'node', 'runtime_config' => json_encode(['port' => 23000])]);
        $other = $this->app->make(Sites::class)->create('org-1', 'web-1.org-1', 'ws', 'ws.example.com', 'git@github.com:acme/ws.git');
        DB::table('sites')->where('id', $other->id)->update(['daemons' => json_encode([['name' => 'ws', 'command' => 'x', 'processes' => 1, 'port' => 23001]])]);

        $a = $this->kuma();
        $b = $this->kuma(['name' => 'kuma2', 'host' => 'kuma2.example.com']);
        $this->assertSame('succeeded', $a['status']);
        $this->assertSame('succeeded', $b['status']);
        $this->assertSame([23002, 23003], DB::table('service_endpoints')->orderBy('port')->pluck('port')->map(fn ($p) => (int) $p)->all());
        $this->assertSame(['127.0.0.1:23002:3001', '127.0.0.1:23003:3001'], array_map(fn ($n) => array_values(array_filter($this->steps('service-run', $n)['run']['args'], fn ($x) => str_starts_with($x, '127.')))[0], [0, 1]));
        $this->assertArrayHasKey(23002, $this->services()->usedPorts('web-1.org-1'));
        $this->assertStringContainsString('kuma', $this->services()->usedPorts('web-1.org-1')[23003]);
    }

    public function test_a_full_port_range_is_refused_and_another_node_has_its_own(): void
    {
        $this->prepared();
        $this->node('web-9.org-1', 'org-1');
        $this->prepared('web-9.org-1');
        $rows = [];
        for ($p = ContainerRecipes::PORT_MIN; $p <= ContainerRecipes::PORT_MAX; $p++) {
            $rows[] = ['service_id' => 999, 'node_id' => 'web-1.org-1', 'port' => $p, 'domain' => 'x', 'tls' => false, 'created_at' => now(), 'updated_at' => now()];
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('service_endpoints')->insert($chunk);
        }
        DB::table('services')->insert(['id' => 999, 'organization_id' => 'org-1', 'project_id' => 1, 'node_id' => 'web-1.org-1', 'template_id' => 'x', 'template_source' => 'builtin', 'template_version' => 1, 'name' => 'full',
            'runtime' => 'native', 'inputs' => '{}', 'status' => 'running', 'data_path' => '/x', 'run_user' => 'x', 'created_by' => 'x', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('failed', $this->kuma()['status']);
        $this->assertStringContainsString('is taken', DB::table('operations')->orderByDesc('id')->value('error'));

        $this->assertSame('succeeded', $this->kuma(['name' => 'kuma9', 'host' => 'k9.example.com'], 'web-9.org-1')['status']);
        $this->assertSame(23000, (int) DB::table('service_endpoints')->where('node_id', 'web-9.org-1')->value('port'));
    }

    public function test_another_teams_service_and_node_cannot_be_used(): void
    {
        $this->installedKuma();
        DB::table('organizations')->insertOrIgnore(['id' => 'org-2', 'name' => 'Globex', 'created_at' => now(), 'updated_at' => now()]);
        $this->node('web-2.org-2', 'org-2');
        $this->prepared('web-2.org-2');
        foreach ([
            fn () => $this->services()->action('org-2', 'web-1.org-1', 'kuma', 'stop', 'x', 'owner', dispatch: false),
            fn () => $this->services()->remove('org-2', 'web-1.org-1', 'kuma', false, 'x', 'owner', dispatch: false),
            fn () => $this->services()->install('org-2', 'web-1.org-1', 'uptime-kuma', ['name' => 'k2', 'host' => 'k2.example.com'], 'x', 'owner', dispatch: false),
            fn () => $this->containers()->cleanup('org-2', 'web-1.org-1', 'x', 'owner', false),
            fn () => $this->containers()->refresh('org-2', 'web-1.org-1'),
        ] as $try) {
            try {
                $try();
                $this->fail('another team must not reach this');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('no ', strtolower($e->getMessage()));
            }
        }
    }

    // ---- the firewall's extra ports -----------------------------------------------

    public function test_approved_extra_ports_are_added_to_the_firewall_only_when_it_is_on(): void
    {
        $git = ['name' => 'git', 'host' => 'git.example.com'];
        $made = $this->services()->install('org-1', 'web-1.org-1', 'gitea', $git, 'm', 'manager', dispatch: false, portsApproved: true);
        \App\Lib\Servers\Firewall::save('web-1.org-1', true, [], []);
        $this->assertSame('succeeded', $this->operate($made['operation_id']), (string) DB::table('operations')->value('error'));

        $fw = $this->steps('firewall');
        $this->assertSame(['allow', '2222/tcp'], $fw['ufw-extra-2222-tcp']['args']);
        $this->assertLessThan(array_search('ufw-enable', array_keys($fw)), array_search('ufw-extra-2222-tcp', array_keys($fw)));

        // Off: nothing is applied.
        $this->recipes = [];
        \App\Lib\Servers\Firewall::save('web-1.org-1', false, [], []);
        $op = $this->services()->remove('org-1', 'web-1.org-1', 'git', false, 'm', 'manager', dispatch: false);
        $this->operate($op);
        $this->assertNotContains('firewall', $this->recipeNames());
        $this->assertNotContains('firewall-off', $this->recipeNames());
    }
}
