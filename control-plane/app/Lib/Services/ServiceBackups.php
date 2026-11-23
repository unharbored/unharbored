<?php

declare(strict_types=1);

namespace App\Lib\Services;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\Spaces;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Sites\SiteBackups;
use Closure;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Backups of a template service's data (docs/specs/templates.md section 5, step 5), on the machinery sites already use:
 * the same backup bucket (Spaces::backupTarget, a five-minute presigned URL for one object) and the same 14 days
 * (SiteBackups::KEEP_DAYS).
 *
 *  native     tar + gzip of the data folder, to /var/backups/cloud-manager/services/{name}/{stamp}-{kind}.tar.gz on the
 *             server (0700, root's). Taken while the service runs, unless the definition says `backup: { stop: true }`.
 *  container  one archive of every named volume of the service (found by their cloud-manager.service label), made by a
 *             throwaway `docker run --rm --entrypoint tar` of the service's own pinned image (no pull, no unpinned
 *             image). An image without tar fails with a message saying so.
 *
 * When the service's `backup` input is `spaces` and a bucket exists, the archive is also uploaded. The upload URL is
 * written to a 0600 curl config (a `sensitive` file step), never put in an argument. `snapshot` keeps it on the
 * server only (it protects against a bad update or a bad day, not a lost server) and `none` leaves the service out of
 * the nightly run; a manual backup always works.
 *
 * One row in service_backups per backup, so the console lists them without asking the server.
 */
final class ServiceBackups
{
    public const KINDS = ['manual', 'scheduled', 'pre_update', 'pre_restore'];

    public function __construct(
        private readonly GatewayClient $gateway,
        private readonly Spaces $spaces,
        private readonly AuditLog $audit,
    ) {}

    // ---- reading -----------------------------------------------------------

    /** @return list<object> newest first */
    public function list(string $organizationId, int $serviceId): array
    {
        return DB::table('service_backups')->where('organization_id', $organizationId)->where('service_id', $serviceId)->orderByDesc('id')->get()->all();
    }

    /** What the console and the API show of a backup: no bucket key, nothing secret. @return array<string, mixed> */
    public static function describe(object $b): array
    {
        return ['id' => (int) $b->id, 'name' => $b->name, 'kind' => $b->kind, 'bytes' => (int) $b->bytes, 'where' => $b->location === 'server+spaces' ? 'this server and the backup bucket' : 'this server only', 'created_at' => $b->created_at];
    }

    /** snapshot | spaces | none: the service's `backup` input, `snapshot` when its template has none. */
    public static function choice(object $service): string
    {
        $inputs = json_decode((string) $service->inputs, true) ?: [];

        return in_array($inputs['backup'] ?? null, ['snapshot', 'spaces', 'none'], true) ? $inputs['backup'] : 'snapshot';
    }

    /**
     * The services the nightly job backs up: running or stopped (a failed or half-installed one has nothing to
     * keep), whose backup input is not none, with no operation running.
     *
     * @return list<object>
     */
    public function nightly(): array
    {
        $busy = DB::table('operations')->whereIn('status', ['queued', 'running'])->whereNotNull('meta->service_id')->get(['meta'])
            ->map(fn ($o) => (int) (json_decode((string) $o->meta, true)['service_id'] ?? 0))->all();

        return DB::table('services')->whereIn('status', ['running', 'stopped'])->orderBy('id')->get()
            ->filter(fn ($s) => self::choice($s) !== 'none' && ! in_array((int) $s->id, $busy, true))->values()->all();
    }

    // ---- taking one --------------------------------------------------------

    /**
     * Archives the data, records it and, when chosen, uploads it. A failed archive leaves nothing recorded. A failed upload
     * leaves the server copy recorded and fails the call, so the person is told.
     *
     * @param  Closure(array<string, mixed>, int): void  $apply
     * @param  Closure(string, string): void  $log
     * @return array{id: int, name: string, bytes: int, where: string}
     */
    public function take(object $service, string $kind, Closure $apply, Closure $log, string $actor): array
    {
        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException("Unknown backup kind {$kind}.");
        }
        $installed = Services::installedSummary($service);
        $stop = (bool) ($installed['backup_stop'] ?? false);
        // Names are a stamp and a kind; two in one second (an update's snapshot right after a manual one) move to the next second.
        $at = time();
        while (DB::table('service_backups')->where('service_id', $service->id)->where('name', gmdate('Ymd\THis\Z', $at).'-'.$kind)->exists()) {
            $at++;
        }
        $name = gmdate('Ymd\THis\Z', $at).'-'.$kind;
        $upload = self::choice($service) === 'spaces';
        $bucket = $upload && $this->spaces->backupTarget() !== null;
        if ($upload && ! $bucket) {
            $log('backup', 'This service is set to keep backups in the backup bucket, but there is none (run backup:offsite); the copy stays on the server.');
        }

