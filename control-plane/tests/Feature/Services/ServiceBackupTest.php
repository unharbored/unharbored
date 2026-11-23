<?php

declare(strict_types=1);

namespace Tests\Feature\Services;

use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\Spaces;
use App\Lib\Resources\ResourceManager;
use App\Lib\Resources\ResourceState;
use App\Lib\Services\ServiceBackups;
use App\Lib\Services\Services;
use App\Lib\Sites\SiteBackups;
use App\Lib\Vault\Vault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Step 5: backups and restores of a service's data folder or Docker volumes, the bucket, retention and the nightly selection. */
final class ServiceBackupTest extends ServiceTestCase
{
    /** @var list<string> DELETE urls sent to the backup bucket */
    private array $deleted = [];

    private function backup(string $name = 'files', string $kind = 'manual', string $role = 'manager', string $org = 'org-1'): int
    {
        return $this->services()->backup($org, $this->nodeOf($name), $name, $kind, 'matt@example.com', $role, dispatch: false);
    }

    private function nodeOf(string $name): string
    {
        return (string) (DB::table('services')->where('name', $name)->value('node_id') ?? 'web-1.org-1');
    }

    /** RustFS's ports are fixed, so each RustFS goes on a server of its own. */
    private function rustfsOn(string $name, array $extra = []): int
    {
        $this->node("{$name}.org-1", 'org-1');

        return $this->install('rustfs', $this->rustfs($name, $extra), "{$name}.org-1")['service_id'];
    }

    private function opError(int $op): string
    {
        return (string) DB::table('operations')->where('id', $op)->value('error');
    }

    private function bucket(): void
    {
        $resources = new ResourceManager(new AuditLog());
        $b = $resources->declare('org-1', 'backup_bucket', 'control-plane', ['region' => 'sfo3', 'bucket' => 'cm-backups-test', 'key_id' => 'AKB']);
        $b = $resources->transition($b, ResourceState::Provisioning, reason: 't');
        $resources->transition($b, ResourceState::Active, reason: 't');
        app(Vault::class)->put('org-1', Spaces::BACKUP_SECRET, 'SKB-SECRET');
        $http = new HttpFactory();
        $http->fake(function (ClientRequest $r) use ($http) {
            $r->method() === 'DELETE' && $this->deleted[] = $r->url();

            return $http->response('', 204);
        });
        $this->app->instance(HttpFactory::class, $http);
        foreach ([Spaces::class, ServiceBackups::class, Services::class, \App\Lib\Services\ServiceRunner::class, \App\Lib\Operations\OperationRunner::class] as $c) {
            $this->app->forgetInstance($c);
        }
    }

    private function kuma(array $extra = []): int
    {
        DB::table('nodes')->where('node_id', 'web-1.org-1')->update(['containers_ready_at' => now(), 'docker_version' => '29.0.0']);

        return $this->install('uptime-kuma', ['name' => 'kuma', 'host' => 'kuma.example.com'] + $extra)['service_id'];
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->execOutput = fn ($n) => $n === 'stat' ? '2048' : '';
    }

    // ---- taking one -----------------------------------------------------------

