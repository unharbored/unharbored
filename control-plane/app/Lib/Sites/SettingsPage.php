<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Auth\Org;
use App\Lib\Auth\Projects;
use App\Lib\Cloud\DatabaseClusters;
use App\Lib\Git\GitPush;
use App\Lib\Git\GitRepo;
use App\Lib\Operations\Present;
use App\Lib\Resources\ResourceManager;
use App\Lib\Scaling\ServerScaler;
use App\Lib\Servers\LocalServices;
use App\Lib\Servers\PhpSettings;
use App\Lib\Servers\ServerStack;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * What an application's Settings page shows: a few facts every tab needs, and
 * the data of the one tab that is open (so a tab never pays for another's queries).
 */
final class SettingsPage
{
    public const TABS = ['general', 'env', 'domain', 'database', 'servers', 'processes', 'build', 'scheduler', 'web', 'script', 'runtime', 'previews', 'source', 'delete'];

    /** @return array<string, mixed> */
    public static function common(object $row): array
    {
        $org = Org::id();

        return [
            'id' => $row->id,
            'name' => $row->name,
            'domain' => $row->domain,
            'tls' => (bool) $row->tls,
            'node' => strstr($row->node_id, '.', true) ?: $row->node_id,
            'branch' => $row->branch,
            'runtime' => Runtimes::runtime($row),
            'capabilities' => Runtimes::capabilities($row),
            // A free name that needs no DNS, offered while the application has no domain of its own.
            'freeAddress' => NewApplications::freeAddress($row),
            'pending' => $row->pending_since !== null,
            'pendingSince' => Present::ago($row->pending_since),
            'project' => (int) $row->project_id,
            'projects' => $row->parent_site_id ? [] : Projects::listing($org),
            'parent' => $row->parent_site_id ? (array) DB::table('sites')->where('organization_id', $org)->where('id', $row->parent_site_id)->first(['id', 'name']) : null,
            'previewIdle' => $row->preview_idle_days,
        ];
    }

    /** @return array<string, mixed> */
    public static function tab(string $tab, object $row): array
    {
        return match ($tab) {
            'general' => [],
            'env' => self::env($row),
            'domain' => [],
            'database' => self::database($row),
            'servers' => self::servers($row),
            'processes' => self::processes($row),
            'build' => self::build($row),
            'scheduler' => self::scheduler($row),
            'web' => self::web($row),
            'script' => self::script($row),
            'runtime' => self::runtime($row),
            'previews' => self::previews($row),
            'source' => self::source($row),
            'delete' => self::delete($row),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private static function env(object $row): array
    {
        $sites = app(Sites::class);
        // Two layers: the user's own variables, and what Unharbored injects.
        $layers = $sites->envLayers($row);
        $secret = fn (string $key) => preg_match('/(KEY|SECRET|PASSWORD|TOKEN)/', $key) === 1;
        // Values of anything that looks secret are masked until revealed.
        $masked = preg_replace_callback('/^([A-Z0-9_]*(KEY|SECRET|PASSWORD|TOKEN)[A-Z0-9_]*)=(.+)$/m',
            fn ($m) => $m[1].'=••••••••••••', $layers['custom']);
        $versions = $sites->envVersions($row);

        return [
            'envMasked' => $masked,
            // Variables can be put on the running application with no deploy when it is a live PHP application.
            'canApply' => Runtimes::has($row, 'env-file') && $row->current_release !== null && Gate::allows('deploy'),
            'injected' => array_map(fn ($r) => ['key' => $r['key'], 'source' => $r['source'], 'overridden' => $r['overridden'],
                'shown' => $secret($r['key']) ? '••••••••••••' : $r['value']], $layers['injected']),
            'overridden' => $layers['overridden'],
            'envCopies' => $sites->envCopies($row),
            // The newest of the user's own versions: the one nothing needs restoring to.
            'currentEnvVersion' => collect($versions)->firstWhere('layer', 'custom')['version'] ?? 0,
            'envVersions' => array_map(fn ($v) => $v + ['ago' => Present::ago($v['at'])], $versions),
        ];
    }

    /** What the site's data lives in, as the page says it. */
    private static function databaseOf(object $row, string $env): array
    {
        if ($row->database_cluster_id !== null) {
            $cluster = DB::table('resources')->where('organization_id', Org::id())->where('id', $row->database_cluster_id)->first(['slug', 'attributes']);
            $engine = DatabaseClusters::ENGINES[json_decode((string) ($cluster->attributes ?? '{}'), true)['engine'] ?? ''] ?? 'database';

            return ['managed' => true, 'kind' => 'managed', 'label' => "{$engine} on cluster ".($cluster->slug ?? '?').', database '.str_replace('-', '_', $row->name)];
        }

        // A database Unharbored made on the server itself.
        if (str_starts_with((string) ($row->database_mode ?? ''), 'local:')) {
            $service = substr((string) $row->database_mode, 6);

            return ['managed' => false, 'kind' => 'local', 'label' => (LocalServices::SERVICES[$service]['label'] ?? $service).' on '.(strstr($row->node_id, '.', true) ?: $row->node_id).', database '.str_replace('-', '_', $row->name)];
        }

        // No managed cluster: what the .env points at is the truth. It may be
        // SQLite, an external database (a detached one, or the user's own),
        // or nothing at all.
        $value = static function (string $key) use ($env): ?string {
            return preg_match('/^'.preg_quote($key, '/').'=["\']?([^"\'\s]*)/m', $env, $m) ? $m[1] : null;
        };
        $connection = $value('DB_CONNECTION');
        $name = $value('DB_DATABASE');
        $host = $value('DB_HOST');

        if ($connection === 'sqlite') {
            return ['managed' => false, 'kind' => 'sqlite', 'label' => 'SQLite on '.(strstr($row->node_id, '.', true) ?: $row->node_id)];
        }
        if ($connection !== null) {
            $engine = match ($connection) {
                'pgsql', 'postgres', 'postgresql' => 'PostgreSQL',
                'mysql', 'mariadb' => 'MySQL',
                default => $connection,
            };

            return ['managed' => false, 'kind' => 'external', 'label' => "External {$engine}".($host !== null ? " at {$host}" : '').($name !== null ? ", database {$name}" : '').' (unmanaged)'];
        }

        return ['managed' => false, 'kind' => 'none', 'label' => 'No database attached'];
    }

    /** @return array<string, mixed> */
    private static function database(object $row): array
    {
        $org = Org::id();

        return [
            'database' => self::databaseOf($row, app(Sites::class)->env($row)),
            'cache' => $row->cache_cluster_id
                ? 'Valkey on cluster '.(DB::table('resources')->where('organization_id', $org)->where('id', $row->cache_cluster_id)->value('slug') ?? '?')
                : 'the database, as Laravel defaults',
            'bucket' => $row->bucket_id ? DB::table('resources')->where('organization_id', $org)->where('id', $row->bucket_id)->value('external_id') : null,
            // One server and not a container: the engines a database can be put on, on that server.
            'localDatabases' => ($row->compute ?? 'single') === 'single' && ! Runtimes::isContainer($row)
                ? collect(LocalServices::SERVICES)->where('kind', 'database')->map(fn ($v, $k) => ['service' => $k, 'label' => $v['label']])->values()->all() : [],
            'backupDays' => SiteBackups::KEEP_DAYS,
            'backupsOn' => (bool) ($row->backups ?? false),
            'backups' => self::backups($row),
            'clusterBackups' => self::clusterBackups($row),
            'clones' => app(DatabaseClones::class)->list($row),
            'cloneSql' => (string) ($row->clone_conversion ?? ''),
            'cloneExamples' => self::cloneExamples($row),
            'clusters' => DB::table('resources')->where('organization_id', $row->organization_id)->where('type', 'database_cluster')
                ->where('state', 'active')->orderBy('slug')->get(['id', 'slug', 'attributes'])
                ->map(fn ($c) => ['id' => $c->id, 'label' => $c->slug.' · '.(DatabaseClusters::ENGINES[json_decode((string) $c->attributes, true)['engine'] ?? ''] ?? '?')])->all(),
        ];
    }

    /** The site's backups, newest first. Empty when there is no backup bucket, or Spaces cannot be reached right now. */
    private static function backups(object $row): array
    {
        $backups = app(SiteBackups::class);
        try {
            if (! $backups->enabled()) {
                return [];
            }

            return array_map(fn ($b) => ['object' => $b['object'], 'at' => $b['at']->format('Y-m-d H:i').' UTC', 'ago' => $b['at']->diffForHumans(),
                'kind' => ['nightly' => 'nightly', 'deploy' => 'before a deploy', 'manual' => 'by hand', 'restore' => 'before a restore'][$b['kind']] ?? $b['kind'],
                'size' => $b['bytes'] >= 1048576 ? number_format($b['bytes'] / 1048576, 1).' MB' : number_format($b['bytes'] / 1024, 1).' KB'],
                $backups->list($row));
        } catch (\Throwable) {
            return [];
        }
    }

    /** DigitalOcean's daily backups of the site's cluster. Empty for a site not on a cluster. */
    private static function clusterBackups(object $row): array
    {
        $cluster = $row->database_cluster_id ? app(ResourceManager::class)->findById((int) $row->database_cluster_id) : null;
        if ($cluster === null) {
            return [];
        }
        try {
            return array_map(fn ($b) => ['created_at' => $b['created_at'], 'at' => Carbon::parse($b['created_at'])->utc()->format('Y-m-d H:i').' UTC',
                'cluster' => $cluster->slug], app(DatabaseClusters::class)->backups($cluster, Sites::attachedAt($row)));
        } catch (\Throwable) {
            return [];
        }
    }

    /** Insert-example buttons that fit the application's database. */
    private static function cloneExamples(object $row): array
    {
        try {
            return CloneConversion::examples(app(SiteBackups::class)->engine($row));
        } catch (\RuntimeException) {
            return [];
        }
    }

    /**
     * Where a server could be made for this application: its own provider's regions and sizes. None for a provider that is not
     * connected through its API (a server reached over SSH cannot be made), or for someone who may not change infrastructure.
     *
     * @return array{label: string, regions: array<string, string>, region: string, sizes: array<string, string>, size: string}|null
     */
    public static function newServerChoices(object $row): ?array
    {
        if (! auth()->user()?->can('infrastructure') || Runtimes::isContainer($row)) {
            return null;
        }
        foreach (app(\App\Lib\Providers\Providers::class)->connected(Org::id()) as $p) {
            if ($p->id() !== (string) ($row->provider ?? 'digitalocean') || $p->method() !== 'api') {
                continue;
            }
            $c = $p->catalog();
            if ($c->sizes === []) {
                return null;
            }

            return ['label' => $p->label(), 'regions' => $c->regions, 'region' => $c->defaultRegion,
                'sizes' => array_map(\App\Lib\Providers\Catalog::sizeLabel(...), $c->sizes), 'size' => array_key_first($c->sizes)];
        }

        return null;
    }

    /** @return array<string, mixed> */
    private static function servers(object $row): array
    {
        $org = Org::id();

        return [
            'servers' => array_map(fn ($n) => ['id' => $n, 'name' => strstr($n, '.', true) ?: $n, 'primary' => $n === $row->node_id,
                'role' => in_array($n, Sites::workerNodes($row), true) ? Sites::ROLE_WORKER : Sites::ROLE_WEB,
                'pooled' => DB::table('site_nodes')->where('site_id', $row->id)->where('node_id', $n)->whereNotNull('autoscale_pool_id')->exists()], Sites::nodes($row)),
            'spare' => DB::table('nodes')->where('organization_id', $row->organization_id)->whereNotIn('node_id', Sites::nodes($row))
                ->whereNotIn('node_id', DB::table('site_nodes')->whereNotNull('autoscale_pool_id')->select('node_id'))
                ->orderBy('node_id')->pluck('node_id')->map(fn ($n) => ['id' => $n, 'name' => strstr($n, '.', true) ?: $n])->all(),
            'pool' => $row->autoscale_pool_id ? json_decode((string) DB::table('resources')->where('organization_id', $org)->where('id', $row->autoscale_pool_id)->value('attributes'), true) : null,
            'balancer' => $row->load_balancer_id
                ? (json_decode((string) DB::table('resources')->where('organization_id', $org)->where('id', $row->load_balancer_id)->value('attributes'), true)['ip'] ?? 'starting')
                : null,
            'scalerLimit' => ServerScaler::FAILURES_BEFORE_PAUSE,
            'newServer' => self::newServerChoices($row),
        ];
    }

    /** @return array<string, mixed> */
    private static function processes(object $row): array
    {
        return [
            'workers' => (int) $row->queue_workers,
            'workersMin' => (int) ($row->queue_workers_min ?? 0),
            'workersMax' => (int) ($row->queue_workers_max ?? 4),
            'jobsPerWorker' => WorkerScaling::JOBS_PER_WORKER,
            'idleMinutes' => WorkerScaling::IDLE_MINUTES,
            'scaling' => $row->queue_workers_max !== null ? ['min' => (int) $row->queue_workers_min, 'max' => (int) $row->queue_workers_max,
                'idle' => $row->queue_idle_since !== null ? Present::ago($row->queue_idle_since) : null] : null,
            'reverb' => (bool) $row->reverb,
            'reverbPort' => Sites::reverbPort($row),
            'daemons' => Sites::daemons($row),
            'presets' => LaravelServices::catalogue(),
            // Where a process runs only matters once the application has more than one server.
            'serverCount' => count(Sites::nodes($row)),
            'workerServers' => count(Sites::workerNodes($row)),
            'workersOn' => (string) ($row->workers_on ?? 'all'),
        ];
    }

    /** @return array<string, mixed> */
    private static function build(object $row): array
    {
        $own = json_decode((string) ($row->runtime_config ?? 'null'), true) ?: [];

        return [
            // What the application is, and the runtime's settings as they stand, for the form.
            'rt' => ['runtime' => Runtimes::runtime($row), 'root' => Sites::rootDir($row)] + array_map(fn ($v) => $v === null ? '' : $v, $own)
                + ['version' => '', 'install' => '', 'build' => '', 'release' => '', 'start' => '', 'output' => '', 'health' => '', 'port' => '', 'spa' => false],
            'buildScript' => ['script' => trim((string) ($row->build_script ?? '')) !== '' ? (string) $row->build_script : Sites::DEFAULT_BUILD_SCRIPT, 'node' => (string) ($row->node_version ?? ''), 'custom' => trim((string) ($row->build_script ?? '')) !== ''],
            // What is set (types and hosts, never the secrets), so the box can say so.
            'composerAuth' => app(Sites::class)->composerAuthSummary($row),
            // Handed down from the team and the project, shown so nobody pastes them again.
            'composerShared' => array_merge(
                array_map(fn ($h) => 'team: '.$h['host'], app(Sites::class)->sharedComposerHosts($row->organization_id, null)),
                array_map(fn ($h) => 'project: '.$h['host'], app(Sites::class)->sharedComposerHosts($row->organization_id, (int) ($row->project_id ?? 0) ?: null)),
            ),
            'runtimes' => Runtimes::choices(),
            'presets' => Runtimes::PRESETS,
            'runtimePort' => Runtimes::has($row, 'fpm') ? null : (Runtimes::config($row)['port'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private static function scheduler(object $row): array
    {
        $at = $row->schedule_tasks_at ?? null;
        $heartbeat = Heartbeats::find((int) $row->id, null);

        return [
            'scheduler' => (bool) $row->scheduler,
            'schedulerMonitored' => $heartbeat !== null,
            'schedulerPing' => Present::ago($heartbeat->last_ping_at ?? null),
            'phpBinary' => ServerStack::phpBinary(Sites::phpVersion($row)),
            'scheduleTasks' => Sites::scheduleTasks($row),
            'scheduleAge' => $at === null ? '' : Present::ago($at),
            'jobs' => array_map(fn ($j) => ['pinged' => $j['pinged'] ? Present::ago($j['pinged']) : null] + $j, Sites::scheduledJobs($row)),
            'jobFrequencies' => Sites::JOB_FREQUENCIES,
            'heartbeatGrace' => Heartbeats::GRACE_MINUTES,
            'multiServer' => count(Sites::nodes($row)) > 1,
        ];
    }

    /** @return array<string, mixed> */
    private static function web(object $row): array
    {
        $nginx = app(Sites::class)->nginx($row);

        return [
            'webUpload' => $nginx['upload_mb'],
            'webTimeout' => $nginx['timeout_s'],
            'webSnippet' => $nginx['snippet'],
            'webDefaults' => ['upload' => Sites::DEFAULT_UPLOAD_MB, 'timeout' => Sites::DEFAULT_TIMEOUT_S],
            'webPreview' => (static function () use ($row) {
                // A balanced site needs its primary's address to render; without one there is nothing to show.
                try {
                    return app(Sites::class)->generatedVhost($row);
                } catch (\Throwable) {
                    return '';
                }
            })(),
            // An upload is limited by the smaller of nginx's limit here and PHP's on the server.
            'webPhpNote' => (static function () use ($row, $nginx) {
                $php = PhpSettings::uploadLimitMb(PhpSettings::for($row->node_id, Sites::phpVersion($row)));
                $web = $nginx['upload_mb'];

                return abs($php - $web) > 0.01
                    ? 'PHP '.Sites::phpVersion($row).' on '.(strstr($row->node_id, '.', true) ?: $row->node_id).' accepts uploads of '.rtrim(rtrim(number_format($php, 1), '0'), '.').' MB and nginx here accepts '.$web.' MB. The smaller one wins. PHP\'s limit is set on the Servers page.'
                    : '';
            })(),
            'balanced' => $row->load_balancer_id !== null,
            'basicAuthUser' => $row->basic_auth_user ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private static function script(object $row): array
    {
        return [
            'scriptText' => Sites::serverScript($row)['content'],
            'scriptVersions' => array_map(fn ($v) => $v + ['ago' => Present::ago($v['at'])], Sites::serverScriptVersions($row)),
        ];
    }

    /** @return array<string, mixed> */
    private static function runtime(object $row): array
    {
        return [
            'phpVersion' => Sites::phpVersion($row),
            'phpVersions' => ServerStack::PHP_VERSIONS,
            'phpDefault' => ServerStack::PHP_VERSION,
            'phpInstalled' => array_map(function (string $node): array {
                $versions = DB::table('server_php_versions')->where('node_id', $node)->pluck('version')->all();

                return ['node' => strstr($node, '.', true) ?: $node,
                    'versions' => array_values(array_unique([ServerStack::PHP_VERSION, ...$versions]))];
            }, Sites::nodes($row)),
        ];
    }

    /** @return list<array<string, mixed>> */
    private static function previewList(object $row): array
    {
        $org = Org::id();

        return DB::table('sites')->where('organization_id', $org)->where('parent_site_id', $row->id)->orderByDesc('id')->get()->map(function ($p) use ($org) {
            $op = DB::table('operations')->where('organization_id', $org)->where('site_id', $p->id)->orderByDesc('id')->first(['id', 'status', 'kind', 'created_at']);

            return ['id' => $p->id, 'name' => $p->name, 'branch' => $p->branch, 'url' => ((bool) $p->tls ? 'https://' : 'http://').$p->domain,
                'follow' => (bool) $p->push_to_deploy, 'idle' => $p->preview_idle_days, 'database' => $p->preview_database, 'pr' => $p->pr_number, 'op' => $op ? ['id' => $op->id, 'status' => $op->status, 'ago' => Present::ago($op->created_at)] : null];
        })->all();
    }

    /** @return array<string, mixed> */
    private static function previews(object $row): array
    {
        return [
            'previews' => self::previewList($row),
            'previewServer' => Previews::server($row->organization_id, (string) ($row->provider ?? 'digitalocean')) !== null,
            'previewSeeder' => (string) ($row->preview_seeder ?? ''),
            'previewExclude' => implode(', ', json_decode((string) $row->preview_exclude, true) ?: []),
            'previewDefaultDatabase' => (string) ($row->preview_database ?? 'fresh'),
            'previewPrBase' => (string) ($row->pr_previews_base ?? ''),
            'previewOverrides' => $row->parent_site_id === null ? app(Previews::class)->overrides($row) : '',
        ];
    }

    /** @return array<string, mixed> */
    private static function source(object $row): array
    {
        $hook = rtrim((string) config('app.url'), '/').'/api/git/hook/'.$row->id.'/'.($row->git_source === 'deploy_key' ? app(Sites::class)->webhookSecret($row) : '');

        return [
            'repo' => $row->repository,
            'source' => $row->git_source,
            'root' => Sites::rootDir($row),
            'deployKey' => $row->deploy_key_public,
            'gitHost' => $row->repository === null ? null : GitRepo::parse($row->repository)->host,
            // Anyone with this address can ask for a deploy of the branch, nothing more; a host that signs its deliveries is checked too.
            'hook' => $row->git_source === 'deploy_key' ? $hook : null,
            // A plain Git server has no web hooks: this runs in the bare repository after each push and asks for the deploy.
            'hookScript' => $row->git_source === 'deploy_key' ? GitPush::postReceiveScript($row->branch, $hook) : null,
            'hostKey' => $row->git_host_key ?? ($row->git_source === 'deploy_key' ? 'built in for github.com' : null),
            'push' => (bool) $row->push_to_deploy,
            'appReady' => DB::table('github_apps')->where('organization_id', $row->organization_id)->exists(),
        ];
    }

    /** @return array<string, mixed> */
    private static function delete(object $row): array
    {
        $org = Org::id();

        return [
            'previews' => self::previewList($row),
            'pool' => $row->autoscale_pool_id ? json_decode((string) DB::table('resources')->where('organization_id', $org)->where('id', $row->autoscale_pool_id)->value('attributes'), true) : null,
            'balancer' => $row->load_balancer_id ? 'yes' : null,
            'uploadsOnSpaces' => (bool) preg_match('/^FILESYSTEM_DISK=s3$/m', app(Sites::class)->env($row)),
        ];
    }

    /** The permissions the page uses to show or hide controls, beyond the shared `can` flags. */
    public static function canEdit(): bool
    {
        return Gate::allows('edit');
    }
}