        $apply(self::archive($service, $name, $stop), 1800);
        $path = self::path($service->name, $name);
        $bytes = $this->size($service, $path);
        $id = (int) DB::table('service_backups')->insertGetId([
            'organization_id' => $service->organization_id, 'service_id' => $service->id, 'name' => $name, 'kind' => $kind, 'bytes' => $bytes,
            'location' => 'server', 'object' => null, 'created_by' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $log('backup', sprintf('%s kept on the server (%s)%s', $name, self::size_label($bytes), $stop ? '; the service was stopped while it was copied' : ''));

        if ($bucket) {
            $object = self::object($service, $name);
            try {
                $apply(self::upload($service->name, $path, $this->spaces->backupUrl('PUT', $object, ttl: Spaces::TRANSFER_TTL)), 1800);
            } catch (Throwable $e) {
                $this->audit($service, $actor, ['kind' => $kind, 'name' => $name, 'bytes' => $bytes, 'where' => 'server']);
                throw new RuntimeException("The backup {$name} was kept on the server, but uploading it to the backup bucket failed: ".$e->getMessage());
            }
            DB::table('service_backups')->where('id', $id)->update(['location' => 'server+spaces', 'object' => $object, 'updated_at' => now()]);
            $log('backup', "{$name} uploaded to the backup bucket");
        }
        $this->audit($service, $actor, ['kind' => $kind, 'name' => $name, 'bytes' => $bytes, 'where' => $bucket ? 'server and bucket' : 'server']);

        try {
            $this->prune($service, $apply, $log);
        } catch (Throwable $e) {
            $log('prune', 'Old backups could not be removed this time: '.$e->getMessage());
        }

        return ['id' => $id, 'name' => $name, 'bytes' => $bytes, 'where' => $bucket ? 'server+spaces' : 'server'];
    }

    /**
     * Backups older than the sites' retention (SiteBackups::KEEP_DAYS) are deleted: the archive on the server, the object
     * in the bucket, the row. The newest one is always kept, so a service nobody has touched for a month still has one.
     *
     * @param  Closure(array<string, mixed>, int): void  $apply
     * @param  Closure(string, string): void  $log
     * @return list<string> the names removed
     */
    public function prune(object $service, Closure $apply, Closure $log): array
    {
        $rows = DB::table('service_backups')->where('service_id', $service->id)->orderByDesc('id')->get();
        $old = $rows->skip(1)->filter(fn ($b) => now()->subDays(SiteBackups::KEEP_DAYS)->gt($b->created_at))->values();
        if ($old->isEmpty()) {
            return [];
        }
        $apply(['name' => "service-backup-prune-{$service->name}", 'steps' => $old->map(fn ($b) => ['id' => 'rm-'.$b->id, 'type' => 'absent', 'path' => self::path($service->name, $b->name)])->all()], 120);
        foreach ($old as $b) {
            if ($b->object !== null) {
                $this->spaces->deleteBackupObject($b->object);
            }
            DB::table('service_backups')->where('id', $b->id)->delete();
        }
        $log('prune', $old->count().' backup(s) older than '.SiteBackups::KEEP_DAYS.' days removed');

        return $old->pluck('name')->all();
    }

    /** A presigned GET for the restore, when the archive was uploaded and a bucket is still configured. */
    public function downloadUrl(object $backup): ?string
    {
        return $backup->object === null || $this->spaces->backupTarget() === null ? null : $this->spaces->backupUrl('GET', $backup->object, ttl: Spaces::TRANSFER_TTL);
    }

    /** The service is gone: its rows go, and with the data's deletion its bucket objects and server folder too. @param Closure(array<string, mixed>, int): void $apply */
    public function forget(object $service, bool $deleteData, Closure $apply): void
    {
        if ($deleteData) {
            foreach (DB::table('service_backups')->where('service_id', $service->id)->whereNotNull('object')->pluck('object') as $object) {
                try {
                    $this->spaces->deleteBackupObject($object);
                } catch (Throwable) {
                }
            }
            $apply(['name' => "service-backup-remove-{$service->name}", 'steps' => [['id' => 'backups', 'type' => 'absent', 'path' => ServiceRecipes::BACKUP_DIR."/{$service->name}"]]], 120);
        }
        DB::table('service_backups')->where('service_id', $service->id)->delete();
    }

    // ---- recipes (pure) ----------------------------------------------------

    public static function path(string $serviceName, string $backupName): string
    {
        return ServiceRecipes::BACKUP_DIR."/{$serviceName}/{$backupName}.tar.gz";
    }

    public static function object(object $service, string $backupName): string
    {
        return "services/{$service->id}-{$service->name}/{$backupName}.tar.gz";
    }

    /** A backup name is a stamp and a kind, nothing else: it ends up in a path. */
    public static function validName(string $name): bool
    {
        return (bool) preg_match('/^\d{8}T\d{6}Z-(manual|scheduled|pre_update|pre_restore)$/', $name);
    }

    /** The flags that confine the throwaway container; root inside it needs three capabilities to read and restore every file. */
    private const THROWAWAY = '--network none --security-opt no-new-privileges --cap-drop ALL --cap-add DAC_OVERRIDE --cap-add DAC_READ_SEARCH --cap-add CHOWN --cap-add FOWNER --user 0:0';

    /**
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function archive(object $service, string $backupName, bool $stop): array
    {
        $e = 'escapeshellarg';
        $dir = ServiceRecipes::BACKUP_DIR."/{$service->name}";
        $dest = self::path($service->name, $backupName);
        $part = $dest.'.partial';
        $container = $service->runtime === 'container';
        $unit = $container ? ContainerRecipes::container($service->name) : ServiceRecipes::unit($service->name);

        $lines = ['set -e', 'trap \'rm -f '.$e($part).'\' EXIT'];
        if ($stop) {
            $isUp = $container ? 'docker inspect -f \'{{.State.Running}}\' '.$e($unit).' 2>/dev/null | grep -qx true' : 'systemctl is-active --quiet '.$e($unit);
            $down = $container ? 'docker stop '.$e($unit).' >/dev/null' : 'systemctl stop '.$e($unit);
            $up = $container ? 'docker start '.$e($unit).' >/dev/null' : 'systemctl start '.$e($unit);
            array_push($lines, 'was=0; if '.$isUp.'; then was=1; '.$down.'; fi', 'resume() { if [ "$was" = 1 ]; then '.$up.'; was=0; fi; }', 'trap \'resume; rm -f '.$e($part).'\' EXIT');
        }
        if ($container) {
            $image = (string) $service->image;
            array_push($lines,
                'vols=$(docker volume ls -q --filter '.$e('label=cloud-manager.service='.$service->name).')',
                '[ -n "$vols" ] || { echo '.$e("{$service->name} has no volumes to back up").'; exit 1; }',
                'args=""; for v in $vols; do args="$args -v $v:/data/${v#'.ServiceRecipes::unit($service->name).'-}:ro"; done',
                'docker run --rm --read-only '.self::THROWAWAY.' --entrypoint tar $args -v '.$e($dir).':/backup '.$e($image).' -czf '.$e('/backup/'.basename($part)).' -C /data . '
                    .'|| { echo '.$e("could not archive the volumes of {$service->name}: its image may have no tar, and then this service cannot be backed up").'; exit 1; }',
            );
        } else {
            array_push($lines, 'tar -C '.$e(dirname($service->data_path)).' -czf '.$e($part).' '.$e(basename($service->data_path)));
        }
        if ($stop) {
            $lines[] = 'resume';
        }
        array_push($lines, 'tar -tzf '.$e($part).' >/dev/null', 'chmod 0600 '.$e($part), 'mv '.$e($part).' '.$e($dest));

        return ['name' => "service-backup-{$service->name}", 'steps' => [
            ...ServiceRecipes::backupDirs($service->name),
            ['id' => 'archive', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', implode("\n", $lines)],
                'description' => ($container ? 'archive the volumes of ' : 'archive the data folder of ').$service->name.($stop ? ' (stopped meanwhile)' : '')],
        ]];
    }

    /** Upload the archive with a presigned URL kept in a 0600 curl config, removed afterwards. @return array{name: string, steps: list<array<string, mixed>>} */
    public static function upload(string $serviceName, string $path, string $url): array
    {
        $cfg = ServiceRecipes::BACKUP_DIR."/{$serviceName}/.transfer.cfg";
        $config = "url = \"{$url}\"\nrequest = \"PUT\"\nupload-file = \"{$path}\"\n";

        return ['name' => "service-backup-upload-{$serviceName}", 'steps' => [
            ...ServiceRecipes::backupDirs($serviceName),
            ['id' => 'transfer-config', 'type' => 'file', 'path' => $cfg, 'mode' => '0600', 'sensitive' => true, 'content_b64' => base64_encode($config)],
            ['id' => 'upload', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'trap \'rm -f '.escapeshellarg($cfg).'\' EXIT; curl -fsS --max-time 1800 -K '.escapeshellarg($cfg)],
                'description' => 'upload the archive to the backup bucket'],
        ]];
    }