    public function test_a_native_backup_archives_the_data_folder_to_the_server_and_records_it(): void
    {
        // The built-in RustFS says stop: true; a template that does not (here, false) is copied while it runs.
        $this->publish('rustfs', 1, ['backup: { stop: true }' => 'backup: { stop: false }']);
        $id = $this->install()['service_id'];
        $this->recipes = [];
        $op = $this->backup();
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));

        $this->assertSame(['service-backup-files'], $this->recipeNames());
        $steps = $this->steps('service-backup');
        $this->assertSame(['dir-backups-0', 'dir-backups-1', 'dir-backups-2', 'archive'], array_keys($steps));
        $this->assertSame('/var/backups/cloud-manager/services/files', $steps['dir-backups-2']['path']);
        $this->assertSame('0700', $steps['dir-backups-2']['mode']);
        $script = $steps['archive']['args'][1];
        $row = DB::table('service_backups')->first();
        $this->assertMatchesRegularExpression('/^\d{8}T\d{6}Z-manual$/', $row->name);
        $this->assertStringContainsString("tar -C '/srv/cloud-manager/data' -czf '/var/backups/cloud-manager/services/files/{$row->name}.tar.gz.partial' 'files'", $script);
        $this->assertStringContainsString("mv '/var/backups/cloud-manager/services/files/{$row->name}.tar.gz.partial' '/var/backups/cloud-manager/services/files/{$row->name}.tar.gz'", $script);
        $this->assertStringContainsString('tar -tzf', $script);
        $this->assertStringNotContainsString('systemctl', $script, 'by default the service keeps running');
        $this->assertSame([2048, 'server', null, 'manual', $id], [(int) $row->bytes, $row->location, $row->object, $row->kind, (int) $row->service_id]);
        $this->assertSame(['stat', ['-c', '%s', "/var/backups/cloud-manager/services/files/{$row->name}.tar.gz"]], [$this->execs[0]['name'], $this->execs[0]['args']]);
        $this->assertSame(['running'], [$this->service()->status]);
        $audit = DB::table('audit_records')->where('action', 'service.backed_up')->first();
        $this->assertSame(['manual', 2048, 'server'], [json_decode($audit->metadata, true)['kind'], json_decode($audit->metadata, true)['bytes'], json_decode($audit->metadata, true)['where']]);
    }

    public function test_a_template_that_says_stop_has_its_unit_stopped_around_the_copy_and_started_again(): void
    {
        $this->install();
        $this->recipes = [];
        $this->assertSame('succeeded', $this->operate($this->backup()));
        $script = $this->steps('service-backup')['archive']['args'][1];
        $this->assertStringContainsString("systemctl is-active --quiet 'cm-svc-files'", $script);
        $this->assertStringContainsString("systemctl stop 'cm-svc-files'", $script);
        $this->assertStringContainsString("systemctl start 'cm-svc-files'", $script);
        $this->assertLessThan(strpos($script, 'tar -tzf'), strpos($script, 'resume' . "\n"), 'it is started again before the archive is verified');
    }

    public function test_the_stop_field_must_be_true_or_false(): void
    {
        $this->expectException(\App\Lib\Templates\InvalidDefinition::class);
        \App\Lib\Templates\Definition::parse(str_replace('backup: { stop: true }', 'backup: { stop: sometimes }', (string) file_get_contents(base_path('templates/rustfs.yml'))));
    }

    public function test_both_built_in_templates_stop_the_service_around_a_backup(): void
    {
        foreach (['rustfs', 'uptime-kuma'] as $id) {
            $this->assertTrue(\App\Lib\Templates\Definition::parse((string) file_get_contents(base_path("templates/{$id}.yml")))->backup['stop'], "{$id} keeps its state in a file or database a live copy may not restore cleanly");
        }
    }

    public function test_a_container_service_is_stopped_around_its_backup_and_started_again(): void
    {
        $this->kuma();
        $this->recipes = [];
        $this->assertSame('succeeded', $this->operate($this->backup('kuma')));
        $script = $this->steps('service-backup')['archive']['args'][1];
        $this->assertStringContainsString("docker inspect -f '{{.State.Running}}' 'cm-svc-kuma'", $script);
        $this->assertStringContainsString("docker stop 'cm-svc-kuma'", $script);
        $this->assertStringContainsString("docker start 'cm-svc-kuma'", $script);
        $this->assertLessThan(strpos($script, 'tar -tzf'), strpos($script, 'resume'."\n"), 'it is started again before the archive is verified');
        $this->assertStringContainsString('(stopped meanwhile)', $this->steps('service-backup')['archive']['description']);
    }

    public function test_a_container_backup_archives_the_named_volumes_with_the_services_own_pinned_image(): void
    {
        $this->kuma();
        $this->recipes = [];
        $op = $this->backup('kuma');
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $script = $this->steps('service-backup')['archive']['args'][1];
        $image = 'louislam/uptime-kuma@sha256:'.self::KUMA_DIGEST;
        $this->assertStringContainsString("--entrypoint tar", $script);
        $this->assertStringContainsString("'{$image}'", $script, 'the service image, pinned by digest');
        $this->assertStringNotContainsString('docker pull', $script);
        $this->assertStringContainsString("docker volume ls -q --filter 'label=cloud-manager.service=kuma'", $script);
        $this->assertStringContainsString('-v $v:/data/${v#cm-svc-kuma-}:ro', $script);
        $this->assertStringContainsString('--network none', $script);
        $this->assertStringContainsString('--read-only', $script);
        $this->assertStringContainsString("-v '/var/backups/cloud-manager/services/kuma':/backup", $script);
        $this->assertStringContainsString('may have no tar', $script);
        $this->assertSame('server', DB::table('service_backups')->value('location'));
    }

    public function test_no_secret_is_in_an_argument_or_a_path_of_a_backup(): void
    {
        $id = $this->install()['service_id'];
        $this->bucket();
        DB::table('services')->where('id', $id)->update(['inputs' => json_encode(['backup' => 'spaces'] + json_decode($this->service()->inputs, true))]);
        $this->recipes = [];
        $this->assertSame('succeeded', $this->operate($this->backup()));
        $secrets = [app(Vault::class)->get('org-1', Services::secretName($id, 'access_key')), app(Vault::class)->get('org-1', Services::secretName($id, 'secret_key')), 'SKB-SECRET'];
        foreach ($this->recipes as $r) {
            foreach ($r['steps'] as $s) {
                $text = json_encode([$s['args'] ?? [], $s['path'] ?? '', $s['name'] ?? '']);
                foreach ($secrets as $secret) {
                    $this->assertStringNotContainsString($secret, $text);
                }
            }
        }
        $this->assertStringNotContainsString('SKB-SECRET', json_encode(DB::table('operations')->get()).json_encode(DB::table('audit_records')->get()).json_encode(DB::table('service_backups')->get()));
    }

    public function test_spaces_uploads_through_a_sensitive_curl_config_and_snapshot_stays_on_the_server(): void
    {
        $id = $this->install()['service_id'];
        $this->bucket();

        // snapshot (the default): server only, even with a bucket.
        $this->recipes = [];
        $this->assertSame('succeeded', $this->operate($this->backup()));
        $this->assertSame(['service-backup-files'], $this->recipeNames());
        $this->assertSame('server', DB::table('service_backups')->value('location'));

        DB::table('services')->where('id', $id)->update(['inputs' => json_encode(['backup' => 'spaces'] + json_decode($this->service()->inputs, true))]);
        $this->recipes = [];
        $op = $this->backup('files', 'scheduled');
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $this->assertSame(['service-backup-files', 'service-backup-upload-files'], $this->recipeNames());
        $upload = $this->steps('service-backup-upload');
        $cfg = $upload['transfer-config'];
        $this->assertTrue($cfg['sensitive']);
        $this->assertSame('0600', $cfg['mode']);
        $this->assertSame('/var/backups/cloud-manager/services/files/.transfer.cfg', $cfg['path']);
        $content = base64_decode($cfg['content_b64']);
        $row = DB::table('service_backups')->where('kind', 'scheduled')->first();
        $this->assertStringContainsString("url = \"https://sfo3.digitaloceanspaces.com/cm-backups-test/services/{$id}-files/{$row->name}.tar.gz?", $content);
        $this->assertStringContainsString('request = "PUT"', $content);
        $this->assertStringNotContainsString('https://', json_encode($upload['upload']['args']), 'the URL is in the config file, not in an argument');
        $this->assertStringContainsString('curl -fsS', $upload['upload']['args'][1]);
        $this->assertStringContainsString('rm -f', $upload['upload']['args'][1]);
        $this->assertSame(['server+spaces', "services/{$id}-files/{$row->name}.tar.gz"], [$row->location, $row->object]);
    }

    public function test_a_failed_upload_keeps_the_server_copy_and_says_so(): void
    {
        $id = $this->install()['service_id'];
        $this->bucket();
        DB::table('services')->where('id', $id)->update(['inputs' => json_encode(['backup' => 'spaces'] + json_decode($this->service()->inputs, true))]);
        $this->failSteps = ['upload' => 'curl: (22) 403'];
        $op = $this->backup();
        $this->assertSame('failed', $this->operate($op));
        $this->assertStringContainsString('was kept on the server, but uploading it to the backup bucket failed', $this->opError($op));
        $this->assertSame('server', DB::table('service_backups')->value('location'));
    }

    public function test_spaces_without_a_bucket_keeps_the_copy_on_the_server_and_says_so(): void
    {
        $id = $this->install()['service_id'];
        DB::table('services')->where('id', $id)->update(['inputs' => json_encode(['backup' => 'spaces'] + json_decode($this->service()->inputs, true))]);
        $op = $this->backup();
        $this->assertSame('succeeded', $this->operate($op));
        $this->assertStringContainsString('there is none', (string) DB::table('operations')->where('id', $op)->value('log'));
        $this->assertSame('server', DB::table('service_backups')->value('location'));
    }

    public function test_a_failed_archive_records_nothing(): void
    {
        $this->install();
        $this->failSteps = ['archive' => 'No space left on device'];
        $this->assertSame('failed', $this->operate($this->backup()));
        $this->assertSame(0, DB::table('service_backups')->count());
    }

    public function test_two_backups_in_the_same_second_get_different_names(): void
    {
        $this->install();
        $this->operate($this->backup());
        $this->operate($this->backup());
        $this->assertCount(2, array_unique(DB::table('service_backups')->pluck('name')->all()));
    }

    // ---- retention and the nightly job ------------------------------------------

    public function test_backups_older_than_the_sites_retention_are_deleted_everywhere_but_the_newest_is_kept(): void
    {
        $id = $this->install()['service_id'];
        $this->bucket();
        $old = fn (string $name, int $days, ?string $object) => DB::table('service_backups')->insert(['organization_id' => 'org-1', 'service_id' => $id, 'name' => $name, 'kind' => 'scheduled', 'bytes' => 1,
            'location' => $object ? 'server+spaces' : 'server', 'object' => $object, 'created_by' => 'schedule', 'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days)]);
        $old('20261001T030000Z-scheduled', 40, "services/{$id}-files/20261001T030000Z-scheduled.tar.gz");
        $old('20261105T030000Z-scheduled', SiteBackups::KEEP_DAYS + 1, null);
        $old('20261110T030000Z-scheduled', 3, null);
        $this->recipes = [];

        $this->assertSame('succeeded', $this->operate($this->backup()));
        $this->assertSame(14, SiteBackups::KEEP_DAYS);
        $remaining = DB::table('service_backups')->orderBy('id')->pluck('name')->all();
        $this->assertCount(2, $remaining);
        $this->assertSame('20261110T030000Z-scheduled', $remaining[0]);
        $prune = $this->steps('service-backup-prune');
        $this->assertEqualsCanonicalizing(['/var/backups/cloud-manager/services/files/20261001T030000Z-scheduled.tar.gz', '/var/backups/cloud-manager/services/files/20261105T030000Z-scheduled.tar.gz'], array_column($prune, 'path'));
        $this->assertSame('absent', array_values($prune)[0]['type']);
        $this->assertCount(1, $this->deleted);
        $this->assertStringContainsString("services/{$id}-files/20261001T030000Z-scheduled.tar.gz", $this->deleted[0]);
    }

    public function test_the_only_backup_is_never_pruned_however_old(): void
    {
        $id = $this->install()['service_id'];
        DB::table('service_backups')->insert(['organization_id' => 'org-1', 'service_id' => $id, 'name' => '20260101T030000Z-scheduled', 'kind' => 'scheduled', 'bytes' => 1, 'location' => 'server', 'object' => null,
            'created_by' => 's', 'created_at' => now()->subDays(300), 'updated_at' => now()->subDays(300)]);
        $this->recipes = [];
        $row = $this->service();
        app(ServiceBackups::class)->prune($row, fn () => $this->fail('nothing to prune'), fn () => null);
        $this->assertSame(1, DB::table('service_backups')->count());
    }

    public function test_the_nightly_job_includes_snapshot_and_spaces_services_and_leaves_out_none_and_busy_ones(): void
    {
        $this->rustfsOn('snap');
        $this->rustfsOn('cloud', ['backup' => 'spaces']);
        $c = $this->rustfsOn('never', ['backup' => 'none']);
        $this->rustfsOn('busy');
        $e = $this->rustfsOn('broken');
        $f = $this->rustfsOn('paused');
        DB::table('services')->where('id', $e)->update(['status' => 'failed']);
        DB::table('services')->where('id', $f)->update(['status' => 'stopped']);
        $this->services()->action('org-1', 'busy.org-1', 'busy', 'restart', 'm@example.com', 'manager', dispatch: false);

        $this->assertSame(['snap', 'cloud', 'paused'], array_map(fn ($s) => $s->name, app(ServiceBackups::class)->nightly()));

        $this->artisan('services:backup')->assertSuccessful();
        $queued = DB::table('operations')->where('kind', 'template_service_backup')->get();
        $this->assertCount(3, $queued);
        $this->assertSame(['scheduled'], array_unique($queued->map(fn ($o) => json_decode($o->meta, true)['kind'])->all()));
        $this->assertSame(['schedule'], array_unique($queued->pluck('trigger')->all()));
        foreach ($queued as $o) {
            $this->assertSame('succeeded', $this->operate((int) $o->id));
        }
        $this->assertSame(0, DB::table('service_backups')->where('service_id', $c)->count());
        $this->assertSame(['scheduled'], array_unique(DB::table('service_backups')->pluck('kind')->all()));

        // Manual always works, even for none.
        $this->assertSame('succeeded', $this->operate($this->backup('never')));
        $this->assertSame(1, DB::table('service_backups')->where('service_id', $c)->count());
    }

    public function test_the_nightly_job_is_in_the_cron_file_the_deploy_installs(): void
    {
        $cron = (string) file_get_contents(base_path('../scripts/mgmt/files/cron'));
        $this->assertStringContainsString('artisan services:backup >> /var/lib/cloud-manager/service-backups.log', $cron);
    }

    // ---- restore ------------------------------------------------------------------

    private function restore(int $backupId, string $confirm = 'files', string $role = 'manager', string $name = 'files', string $org = 'org-1'): int
    {
        return $this->services()->restore($org, $this->nodeOf($name), $name, $backupId, $confirm, 'matt@example.com', $role, dispatch: false);
    }

    public function test_a_native_restore_takes_a_safety_snapshot_first_then_stops_restores_starts_and_checks_health(): void
    {
        $this->install();
        $this->operate($this->backup());
        $backup = DB::table('service_backups')->first();
        $this->recipes = [];

        $op = $this->restore((int) $backup->id);
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $this->assertSame(['snapshot', 'stop', 'restore', 'start', 'health'], array_column(json_decode((string) DB::table('operations')->where('id', $op)->value('stages'), true), 'name'));
        $this->assertSame(['service-backup-files', 'service-stop-files', 'service-restore-files', 'service-start-files', 'service-health-files'], $this->recipeNames());
        $this->assertSame(1, DB::table('service_backups')->where('kind', 'pre_restore')->count());

        $script = $this->steps('service-restore')['restore']['args'][1];
        $src = "/var/backups/cloud-manager/services/files/{$backup->name}.tar.gz";
        $this->assertStringContainsString("tar -tzf '{$src}'", $script);
        $this->assertStringContainsString("tar -xzf '{$src}' -C '/srv/cloud-manager/data/.cm-restore-files'", $script);
        $this->assertStringContainsString("mv '/srv/cloud-manager/data/files' '/srv/cloud-manager/data/files.before-restore'", $script);
        $this->assertStringContainsString("|| { mv '/srv/cloud-manager/data/files.before-restore' '/srv/cloud-manager/data/files'; exit 1; }", $script);
        $this->assertSame('stopped', $this->steps('service-stop')['stop']['state']);
        $this->assertSame('running', $this->steps('service-start')['start']['state']);
        $audit = json_decode((string) DB::table('audit_records')->where('action', 'service.restored')->value('metadata'), true);
        $this->assertSame($backup->name, $audit['backup']);
        $this->assertMatchesRegularExpression('/-pre_restore$/', $audit['safety']);
        $this->assertSame('running', $this->service()->status);
    }

    public function test_a_container_restore_empties_the_volumes_and_unpacks_into_them_with_the_services_image(): void
    {
        $this->kuma();
        $this->operate($this->backup('kuma'));
        $backup = DB::table('service_backups')->first();
        $this->recipes = [];
        $op = $this->services()->restore('org-1', 'web-1.org-1', 'kuma', (int) $backup->id, 'kuma', 'm@example.com', 'manager', dispatch: false);
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $this->assertSame(['service-backup-kuma', 'service-stop-kuma', 'service-restore-kuma', 'service-start-kuma', 'service-health-kuma'], $this->recipeNames());
        $this->assertSame(['docker', 'stop', 'cm-svc-kuma'], [$this->steps('service-stop')['stop']['name'], ...array_slice($this->steps('service-stop')['stop']['args'], 0, 2)]);
        $script = $this->steps('service-restore')['restore']['args'][1];
        $this->assertStringContainsString("--entrypoint sh", $script);
        $this->assertStringContainsString("'louislam/uptime-kuma@sha256:".self::KUMA_DIGEST."'", $script);
        $this->assertStringContainsString('find "$d" -mindepth 1 -delete', $script);
        $this->assertStringContainsString("tar -xzf /backup/{$backup->name}.tar.gz -C /data", $script);
        $this->assertStringContainsString('-v '."'/var/backups/cloud-manager/services/kuma'".':/backup:ro', $script);
    }

    public function test_a_restore_fetches_the_archive_from_the_bucket_when_the_server_no_longer_has_it(): void
    {
        $id = $this->install()['service_id'];
        $this->bucket();
        DB::table('services')->where('id', $id)->update(['inputs' => json_encode(['backup' => 'spaces'] + json_decode($this->service()->inputs, true))]);
        $this->operate($this->backup());
        $backup = DB::table('service_backups')->where('kind', 'manual')->first();
        $this->recipes = [];
        $this->assertSame('succeeded', $this->operate($this->restore((int) $backup->id)));
        $restore = $this->steps('service-restore');
        $this->assertTrue($restore['transfer-config']['sensitive']);
        $this->assertStringContainsString('output = "/var/backups/cloud-manager/services/files/'.$backup->name.'.tar.gz"', base64_decode($restore['transfer-config']['content_b64']));
        $this->assertStringContainsString('[ -f', $restore['restore']['args'][1]);
        $this->assertStringContainsString('curl -fsS', $restore['restore']['args'][1]);
    }

    public function test_the_service_name_must_be_typed_and_the_backup_must_be_this_services(): void
    {
        $this->install();
        $this->rustfsOn('other');
        $this->operate($this->backup());
        $this->operate($this->backup('other'));
        [$mine, $theirs] = [DB::table('service_backups')->where('service_id', $this->service()->id)->value('id'), DB::table('service_backups')->where('service_id', $this->service('other')->id)->value('id')];

        foreach (['', 'file', 'FILES'] as $typed) {
            try {
                $this->restore((int) $mine, $typed);
                $this->fail('the name must match');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('Type files to confirm', $e->getMessage());
            }
        }
        try {
            $this->restore((int) $theirs);
            $this->fail('a backup of another service');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('There is no backup', $e->getMessage());
        }
        try {
            $this->restore(9999);
            $this->fail('no such backup');
        } catch (InvalidArgumentException) {
            $this->assertTrue(true);
        }
        // A backup whose name is not a stamp and a kind never reaches a path.
        DB::table('service_backups')->where('id', $mine)->update(['name' => '../../etc/passwd']);
        $this->expectExceptionMessage('cannot be restored');
        $this->restore((int) $mine);
    }

    public function test_a_failed_restore_starts_the_service_again_and_names_the_safety_backup(): void
    {
        $this->install();
        $this->operate($this->backup());
        $id = (int) DB::table('service_backups')->value('id');
        $this->failSteps = ['restore' => 'the archive is damaged'];
        $op = $this->restore($id);
        $this->assertSame('failed', $this->operate($op));
        $this->assertStringContainsString('The restore failed', $this->opError($op));
        $this->assertStringContainsString('pre_restore', $this->opError($op));
        $this->assertContains('service-start-files', $this->recipeNames());
        $this->assertSame('running', $this->service()->status);
    }

    public function test_a_safety_snapshot_that_fails_stops_the_restore_before_anything_is_stopped(): void
    {
        $this->install();
        $this->operate($this->backup());
        $id = (int) DB::table('service_backups')->value('id');
        $this->recipes = [];
        $this->failSteps = ['archive' => 'No space left on device'];
        $this->assertSame('failed', $this->operate($this->restore($id)));
        $this->assertNotContains('service-stop-files', $this->recipeNames());
    }

    // ---- who, which service ----------------------------------------------------------

    public function test_only_a_manager_backs_up_or_restores_and_another_team_cannot_reach_it(): void
    {
        DB::table('organizations')->insertOrIgnore(['id' => 'org-2', 'name' => 'Globex', 'created_at' => now(), 'updated_at' => now()]);
        $this->install();
        $this->operate($this->backup());
        $id = (int) DB::table('service_backups')->value('id');
        foreach (['developer', 'viewer'] as $role) {
            try {
                $this->backup(role: $role);
                $this->fail("{$role} may not back up");
            } catch (AuthorizationException) {
            }
            try {
                $this->restore($id, role: $role);
                $this->fail("{$role} may not restore");
            } catch (AuthorizationException) {
            }
        }
        foreach ([fn () => $this->backup(org: 'org-2'), fn () => $this->restore($id, org: 'org-2')] as $try) {
            try {
                $try();
                $this->fail('another team');
            } catch (InvalidArgumentException $e) {
                $this->assertStringContainsString('There is no service files', $e->getMessage());
            }
        }
        $this->assertSame([], $this->services()->backups('org-2', 1));
    }

    public function test_removing_a_service_forgets_its_backups_and_deleting_the_data_removes_the_folder_and_objects(): void
    {
        $id = $this->install()['service_id'];
        $this->bucket();
        DB::table('services')->where('id', $id)->update(['inputs' => json_encode(['backup' => 'spaces'] + json_decode($this->service()->inputs, true))]);
        $this->operate($this->backup());
        $this->recipes = [];
        $op = $this->services()->remove('org-1', 'web-1.org-1', 'files', true, 'm@example.com', 'manager', dispatch: false);
        $this->assertSame('succeeded', $this->operate($op), $this->opError($op));
        $this->assertSame(0, DB::table('service_backups')->count());
        $this->assertSame('/var/backups/cloud-manager/services/files', $this->steps('service-backup-remove')['backups']['path']);
        $this->assertCount(1, $this->deleted);
    }

    public function test_the_commands_back_up_and_restore(): void
    {
        $this->install();
        $this->artisan('service:backup web-1.org-1 files')->assertSuccessful();
        $name = (string) DB::table('service_backups')->value('name');
        $this->artisan("service:restore web-1.org-1 files {$name}")->expectsOutputToContain('Type files to confirm')->assertFailed();
        $this->artisan("service:restore web-1.org-1 files {$name} --confirm=files")->assertSuccessful();
        $this->assertSame(1, DB::table('service_backups')->where('kind', 'pre_restore')->count());
        $this->artisan('service:backup web-1.org-1 nope')->expectsOutputToContain('There is no service nope')->assertFailed();
    }
}