    /**
     * Puts a backup back. Native: unpacked beside the data folder, then swapped in whole (the old folder is put back if the swap
     * fails). Container: every volume emptied and the archive unpacked into it, by the service's own pinned image. If the archive
     * is no longer on the server and $downloadUrl is given, it is fetched from the bucket first.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public static function restore(object $service, string $backupName, ?string $downloadUrl): array
    {
        $e = 'escapeshellarg';
        $dir = ServiceRecipes::BACKUP_DIR."/{$service->name}";
        $src = self::path($service->name, $backupName);
        $cfg = "{$dir}/.transfer.cfg";
        $steps = ServiceRecipes::backupDirs($service->name);
        $lines = ['set -e'];
        if ($downloadUrl !== null) {
            $steps[] = ['id' => 'transfer-config', 'type' => 'file', 'path' => $cfg, 'mode' => '0600', 'sensitive' => true,
                'content_b64' => base64_encode("url = \"{$downloadUrl}\"\noutput = \"{$src}\"\n")];
            array_push($lines, 'trap \'rm -f '.$e($cfg).'\' EXIT', '[ -f '.$e($src).' ] || curl -fsS --max-time 1800 -K '.$e($cfg));
        }
        array_push($lines, '[ -f '.$e($src).' ] || { echo '.$e("the backup {$backupName} is no longer on the server and cannot be fetched").'; exit 1; }', 'tar -tzf '.$e($src).' >/dev/null || { echo "the archive is damaged; nothing was changed"; exit 1; }');

        if ($service->runtime === 'container') {
            $inner = 'cd /data && for d in *; do find "$d" -mindepth 1 -delete; done && tar -xzf /backup/'.basename($src).' -C /data --numeric-owner';
            array_push($lines,
                'vols=$(docker volume ls -q --filter '.$e('label=cloud-manager.service='.$service->name).')',
                '[ -n "$vols" ] || { echo '.$e("{$service->name} has no volumes to restore into").'; exit 1; }',
                'args=""; for v in $vols; do args="$args -v $v:/data/${v#'.ServiceRecipes::unit($service->name).'-}"; done',
                'docker run --rm '.self::THROWAWAY.' --entrypoint sh $args -v '.$e($dir).':/backup:ro '.$e((string) $service->image).' -c '.$e($inner)
                    .' || { echo '.$e("could not restore the volumes of {$service->name}: its image may have no sh or tar").'; exit 1; }',
            );
        } else {
            $data = $service->data_path;
            $parent = dirname($data);
            $stage = "{$parent}/.cm-restore-{$service->name}";
            $old = "{$data}.before-restore";
            array_push($lines,
                'rm -rf '.$e($stage).' '.$e($old),
                'mkdir -p -m 0700 '.$e($stage),
                'tar -xzf '.$e($src).' -C '.$e($stage),
                '[ -d '.$e($stage.'/'.basename($data)).' ] || { rm -rf '.$e($stage).'; echo "the archive does not hold a folder called '.basename($data).'; nothing was changed"; exit 1; }',
                'mv '.$e($data).' '.$e($old),
                'mv '.$e($stage.'/'.basename($data)).' '.$e($data).' || { mv '.$e($old).' '.$e($data).'; exit 1; }',
                'rm -rf '.$e($stage).' '.$e($old),
            );
        }
        $steps[] = ['id' => 'restore', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', implode("\n", $lines)], 'description' => "restore {$service->name} from {$backupName}"];

        return ['name' => "service-restore-{$service->name}", 'steps' => $steps];
    }

    // ---- pieces ------------------------------------------------------------

    /** The size of the archive, asked of the server (a recipe step prints nothing back). */
    private function size(object $service, string $path): int
    {
        $result = $this->gateway->run($service->organization_id, $service->node_id, 'exec', ['name' => 'stat', 'args' => ['-c', '%s', $path]], reason: "Size of the backup of {$service->name}");
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException('The backup was made but its size could not be read: '.($result['error'] ?? 'no detail'));
        }

        return (int) trim((string) ($result['detail']['output'] ?? ''));
    }

    public static function size_label(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : ($bytes >= 1024 ** 2 ? round($bytes / 1024 ** 2, 1).' MB' : max(1, (int) ceil($bytes / 1024)).' KB');
    }

    /** @param array<string, mixed> $meta */
    private function audit(object $service, string $actor, array $meta): void
    {
        $this->audit->record($service->organization_id, $actor === 'schedule' || $actor === 'cli' ? Actor::system('control-plane') : Actor::user($actor, $actor), 'service.backed_up',
            resourceType: 'service', resourceId: "{$service->node_id}/{$service->name}", metadata: ['template' => $service->template_id] + $meta);
    }
}
