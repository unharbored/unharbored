<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Audit\Outcome;
use App\Lib\Cloud\Spaces;
use App\Lib\Containers\AppRecipes;
use App\Lib\Containers\ContainerApps;
use App\Lib\Templates\Definition;
use App\Lib\Gateway\GatewayClient;
use App\Lib\GitHub\GitHubApp;
use App\Lib\Servers\ServerStack;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use stdClass;
use Throwable;

/**
 * Deploys a site's branch to its node as a new release.
 *
 * The order is the whole design. Everything up to activation builds a release
 * beside the live one, so a failure anywhere before it leaves the site
 * serving exactly what it was. Activation is a symlink rename on the node.
 *
 *   1. setup      directories, deploy key, .env, vhost (idempotent)
 *   2. fetch      clone the branch into releases/<id> as the deploy user
 *   3. prepare    link shared .env and storage into the release
 *   4. build      composer install, migrate, cache
 *   5. activate   swap current
 *   6. after      worker and cron config, supervisor update, reload
 *                 PHP-FPM, queue:restart onto the live release
 *   7. prune      keep the newest five releases, never current or the
 *                 rollback target
 *
 * Migrations run before activation, against the live database, while the
 * previous release is still serving. That is the conventional Laravel
 * trade-off and the reason migrations have to be backwards compatible.
 */
final class Deployer
{
    private const BUILD_TIMEOUT = 900;

    /** Seconds a server setup script may run. */
    private const SCRIPT_TIMEOUT = 900;

    public function __construct(
        private readonly Sites $sites,
        private readonly GatewayClient $gateway,
        private readonly AuditLog $audit,
        private readonly GitHubApp $github,
    ) {}

    /** Stage names, in order, as $progress reports them. */
    public const STAGES = ['setup', 'fetch', 'prepare', 'build', 'activate', 'workers', 'prune'];

    /** @var (Closure(string, string): void)|null */
    private ?Closure $log = null;

    /** The server the current step runs on, and whether there are several. */
    private ?string $node = null;

    private bool $multi = false;

    /**
     * @param  (Closure(string): void)|null  $progress  Called with each stage in STAGES as it starts.
     * @param  (Closure(string, string): void)|null  $log  Called with (action, text) for each step the node reports.
     * @return array{release: string, commit: string}
     *
     * @throws RuntimeException When any stage fails. The live release is unchanged.
     */
    public function deploy(stdClass $site, ?Closure $progress = null, ?string $now = null, ?Closure $log = null): array
    {
        if (Runtimes::isContainer($site)) {
            return $this->deployContainer($site, $progress, $now, $log);
        }
        $progress ??= static function (string $stage): void {};
        $this->log = $log;
        $startedAt = now();
        $releaseId = self::releaseId($now ?? gmdate('Ymd\THis\Z'), $site->branch);
        $path = Sites::root($site)."/releases/{$releaseId}";
        $resource = "{$site->node_id}/{$site->name}";

        // Every stage runs on every server before the next stage starts, so
        // nothing switches anywhere until the release is built everywhere.
        // On one server this is exactly the single-server deploy.
        $nodes = Sites::nodes($site);
        $this->multi = count($nodes) > 1;
        $switched = [];
        // With a bucket to pass it through, the primary builds once and the
        // others download that build: every server runs the same commit and
        // the same vendor/, and only the primary needs to reach GitHub.
        // Without one, each server clones and builds for itself.
        // A site with an autoscale pool always packs its build, even on one
        // server: a member DigitalOcean adds later unpacks it, and keeps it
        // for as long as the release is kept on disk, for rollbacks.
        $pooled = ($site->autoscale_pool_id ?? null) !== null;
        $artifact = ($this->multi || $pooled) && ($site->bucket_id ?? null) !== null ? $this->artifact($site, $releaseId) : null;
        $builders = $artifact !== null ? [$site->node_id] : $nodes;

        try {
            $progress('setup');
            $this->assertAgentsLinkRoot($site, $nodes);
            foreach ($nodes as $node) {
                $this->recipe($this->at($site, $node), $this->sites->setupRecipe($this->at($site, $node)));
                $this->ensureServerScript($this->at($site, $node));
            }

            $progress('fetch');
            $this->node = null;
            $this->say('release', "{$releaseId} from {$site->branch}".($this->multi ? ' to '.count($nodes).' servers' : ''));
            $token = $this->sourceToken($site);
            foreach ($builders as $node) {
                $this->cloneRelease($this->at($site, $node), $path, $token);
            }

            if (Sites::rootDir($site) !== '') {
                foreach ($builders as $node) {
                    $this->recipe($this->at($site, $node), ['name' => 'root-folder', 'steps' => [
                        ['id' => 'root-folder', 'type' => 'command', 'name' => 'test', 'args' => ['-d', Sites::appDir($site, $path)],
                            'description' => 'the root folder '.Sites::rootDir($site).' is in the repository on '.$site->branch],
                    ]]);
                }
            }

            $progress('prepare');
            foreach ($builders as $node) {
                $this->command($this->at($site, $node), 'deploy', $this->prepareArgs($site, $releaseId));
                $this->say('link', '.env, storage → shared');
            }

            // Migrations run once, on the primary, while every server still
            // serves the old release: the usual reason they must be
            // backwards compatible, now across servers too.
            $progress('build');
            // Migrations run next, on the primary. Where the application has
            // backups switched on, a copy of the data first, while nothing has
            // changed: a failed backup stops the deploy with the live release untouched.
            if ($site->current_release !== null && $site->parent_site_id === null && ($site->backups ?? false) && app(SiteBackups::class)->enabled()) {
                $this->node = null;
                $taken = app(SiteBackups::class)->take($site, 'deploy');
                $this->say('backup', 'database copied to '.basename($taken['object']).' before migrations');
            }
            $php = Sites::phpVersion($site);
            foreach ($builders as $node) {
                $this->recipe($this->at($site, $node), ! Runtimes::has($site, 'composer') ? Runtimes::buildRecipe($site, Sites::appDir($site, $path), $node === $site->node_id) : $this->buildRecipe(Sites::appDir($site, $path), migrate: $node === $site->node_id && $this->migrates($site), seeder: $node === $site->node_id ? $this->seeder($site) : null, php: $php, composerHome: $this->sites->hasComposerAuth($site) ? Sites::composerHome($site) : null, scriptOf: $site), self::BUILD_TIMEOUT);
            }
            if ($artifact !== null) {
                $this->ship($site, $nodes, $path, $releaseId, $artifact);
            }

            $commit = trim((string) ($this->command($this->at($site, $site->node_id), 'exec', [
                'name' => 'runuser',
                'args' => ['-u', ServerStack::DEPLOY_USER, '--', 'git', '-C', $path, 'rev-parse', 'HEAD'],
            ])['detail']['output'] ?? ''));
            $this->say('commit', $commit);

            if ($artifact !== null && ! $pooled) {
                $this->forget($artifact);
            }

            $progress('activate');
            foreach ($nodes as $node) {
                $this->command($this->at($site, $node), 'activate_release', ['site' => $site->name, 'release_id' => $releaseId]);
                $switched[] = $node;
                $this->say('switch', "current → {$releaseId}");
            }
        } catch (Throwable $e) {
            if ($artifact !== null) {
                $this->forget($artifact);
            }
            // A switch that failed part way leaves some servers on the new
            // release and some on the old. Put the switched ones back, so the
            // site is on one release again and "unchanged" stays true.
            foreach ($switched as $node) {
                try {
                    $this->command($this->at($site, $node), 'rollback', ['site' => $site->name]);
                    $this->say('undo', 'switched back after a failure elsewhere');
                } catch (Throwable $undo) {
                    $this->say('error', "could not switch back: {$undo->getMessage()}");
                }
            }
            $this->node = null;
            $this->audit->record(
                organizationId: $site->organization_id,
                actor: $this->actor(),
                action: 'site.deploy_failed',
                outcome: Outcome::Failed,
                resourceType: 'site',
                resourceId: $resource,
                metadata: ['release' => $releaseId, 'servers' => $nodes, 'error' => mb_substr($e->getMessage(), 0, 500)],
            );

            // A server whose software install never finished has no deploy user; say so, since that is the thing to fix.
            $hint = str_contains($e->getMessage(), 'unknown user '.ServerStack::DEPLOY_USER)
                ? ' The server\'s software was never installed (its setup failed part way). On Servers, use "Repair stack" for this server, then deploy again.' : '';

            throw new RuntimeException("Deploy of {$releaseId} failed; the live release is unchanged. ".$e->getMessage().$hint, previous: $e);
        }
        $this->node = null;

        // Whatever was waiting for a deploy went out with this one, provided
        // it was saved before the deploy started.
        DB::table('sites')->where('id', $site->id)->update(['current_release' => $releaseId, 'updated_at' => now()]);
        DB::table('sites')->where('id', $site->id)->where('pending_since', '<=', $startedAt)->update(['pending_since' => null]);

        // A cloned preview's first release: its database is still empty, so
        // the copy goes in now, before workers start on it, and the branch's
        // migrations on top. It needs the release live to run artisan.
        if (($site->parent_site_id ?? null) !== null && ($site->preview_database ?? null) === 'clone' && $site->current_release === null) {
            $parent = DB::table('sites')->where('id', $site->parent_site_id)->first();
            try {
                $this->node = null;
                app(SiteBackups::class)->cloneInto($this->sites->find($site->node_id, $site->name), $parent, fn (string $step, string $line) => $this->say($step, $line));
            } catch (Throwable $e) {
                throw new RuntimeException("{$releaseId} is live, but copying {$parent->name}'s database into it failed: ".$e->getMessage(), previous: $e);
            }
        }

        // From here the new release is live. A failure is reported as such,
        // never as "unchanged": the code is serving, but workers or the
        // scheduler may still be on the old release.
        try {
            $progress('workers');
            $this->programsEverywhere($site, $nodes);
            foreach ($nodes as $node) {
                $this->afterSwitch($this->at($site, $node), $site->current_release, scheduler: $node === $site->node_id);
            }
        } catch (Throwable $e) {
            $this->node = null;
            $this->audit->record(
                organizationId: $site->organization_id,
                actor: $this->actor(),
                action: 'site.deploy_failed',
                outcome: Outcome::Failed,
                resourceType: 'site',
                resourceId: $resource,
                metadata: ['release' => $releaseId, 'stage' => 'after_switch', 'error' => mb_substr($e->getMessage(), 0, 500)],
            );

            throw new RuntimeException("{$releaseId} is live, but restarting workers or PHP failed: ".$e->getMessage(), previous: $e);
        }
        $this->node = null;

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: $this->actor(),
            action: 'site.deployed',
            resourceType: 'site',
            resourceId: $resource,
            metadata: ['release' => $releaseId, 'commit' => $commit, 'branch' => $site->branch, 'servers' => $nodes],
        );

        // Housekeeping. A failure here, including an agent too old to know
        // the command, must not report a live deploy as failed.
        $progress('prune');
        foreach ($nodes as $node) {
            try {
                $deleted = $this->command($this->at($site, $node), 'prune_releases', ['site' => $site->name])['detail']['deleted'] ?? [];
                $this->say('prune', $deleted === [] ? 'nothing to remove' : 'removed '.implode(', ', $deleted));
                if ($pooled && $artifact !== null && $node === $site->node_id) {
                    foreach ($deleted as $old) {
                        $this->forget($this->artifact($site, (string) $old));
                    }
                }
            } catch (Throwable $e) {
                $this->say('skip', 'prune: '.$e->getMessage());
            }
        }
        $this->node = null;

        return ['release' => $releaseId, 'commit' => $commit];
    }

    /** A read-only GitHub App token for a GitHub App repository, one hour; null for every other way of reaching git. */
    private function sourceToken(stdClass $site): ?string
    {
        if ($site->git_source !== 'github_app') {
            return null;
        }
        $token = $this->github->installationToken($site->organization_id, GitHubApp::fullName($site->repository));
        $this->say('token', 'GitHub App token for '.GitHubApp::fullName($site->repository).', read-only, one hour');

        return $token;
    }

    /** Clones the branch into $path on one server. A token file is removed afterwards even when the clone fails. */
    private function cloneRelease(stdClass $on, string $path, ?string $token, bool $storage = true): void
    {
        try {
            $this->recipe($on, $this->fetchRecipe($on, $path, $token, $storage));
        } finally {
            // The recipe deletes the token after cloning, but stops at
            // the first failure. Never leave it behind.
            if ($token !== null) {
                try {
                    $this->recipe($on, ['name' => 'forget-token', 'steps' => [
                        ['id' => 'forget-token', 'type' => 'absent', 'path' => self::tokenPath($on), 'owner' => ServerStack::DEPLOY_USER, 'base' => '/home/'.ServerStack::DEPLOY_USER],
                    ]]);
                } catch (Throwable) {
                }
            }
        }
    }

    /** The site pointed at one server, remembered so log lines can name it. */
    private function at(stdClass $site, string $node): stdClass
    {
        $this->node = $node;

        return Sites::on($site, $node);
    }

    /**
     * Points current back at the previous release, then brings workers and
     * PHP along with it.
     */
    /**
     * @param  (Closure(string, string): void)|null  $log
     */
    /** Who the audit log names for what this deployer does next: the person who asked, else the control plane. */
    private ?Actor $actingAs = null;

    public function actingAs(?Actor $actor): static
    {
        $this->actingAs = $actor;

        return $this;
    }

    private function actor(): Actor
    {
        return $this->actingAs ?? Actor::system('control-plane');
    }

    public function rollback(stdClass $site, ?Closure $log = null): string
    {
        if (Runtimes::isContainer($site)) {
            return $this->rollbackContainer($site, $log);
        }
        $this->log = $log;
        $previous = $site->current_release;
        $nodes = Sites::nodes($site);
        $this->multi = count($nodes) > 1;
        $current = '';
        foreach ($nodes as $node) {
            $now = (string) ($this->command($this->at($site, $node), 'rollback', ['site' => $site->name])['detail']['current'] ?? '');
            $current = $node === $site->node_id ? $now : $current;
            $this->say('switch', "current → {$now}");
        }
        if ($current === '') {
            // Recording a blank release would leave the site with nothing marked live.
            throw new RuntimeException('The server did not say which release it switched to, so Unharbored has not changed what it shows as live. Check the site, then try again.');
        }
        DB::table('sites')->where('id', $site->id)->update(['current_release' => $current, 'updated_at' => now()]);

        $this->programsEverywhere($site, $nodes);
        foreach ($nodes as $node) {
            $this->afterSwitch($this->at($site, $node), $previous, scheduler: $node === $site->node_id);
        }
        $this->node = null;

        $this->audit->record(
            organizationId: $site->organization_id,
            actor: $this->actor(),
            action: 'site.rolled_back',
            resourceType: 'site',
            resourceId: "{$site->node_id}/{$site->name}",
            metadata: ['release' => $current],
        );

        return $current;
    }

    /**
     * The site's supervisor programs, written and loaded, with only the
     * workers scaling wants left running.
     *
     * Run on every server before any of them restarts workers: the restart
     * signal lives in the cache the servers share, so a worker on a server
     * not yet updated restarts while supervisor there is about to stop its
     * group for a changed process count, and a worker stopped as it starts
     * can miss the signal and hold supervisor for its full stop timeout.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function programsRecipe(stdClass $site): array
    {
        $programs = $this->programs($site);
        $conf = "/etc/supervisor/conf.d/cm-{$site->name}.conf";
        $steps = [];
        if ($programs !== []) {
            // Supervisor opens these logs as root, so the folder is root's alone:
            // a log under the application's own storage could be swapped for a
            // symlink to a privileged file (audit 2026-11-08, #2).
            $steps[] = ['id' => 'supervisor-log-root', 'type' => 'dir', 'path' => Sites::SUPERVISOR_LOG_ROOT, 'mode' => '0755'];
            $steps[] = ['id' => 'supervisor-log-dir', 'type' => 'dir', 'path' => Sites::supervisorLogDir($site), 'mode' => '0755'];
        }
        $steps[] = $programs === []
            ? ['id' => 'supervisor-conf', 'type' => 'absent', 'path' => $conf]
            : ['id' => 'supervisor-conf', 'type' => 'file', 'path' => $conf, 'content_b64' => base64_encode($this->supervisorConf($site, $programs)), 'mode' => '0644'];

        // Adds, removes or restarts program groups whose configuration
        // changed, and leaves the rest alone.
        $steps[] = ['id' => 'supervisor-update', 'type' => 'command', 'name' => 'supervisorctl', 'args' => ['update'], 'description' => 'supervisor picks up program changes'];
        // A changed group starts every process; only those scaling wants
        // keep running.
        if (($site->queue_workers_max ?? null) !== null) {
            $steps[] = ['id' => 'workers-scaled', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', WorkerScaling::command($site->name, (int) $site->queue_workers, (int) $site->queue_workers_max)],
                'description' => "{$site->queue_workers} of up to {$site->queue_workers_max} workers running, as the queue needs"];
        }

        return ['name' => "programs-{$site->name}", 'steps' => $steps];
    }

    /**
     * Everything that must follow the live release rather than precede it.
     *
     * queue:restart has to run after the swap: workers exit and supervisor
     * starts them again, resolving current at that moment. Run before the
     * swap, they come back up on the old code, which is the failure the demo
     * app's stale-worker check exists to catch.
     *
     * Worker and cron configuration are written here too, so on a first
     * deploy nothing starts against a current that does not exist yet.
     *
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function afterSwitchRecipe(stdClass $site, ?string $previousRelease = null, bool $scheduler = true): array
    {
        $user = ServerStack::DEPLOY_USER;
        $current = Sites::current($site);
        $programs = $this->programs($site);
        $php = Sites::phpVersion($site);
        $binary = ServerStack::phpBinary($php);

        $steps = $this->programsRecipe($site)['steps'];

        // Not PHP: no scheduler, FPM or queue. What runs is the application's own process, restarted onto the
        // live release and waited for; a static site has nothing to restart.
        if (! Runtimes::has($site, 'fpm')) {
            foreach ($programs as $program) {
                if ($program['restart']) {
                    $steps[] = ['id' => "restart-{$program['name']}", 'type' => 'command', 'name' => 'supervisorctl', 'args' => ['restart', "{$program['name']}:*"],
                        'description' => "{$program['name']} restarts onto the live release"];
                }
            }
            array_push($steps, ...Runtimes::healthSteps($site));

            return ['name' => "after-switch-{$site->name}", 'steps' => $steps];
        }

        // Files in /etc/cron.d are ignored if their name contains a dot, and
        // must end in a newline. Site names cannot contain dots.
        // One server runs the scheduler, the primary. On every other server
        // the entry is removed, or every scheduled job would run once per
        // server.
        $steps[] = $this->schedulerStep($site, $scheduler);

        $steps[] = ['id' => 'reload-php-fpm', 'type' => 'service', 'name' => "php{$php}-fpm", 'state' => 'reloaded'];
        $steps[] = [
            'id' => 'queue-restart',
            'type' => 'command',
            'name' => 'runuser',
            'args' => ['-u', $user, '--', $binary, "{$current}/artisan", 'queue:restart'],
            'description' => 'workers restart onto the live release',
        ];

        // The restart signal is stored in the app's cache. If this deploy
        // moved the cache (attaching a database moves a database-backed
        // cache), the workers still running read the old store and never
        // see a signal written to the new one: seen live, the demo's worker
        // kept polling SQLite after the switch to PostgreSQL. So send it
        // through the previous release too, which reads the old store.
        if ($previousRelease !== null && Deployer::isReleaseId($previousRelease)) {
            $old = Sites::appDir($site, Sites::root($site)."/releases/{$previousRelease}").'/artisan';
            $steps[] = [
                'id' => 'queue-restart-previous',
                'type' => 'command',
                'name' => 'sh',
                'args' => ['-c', "[ ! -f {$old} ] || runuser -u {$user} -- {$binary} {$old} queue:restart || true"],
                'description' => 'workers on the previous release are told too',
            ];
        }

        // Daemons have no graceful restart signal of their own, so supervisor
        // restarts them. They resolve current as they start, which is now
        // the new release.
        foreach ($programs as $program) {
            if ($program['restart']) {
                $steps[] = [
                    'id' => "restart-{$program['name']}",
                    'type' => 'command',
                    'name' => 'supervisorctl',
                    'args' => ['restart', "{$program['name']}:*"],
                    'description' => "{$program['name']} restarts onto the live release",
                ];
            }
        }

        return ['name' => "after-switch-{$site->name}", 'steps' => $steps];
    }

    /** @param  list<string>  $nodes */
    private function programsEverywhere(stdClass $site, array $nodes): void
    {
        if (count($nodes) > 1) {
            foreach ($nodes as $node) {
                $on = $this->at($site, $node);
                $this->recipe($on, $this->programsRecipe($on));
            }
        }
    }

    /**
     * Whether this deploy migrates. A shared preview never does: the database
     * is its parent's. A cloned one's first deploy leaves it to the clone,
     * which migrates once the copy is loaded.
     */
    private function migrates(stdClass $site): bool
    {
        return match ($site->preview_database ?? 'fresh') {
            'shared' => ($site->parent_site_id ?? null) === null,
            'clone' => ($site->parent_site_id ?? null) === null || $site->current_release !== null,
            default => true,
        };
    }

    /** The seeder an empty preview's first deploy runs: its parent's, if set. */
    private function seeder(stdClass $site): ?string
    {
        if (($site->parent_site_id ?? null) === null || $site->current_release !== null || in_array($site->preview_database ?? 'fresh', ['clone', 'shared'], true)) {
            return null;
        }
        $seeder = DB::table('sites')->where('id', $site->parent_site_id)->value('preview_seeder');

        return $seeder !== null && preg_match('/^[A-Za-z0-9_\\\\]+$/', $seeder) ? $seeder : null;
    }

    private function afterSwitch(stdClass $site, ?string $previousRelease = null, bool $scheduler = true): void
    {
        $this->recipe($site, $this->afterSwitchRecipe($site, $previousRelease, $scheduler));
    }

    /**
     * Every supervisor program the site runs.
     *
     * @return list<array{name: string, command: string, processes: int, stopwait: int, restart: bool}>
     */
    private function programs(stdClass $site): array
    {
        $php = ServerStack::phpBinary(Sites::phpVersion($site));
        $artisan = Sites::current($site).'/artisan';
        $programs = Runtimes::has($site, 'fpm') ? [] : Runtimes::programs($site);

        // Scaling sites get the most a server may run; WorkerScaling starts
        // and stops processes within it.
        $workers = Runtimes::has($site, 'workers') ? (int) ($site->queue_workers_max ?? $site->queue_workers ?? 1) : 0;
        if ($workers > 0) {
            // stopwaitsecs matches --max-time so a stop lets a long job
            // finish. queue:restart, not supervisor, moves these on deploy.
            $programs[] = ['name' => "cm-{$site->name}-worker", 'command' => "{$php} {$artisan} queue:work --sleep=3 --tries=3 --max-time=3600",
                'processes' => $workers, 'stopwait' => 3600, 'restart' => false];
        }

        if (Runtimes::has($site, 'workers') && (bool) ($site->reverb ?? false)) {
            $port = Sites::reverbPort($site);
            $programs[] = ['name' => "cm-{$site->name}-reverb", 'command' => "{$php} {$artisan} reverb:start --host=127.0.0.1 --port={$port}",
                'processes' => 1, 'stopwait' => 10, 'restart' => true];
        }

        foreach (Sites::daemons($site) as $daemon) {
            $command = preg_replace('/^php /', "{$php} ", $daemon['command']);
            $port = isset($daemon['port']) ? (int) $daemon['port'] : null;
            $programs[] = ['name' => "cm-{$site->name}-{$daemon['name']}", 'command' => $port !== null ? str_replace('{port}', (string) $port, (string) $command) : (string) $command,
                'processes' => (int) $daemon['processes'], 'stopwait' => 60, 'restart' => true] + ($port !== null ? ['port' => $port] : []);
        }

        return $programs;
    }

    /**
     * @param  list<array{name: string, command: string, processes: int, stopwait: int, restart: bool}>  $programs
     */
    private function supervisorConf(stdClass $site, array $programs): string
    {
        $root = Sites::root($site);
        $appCurrent = Sites::current($site);
        $user = ServerStack::DEPLOY_USER;
        $logDir = Sites::supervisorLogDir($site);
        $out = "; Managed by cloud-manager. Changes here are overwritten on deploy.\n";

        foreach ($programs as $p) {
            // % starts supervisor's own interpolation.
            $command = str_replace('%', '%%', $p['command']);
            $log = substr($p['name'], strlen("cm-{$site->name}-"));
            // A process that listens on a port is told which: PORT, as most servers read it.
            $environment = isset($p['port']) ? ',PORT="'.(int) $p['port'].'"' : '';

            $out .= <<<CONF

[program:{$p['name']}]
process_name=%(program_name)s_%(process_num)02d
command={$command}
directory={$appCurrent}
user={$user}
environment=HOME="/home/{$user}"{$environment}
numprocs={$p['processes']}
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
stopwaitsecs={$p['stopwait']}
redirect_stderr=true
stdout_logfile={$logDir}/{$log}.log
stdout_logfile_maxbytes=10MB
stdout_logfile_backups=3

CONF;
        }

        return $out;
    }

    /**
     * Issues a certificate for the site's domain and switches the vhost to
     * HTTPS. The domain must already resolve to the node, since Let's Encrypt
     * fetches the challenge from it over port 80.
     *
     * The order guards the one way this takes a site down: nginx refuses to
     * load a vhost whose certificate file is missing, so TLS is only switched
     * on after the certificate exists, and nginx -t runs before the reload.
     */
    public function secure(stdClass $site): stdClass
    {
        // Let's Encrypt reaches a server behind an Incus host's ingress through it: the name must route first.
        $this->syncIngress($site);
        $this->recipe($site, $this->sites->setupRecipe($site));
        $this->recipe($site, $this->sites->certificateRecipe($site), 180);

        $site = $this->sites->markTls($site);
        foreach (Sites::nodes($site) as $node) {
            $this->recipe(Sites::on($site, $node), $this->sites->setupRecipe(Sites::on($site, $node)));
        }
        try {
            $this->readCertificateExpiry($site);
        } catch (\Throwable $e) {
            $this->say('certificate', 'could not read the expiry: '.$e->getMessage()); // Issued fine; the date can be refreshed later.
        }

        return $site;
    }

    /**
     * Puts an uploaded certificate on the site's primary server and switches
     * its vhost to it. Only the primary's vhost has an ssl_certificate: with a
     * load balancer TLS ends at the balancer, and extra servers answer port
     * 80 only, so the private key goes nowhere else. The server writes the
     * files, checks they match, switches the vhost, runs nginx -t and reloads
     * (the vhost steps put the old config back if nginx refuses). Only then
     * does the database change; if the database write fails too, or anything
     * else does, the files and vhost are put back and the failure is
     * rethrown, so a bad upload never leaves the database and the server
     * disagreeing or nginx unable to reload.
     *
     * @param  array{fullchain: string, key: string, expires_at: \DateTimeInterface}  $bundle
     */
    public function uploadCertificate(stdClass $site, #[\SensitiveParameter] array $bundle, ?Closure $log = null): void
    {
        $this->log = $log;
        $this->multi = false;
        $custom = clone $site;
        $custom->cert_source = 'custom';
        try {
            $this->recipe($custom, $this->sites->uploadCertificateRecipe($custom, $bundle['fullchain'], $bundle['key']));
            $this->say('certificate', 'uploaded; nginx checked and reloaded');
            $this->setCertificateSource($site, 'custom', $bundle['expires_at']);
        } catch (\Throwable $e) {
            try {
                $this->recipe($site, $this->sites->restoreCertificateRecipe($site->domain));
                $this->recipe($site, $this->sites->vhostRecipe($site));
            } catch (\Throwable) {
                $this->say('rollback', 'could not put the previous certificate back on this server; check it by hand');
            }
            throw $e;
        }

        // Housekeeping: the copy kept aside, and certbot's lineage so its timer stops renewing a name it no longer serves.
        try {
            $this->recipe($custom, $this->sites->discardCertificateBackupRecipe($site->domain));
            $this->recipe($custom, $this->sites->retireCertificateRecipe($site->domain));
        } catch (\Throwable $e) {
            $this->say('certbot', 'could not remove the old Let\'s Encrypt renewal here (the uploaded certificate is already live): '.mb_substr($e->getMessage(), 0, 200));
        }
    }

    /**
     * Back to Let's Encrypt: a certificate is issued first (an expired
     * lineage is cleared so the issue is real; a valid one from an earlier
     * attempt is kept and reused), then the primary's vhost switches, then
     * the database, then the uploaded files go. A failure after the switch,
     * database write included, puts the vhost back.
     */
    public function revertToLetsEncrypt(stdClass $site, ?Closure $log = null): void
    {
        $this->log = $log;
        $this->multi = false;
        $this->recipe($site, $this->sites->clearStaleCertificateRecipe($site->domain));
        $this->recipe($site, $this->sites->certificateRecipe($site), 180);
        $this->say('certificate', "Let's Encrypt certificate ready");

        $letsEncrypt = clone $site;
        $letsEncrypt->cert_source = 'letsencrypt';
        try {
            $this->recipe($letsEncrypt, $this->sites->vhostRecipe($letsEncrypt));
            $this->setCertificateSource($site, 'letsencrypt', null);
        } catch (\Throwable $e) {
            try {
                $this->recipe($site, $this->sites->vhostRecipe($site));
            } catch (\Throwable) {
            }
            throw $e;
        }

        try {
            $this->readCertificateExpiry($letsEncrypt);
        } catch (\Throwable $e) {
            $this->say('certificate', 'could not read the expiry: '.$e->getMessage());
        }
        $this->retireCustomFiles($site, $site->domain);
    }

    /**
     * Removes the uploaded files and the copy kept aside from every server
     * (older uploads went to all of them). Quiet when the files are no longer
     * served and failing only costs tidiness; $loud rethrows, for when a
     * failure would leave a private key nobody remembers.
     */
    private function retireCustomFiles(stdClass $site, string $domain, bool $loud = false): void
    {
        foreach (Sites::nodes($site) as $node) {
            $this->node = $node;
            try {
                $this->recipe(Sites::on($site, $node), $this->sites->retireCustomCertificateRecipe($domain));
            } catch (\Throwable $e) {
                if ($loud) {
                    $this->node = null;
                    throw $e;
                }
                $this->say('certificate', 'could not remove the uploaded files here: '.mb_substr($e->getMessage(), 0, 200));
            }
        }
        $this->node = null;
    }

    /**
     * The database write, tried once more if it fails: it is a single update,
     * so a second failure is a real outage and is rethrown for the caller to roll back.
     */
    private function setCertificateSource(stdClass $site, string $source, ?\DateTimeInterface $expiresAt): void
    {
        try {
            $this->sites->setCertificateSource($site, $source, $expiresAt);
        } catch (\Throwable) {
            $this->sites->setCertificateSource($site, $source, $expiresAt);
        }
    }

    /** Sets certbot's autorenew for the site's certificate on its home server. Throws if the node could not do it; the caller then leaves the database alone. */
    public function setCertificateAutoRenew(stdClass $site, bool $on): void
    {
        $this->recipe($site, $this->sites->autoRenewRecipe($site->domain, $on));
    }

    /**
     * Forces a new certificate now. certbot's failure text (rate limits included) comes back in the exception.
     * Without a terminal certbot sleeps a random time (up to about 8 minutes) before renewing, which outlasts the
     * timeout, so the sleep is switched off: someone pressing Renew now is waiting.
     */
    public function renewCertificate(stdClass $site): void
    {
        $this->command($site, 'exec', ['name' => 'certbot', 'args' => ['renew', '--cert-name', $site->domain, '--force-renewal', '--no-random-sleep-on-renew', '--deploy-hook', 'systemctl reload nginx']], 180);
    }

    /** Asks the node when the site's certificate ends, and keeps it. Throws if it cannot be read. */
    public function readCertificateExpiry(stdClass $site): Carbon
    {
        $result = $this->command($site, 'exec', ['name' => 'openssl', 'args' => ['x509', '-enddate', '-noout', '-in', Sites::certificateFiles($site)[0]]], 30);
        $expires = self::parseCertificateEnd((string) ($result['detail']['output'] ?? ''))
            ?? throw new RuntimeException('The server did not report a certificate end date.');
        DB::table('sites')->where('id', $site->id)->update(['cert_expires_at' => $expires]);

        return $expires;
    }

    /** The date in openssl's `notAfter=Feb 12 10:00:00 2027 GMT`, or null if there is none. */
    public static function parseCertificateEnd(string $output): ?Carbon
    {
        if (! preg_match('/^notAfter=(.+?)\s*$/m', $output, $m)) {
            return null;
        }
        try {
            return Carbon::createFromFormat('M j H:i:s Y T', preg_replace('/\s+/', ' ', $m[1]), 'UTC')?->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Moves the site to a new domain, over HTTPS if it was on HTTPS, and
     * retires the old certificate. Callers check DNS first: once the vhost
     * moves, the old name stops being served.
     */
    public function moveDomain(stdClass $site, string $domain): stdClass
    {
        $old = $site->domain;
        $wasTls = (bool) $site->tls;
        $wasCustom = ($site->cert_source ?? 'letsencrypt') === 'custom';

        // changeDomain puts the site back on Let's Encrypt: an uploaded certificate does not cover the new name.
        $site = $this->sites->changeDomain($site, $domain);
        // Behind an Incus host's ingress, the new name must route before Let's Encrypt checks it.
        $this->syncIngress($site);
        // The other servers learn the new name first: behind a balancer, the
        // certificate challenge for it may reach any of them.
        foreach (array_slice(Sites::nodes($site), 1) as $node) {
            $this->recipe(Sites::on($site, $node), $this->sites->setupRecipe(Sites::on($site, $node)));
        }

        if ($wasCustom) {
            // The database no longer remembers the old name, so the uploaded key goes now, not after
            // secure(): first a vhost that does not read it (TLS is off until the new certificate
            // exists), then the files. A failed issue below can no longer orphan the key.
            $this->recipe($site, $this->sites->setupRecipe($site));
            $this->retireCustomFiles($site, $old, loud: true);
        }

        if ($wasTls) {
            $site = $this->secure($site);
            if (! $wasCustom) {
                $this->recipe($site, $this->sites->retireCertificateRecipe($old));
            }
        } elseif (! $wasCustom) {
            $this->recipe($site, $this->sites->setupRecipe($site));
        }

        return $site;
    }

    /**
     * Mirrors deploy.NewReleaseID: a UTC timestamp and the ref reduced to
     * characters safe in a path segment. Characters outside the set are
     * dropped rather than replaced, so two refs cannot collapse together.
     */
    /** Whether a string is a release id the agent would accept: safe in a path and a shell word. */
    public static function isReleaseId(string $id): bool
    {
        return (bool) preg_match('/^[0-9]{8}T[0-9]{6}Z-[A-Za-z0-9._-]{1,64}$/', $id) && ! str_contains($id, '..');
    }

    public static function releaseId(string $timestamp, string $ref): string
    {
        $clean = trim((string) preg_replace('/[^A-Za-z0-9._-]/', '', $ref), '.');
        $clean = $clean === '' ? 'unknown' : substr($clean, 0, 64);

        return "{$timestamp}-{$clean}";
    }

    /**
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    public function fetchRecipe(stdClass $site, string $path, #[\SensitiveParameter] ?string $token = null, bool $storage = true): array
    {
        $user = ServerStack::DEPLOY_USER;
        $shared = Sites::root($site).'/shared';

        $steps = match (true) {
            ($site->git_source ?? null) === 'https' => [$this->cloneAnonymously($site, $path)],
            $token === null => [$this->cloneWithDeployKey($site, $path)],
            default => $this->cloneWithToken($site, $path, $token),
        };

        // A container application needs the code and nothing of Laravel's shared folders.
        if (! $storage) {
            return ['name' => "fetch-{$site->name}", 'steps' => $steps];
        }

        return ['name' => "fetch-{$site->name}", 'steps' => [
            ...$steps,
            [
                // Shared storage is empty on the first deploy, and Laravel
                // will not boot without its framework directories. Copy the
                // repository's skeleton in, never overwriting what is there.
                'id' => 'seed-storage',
                'type' => 'command',
                'name' => 'runuser',
                // Only frameworks that ship a storage skeleton have one; a static site or a Go
                // service has none, and copying a missing directory fails the deploy.
                'args' => ['-u', $user, '--', 'sh', '-c',
                    'test -d "$1" || exit 0; cp -r --update=none "$1" "$2"', 'seed', "{$path}/storage", "{$shared}/"],
                'description' => 'shared storage has the framework skeleton',
            ],
            [
                // Repositories often lack the placeholder files that keep
                // these in git, and artisan optimize fails without them. The
                // first real deploy found exactly that.
                'id' => 'storage-dirs',
                'type' => 'command',
                'name' => 'runuser',
                'args' => ['-u', $user, '--', 'mkdir', '-p',
                    "{$shared}/storage/framework/cache/data",
                    "{$shared}/storage/framework/sessions",
                    "{$shared}/storage/framework/views",
                    "{$shared}/storage/logs",
                    "{$shared}/storage/app/public",
                ],
                'unless' => ['test', '-d', "{$shared}/storage/framework/views"],
                'description' => 'shared storage has the framework directories',
            ],
            [
                'id' => 'sqlite',
                'type' => 'command',
                'name' => 'runuser',
                'args' => ['-u', $user, '--', 'touch', "{$shared}/database.sqlite"],
                'unless' => ['test', '-f', "{$shared}/database.sqlite"],
                'description' => 'shared SQLite database exists',
            ],
        ]];
    }

    /**
     * A public repository: no key, no token, nothing to leave behind.
     * Prompts are off so a repository that has since gone private fails at once.
     *
     * @return array<string, mixed>
     */
    private function cloneAnonymously(stdClass $site, string $path): array
    {
        return [
            'id' => 'clone',
            'type' => 'command',
            'name' => 'runuser',
            'args' => ['-u', ServerStack::DEPLOY_USER, '--', 'env', 'GIT_TERMINAL_PROMPT=0', 'git', '-c', 'credential.helper=',
                'clone', '--depth', '1', '--branch', $site->branch, '--', $site->repository, $path],
            'unless' => ['test', '-d', "{$path}/.git"],
            'description' => "clone {$site->repository} {$site->branch}",
        ];
    }

    /** @return array<string, mixed> */
    private function cloneWithDeployKey(stdClass $site, string $path): array
    {
        $user = ServerStack::DEPLOY_USER;

        // Only this site's key, only GitHub's pinned host key. IdentitiesOnly
        // stops ssh offering any other key the deploy user might hold.
        $ssh = sprintf(
            'ssh -i %s -o IdentitiesOnly=yes -o UserKnownHostsFile=/home/%s/.ssh/known_hosts_git -o StrictHostKeyChecking=yes',
            Sites::keyPath($site),
            $user,
        );

        return [
            'id' => 'clone',
            'type' => 'command',
            'name' => 'runuser',
            // "--" ends git's options, so nothing in the repository or
            // branch can be read as one.
            'args' => ['-u', $user, '--', 'git', '-c', "core.sshCommand={$ssh}",
                'clone', '--depth', '1', '--branch', $site->branch, '--', $site->repository, $path],
            'unless' => ['test', '-d', "{$path}/.git"],
            'description' => "clone {$site->repository} {$site->branch}",
        ];
    }

    /**
     * Clones over HTTPS with an installation token. The token reaches git
     * through a credential helper reading a file only the deploy user can
     * open, so it is never in a process's arguments or in the release's
     * .git/config, and the file is gone as soon as the clone finishes.
     *
     * @return list<array<string, mixed>>
     */
    private function cloneWithToken(stdClass $site, string $path, #[\SensitiveParameter] string $token): array
    {
        $user = ServerStack::DEPLOY_USER;
        $file = self::tokenPath($site);
        $url = 'https://github.com/'.GitHubApp::fullName($site->repository).'.git';
        $helper = "!f() { test \"\$1\" = get || exit 0; echo username=x-access-token; echo \"password=\$(cat {$file})\"; }; f";

        return [
            // Owned and confined to the deploy home: root never writes or chowns this by path.
            ['id' => 'token', 'type' => 'file', 'path' => $file, 'content_b64' => base64_encode($token), 'mode' => '0600', 'sensitive' => true, 'owner' => $user, 'base' => "/home/{$user}"],
            [
                'id' => 'clone',
                'type' => 'command',
                'name' => 'runuser',
                'args' => ['-u', $user, '--', 'git', '-c', "credential.helper={$helper}",
                    'clone', '--depth', '1', '--branch', $site->branch, '--', $url, $path],
                'unless' => ['test', '-d', "{$path}/.git"],
                'description' => "clone {$url} {$site->branch}",
            ],
            ['id' => 'forget-token', 'type' => 'absent', 'path' => $file, 'owner' => $user, 'base' => "/home/{$user}"],
        ];
    }

    public static function tokenPath(stdClass $site): string
    {
        return '/home/'.ServerStack::DEPLOY_USER."/.cm-git-token-{$site->name}";
    }

    /**
     * @return array{name: string, steps: list<array<string, mixed>>}
     */
    /**
     * The arguments of the agent's prepare step. An application in a repository
     * folder links .env and storage from inside that folder to the same
     * shared/ files as ever, which the agent can do only from the `link` field.
     *
     * @return array<string, mixed>
     */
    private function prepareArgs(stdClass $site, string $releaseId): array
    {
        $args = ['site' => $site->name, 'release_id' => $releaseId];
        $dir = Sites::rootDir($site);
        if ($dir !== '') {
            $args['shared'] = [
                ['rel' => "{$dir}/.env", 'link' => '.env', 'kind' => 'file'],
                ['rel' => "{$dir}/storage", 'link' => 'storage', 'kind' => 'dir'],
            ];
        }

        return $args;
    }

    /**
     * An agent from before the root folder existed would link shared/apps/web/.env,
     * an empty file, and the application would start with no configuration. So say so first.
     *
     * @param  list<string>  $nodes
     */
    private function assertAgentsLinkRoot(stdClass $site, array $nodes): void
    {
        if (Sites::rootDir($site) === '') {
            return;
        }
        $serving = (string) config('control_plane.agent_version');
        foreach ($nodes as $node) {
            $agent = DB::table('nodes')->where('node_id', $node)->value('agent_version');
            if ($agent !== null && $agent !== $serving) {
                throw new RuntimeException("{$node} runs an older agent that cannot link shared files inside a root folder. Upgrade the agent on the Servers page, then deploy again.");
            }
        }
    }

    public function buildRecipe(string $path, bool $migrate = true, ?string $seeder = null, ?string $php = null, ?string $composerHome = null, ?stdClass $scriptOf = null): array
    {
        $user = ServerStack::DEPLOY_USER;
        $binary = ServerStack::phpBinary($php ?? ServerStack::PHP_VERSION);
        $as = static fn (string ...$args): array => ['-u', $user, '--', ...$args];
        $composerEnv = $composerHome === null ? [] : ['env', "COMPOSER_HOME={$composerHome}"];

        $steps = [
            [
                'id' => 'composer',
                'type' => 'command',
                'name' => 'runuser',
                // With credentials for private packages, Composer reads them from its own home folder; the secret is in that file, not here.
                'args' => $as(...[...$composerEnv, $binary, ServerStack::COMPOSER, 'install', "--working-dir={$path}", '--no-dev', '--no-interaction',
                    '--prefer-dist', '--optimize-autoloader', '--no-progress']),
                'description' => 'composer install',
            ],
            [
                'id' => 'migrate',
                'type' => 'command',
                'name' => 'runuser',
                'args' => $as($binary, "{$path}/artisan", 'migrate', '--force'),
                'description' => 'artisan migrate',
            ],
            [
                'id' => 'optimize',
                'type' => 'command',
                'name' => 'runuser',
                'args' => $as($binary, "{$path}/artisan", 'optimize'),
                'description' => 'artisan optimize',
            ],
        ];

        // The application's own build commands replace the Composer step (the default commands are that step), and run before
        // anything reads or caches the app, so what they make, such as the Vite manifest, is there.
        if ($scriptOf !== null && ($build = Sites::buildScript($scriptOf)) !== null) {
            $steps = array_values(array_filter($steps, fn ($step) => $step['id'] !== 'composer'));
            array_splice($steps, 0, 0, Runtimes::buildScriptSteps($scriptOf, $path, $build['script'], $build['node'], $binary, $composerHome));
        }

        // Migrations run on one server only: the primary.
        if (! $migrate) {
            $steps = array_values(array_filter($steps, fn ($step) => $step['id'] !== 'migrate'));
        } elseif ($seeder !== null) {
            // A preview's first deploy fills its fresh database.
            array_splice($steps, 2, 0, [[
                'id' => 'seed',
                'type' => 'command',
                'name' => 'runuser',
                'args' => $as($binary, "{$path}/artisan", 'db:seed', "--class={$seeder}", '--force'),
                'description' => "artisan db:seed --class={$seeder}",
            ]]);
        }

        return ['name' => 'build', 'steps' => $steps];
    }

    /** @param array<string, mixed> $spec */
    /**
     * Where a release's build goes in the site's bucket, and URLs to put and
     * get it that last half an hour, signed with the site's own key. The
     * agent fetches them with curl and needs no credentials of its own.
     *
     * @return array{endpoint: string, bucket: string, object: string, key: string, secret: string, put: string, get: string}
     */
    private function artifact(stdClass $site, string $releaseId): array
    {
        $env = [];
        foreach (preg_split('/\R/', $this->sites->env($site)) as $line) {
            if (preg_match('/^(AWS_[A-Z_]+)=(.*)$/', $line, $m)) {
                $env[$m[1]] = trim($m[2], '"\'');
            }
        }
        foreach (['AWS_ENDPOINT', 'AWS_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'] as $k) {
            if (($env[$k] ?? '') === '') {
                throw new RuntimeException("{$site->name} has a bucket but its .env has no {$k}, so the build cannot be shared. Re-attach Spaces or remove the bucket.");
            }
        }
        $a = ['endpoint' => $env['AWS_ENDPOINT'], 'bucket' => $env['AWS_BUCKET'], 'object' => ".cloud-manager/releases/{$releaseId}.tar.gz",
            'key' => $env['AWS_ACCESS_KEY_ID'], 'secret' => $env['AWS_SECRET_ACCESS_KEY']];
        $a['put'] = Spaces::presign('PUT', $a['endpoint'], $a['bucket'], $a['object'], $a['key'], $a['secret'], 1800);
        $a['get'] = Spaces::presign('GET', $a['endpoint'], $a['bucket'], $a['object'], $a['key'], $a['secret'], 1800);

        return $a;
    }

    /**
     * Packs the primary's build into the bucket and unpacks it on every
     * other server, which then links and caches it exactly as a build there
     * would have. The packed build leaves out .git and the cached config:
     * that cache holds the .env's secrets, and is rebuilt on each server.
     *
     * @param  list<string>  $nodes
     * @param  array<string, string>  $artifact
     */
    private function ship(stdClass $site, array $nodes, string $path, string $releaseId, array $artifact): void
    {
        $as = static fn (string ...$args): array => ['-u', ServerStack::DEPLOY_USER, '--', ...$args];
        // Spaces needs the length up front, so the archive is written to a
        // file before it is sent rather than streamed.
        $pack = 'set -eu; f=$(mktemp); trap \'rm -f "$f"\' EXIT; '
            .'tar -czf "$f" -C "$1" --exclude=./.git --exclude=./bootstrap/cache/config.php .; '
            .'curl -fsS --retry 3 -T "$f" "$2"; du -h "$f" | cut -f1';
        $this->recipe($this->at($site, $site->node_id), ['name' => 'pack', 'steps' => [
            ['id' => 'pack', 'type' => 'command', 'name' => 'runuser', 'args' => $as('sh', '-c', $pack, 'sh', $path, $artifact['put']),
                'description' => 'build packed into the bucket'],
        ]], self::BUILD_TIMEOUT);

        foreach (array_slice($nodes, 1) as $node) {
            $this->unpack($this->at($site, $node), $path, $releaseId, $artifact['get']);
        }
    }

    /** One server takes a packed build: unpacked, linked and cached as if built there. */
    private function unpack(stdClass $on, string $path, string $releaseId, string $get): void
    {
        $as = static fn (string ...$args): array => ['-u', ServerStack::DEPLOY_USER, '--', ...$args];
        $this->recipe($on, ['name' => 'unpack', 'steps' => [
            ['id' => 'release-dir', 'type' => 'command', 'name' => 'runuser', 'args' => $as('mkdir', '-p', $path), 'description' => "release {$releaseId}"],
            ['id' => 'unpack', 'type' => 'command', 'name' => 'runuser',
                'args' => $as('sh', '-c', 'set -eu; curl -fsS --retry 3 "$1" | tar -xzf - -C "$2"', 'sh', $get, $path),
                'description' => 'the primary\'s build, unpacked'],
            ['id' => 'storage-dirs', 'type' => 'command', 'name' => 'runuser',
                'args' => $as('mkdir', '-p', ...array_map(fn ($d) => Sites::root($on)."/shared/storage/{$d}", ['framework/cache/data', 'framework/sessions', 'framework/views', 'logs', 'app/public'])),
                'description' => 'shared storage has the framework directories'],
        ]], self::BUILD_TIMEOUT);
        $this->command($on, 'deploy', $this->prepareArgs($on, $releaseId));
        $binary = ServerStack::phpBinary(Sites::phpVersion($on));
        $this->recipe($on, ['name' => 'cache', 'steps' => [
            ['id' => 'optimize', 'type' => 'command', 'name' => 'runuser', 'args' => $as($binary, Sites::appDir($on, $path).'/artisan', 'optimize'), 'description' => 'artisan optimize'],
        ]], self::BUILD_TIMEOUT);
    }

    /**
     * Brings a server the autoscale pool added onto the site's live release:
     * directories, secrets and nginx, the current release's packed build,
     * the switch, and workers. No build, no migrations, no scheduler. The
     * balancer and firewalls are the caller's, once this has succeeded.
     */
    public function join(stdClass $site, string $nodeId, ?Closure $log = null): void
    {
        $this->log = $log;
        $this->multi = true;
        $release = (string) ($site->current_release ?? throw new RuntimeException("{$site->name} has never been deployed, so there is nothing to join."));
        $on = $this->at($site, $nodeId);
        try {
            $this->recipe($on, $this->sites->setupRecipe($on));
            $this->ensureServerScript($on);
            $path = Sites::root($site)."/releases/{$release}";
            $artifact = $this->artifact($site, $release);
            // A release deployed before the site autoscaled, or rolled back
            // to from then, was never kept in the bucket: the primary packs
            // its copy, which is the build that is live.
            if (! Http::withHeaders(['Range' => 'bytes=0-0'])->timeout(20)->get($artifact['get'])->successful()) {
                $this->node = $site->node_id;
                $this->ship($site, [$site->node_id], $path, $release, $artifact);
                $this->node = $nodeId;
            }
            $this->unpack($on, $path, $release, $artifact['get']);
            $this->command($on, 'activate_release', ['site' => $site->name, 'release_id' => $release]);
            $this->say('switch', "current → {$release}");
            $this->afterSwitch($on, null, scheduler: false);
        } finally {
            $this->node = null;
        }
    }

    /** The packed build is only needed until every server has it. */
    private function forget(array $artifact): void
    {
        try {
            $path = "/{$artifact['bucket']}/{$artifact['object']}";
            Http::withHeaders(Spaces::sign('DELETE', $artifact['endpoint'], $path, $artifact['key'], $artifact['secret']))
                ->timeout(20)->delete($artifact['endpoint'].$path);
        } catch (Throwable) {
            // Left behind, it costs a few megabytes and the next deploy is unaffected.
        }
    }

    /** Rewrites the site's directories, secrets and nginx on one server. */
    public function setup(stdClass $site): void
    {
        $this->recipe($site, $this->sites->setupRecipe($site));
    }

    /** Stops a server serving the site, once it is no longer one of the site's servers. */
    public function unserve(stdClass $site, string $nodeId, bool $keepData = false): void
    {
        $on = Sites::on($site, $nodeId);
        $this->recipe($on, $this->sites->removalRecipe($on, $keepData));
    }

    /**
     * Puts the site's variables on the running application without a deploy: the shared .env is rewritten, the config
     * cache is rebuilt from it (a cached config never reads .env), PHP-FPM is reloaded and the queue workers restart.
     * Nothing is cloned, installed or migrated.
     *
     * @throws \InvalidArgumentException when this application's variables cannot be applied that way
     */
    public function applyVariables(stdClass $site, ?Closure $log = null): void
    {
        if (! Runtimes::has($site, 'env-file')) {
            throw new \InvalidArgumentException('Only a PHP application reads its variables from a .env file; this one needs a deploy.');
        }
        if ($site->current_release === null) {
            throw new \InvalidArgumentException('Nothing is live yet; the first deploy puts the variables on the server.');
        }
        $this->log = $log;
        foreach (Sites::nodes($site) as $node) {
            $on = Sites::on($site, $node);
            $this->recipe($on, $this->variablesRecipe($on));
            $this->say('variables', 'applied on '.(strstr($node, '.', true) ?: $node));
        }
    }

    /** @return array{name: string, steps: list<array<string, mixed>>} */
    public function variablesRecipe(stdClass $site): array
    {
        $user = ServerStack::DEPLOY_USER;
        $php = Sites::phpVersion($site);
        $artisan = fn (string ...$args): array => ['-u', $user, '--', ServerStack::phpBinary($php), Sites::current($site).'/artisan', ...$args];
        // The restart signal goes through the app's cache. It is sent once through the old cached config, which still names the old
        // store, and once through the new one, so workers restart whichever store they were watching. A cache that cannot take it
        // (a database cache with no table) must not fail the rest.
        $restart = fn (string $id, string $what): array => ['id' => $id, 'type' => 'command', 'name' => 'sh',
            'args' => ['-c', 'runuser '.implode(' ', array_map('escapeshellarg', $artisan('queue:restart'))).' || true'], 'description' => $what];

        return ['name' => "variables-{$site->name}", 'steps' => [
            $this->sites->envStep($site),
            $restart('queue-restart-before', 'workers are told to restart (through the old cache setting)'),
            ['id' => 'config-cache', 'type' => 'command', 'name' => 'runuser', 'args' => $artisan('config:cache'), 'description' => 'artisan config:cache'],
            ['id' => 'reload-php-fpm', 'type' => 'service', 'name' => "php{$php}-fpm", 'state' => 'reloaded'],
            $restart('queue-restart', 'workers are told to restart (through the new one)'),
        ]];
    }

    /**
     * Writes the site's web server settings to every server it runs on, each
     * checked by nginx before it reloads. A server whose check fails keeps its
     * old config and the failure stops the rest.
     */
    public function applyWebServer(stdClass $site, ?Closure $log = null): void
    {
        $this->log = $log;
        foreach (Sites::nodes($site) as $node) {
            $on = Sites::on($site, $node);
            $this->recipe($on, $this->sites->vhostRecipe($on));
            $this->say('nginx', 'settings applied and nginx reloaded on '.(strstr($node, '.', true) ?: $node));
        }
    }

    /**
     * The site's cron file, rebuilt whole: `schedule:run` (on the primary,
     * when its switch is on) and each job that is not paused (on the primary,
     * or on every server if it says so). Absent when that is nothing, and from
     * a server that has none of it, or each task would run once per server.
     * Each job runs under flock -n, so a slow run is not started on top of itself.
     *
     * @return array<string, mixed>
     */
    public function schedulerStep(stdClass $site, bool $primary = true): array
    {
        $path = "/etc/cron.d/cm-{$site->name}";
        $current = Sites::current($site);
        $binary = ServerStack::phpBinary(Sites::phpVersion($site));
        $user = ServerStack::DEPLOY_USER;

        // After a successful run, a monitored one pings its URL.
        $pings = Heartbeats::urls((int) $site->id);
        $ping = fn (int $id) => isset($pings[$id]) ? ' && curl -fsS -m 10 --retry 2 -o /dev/null '.escapeshellarg($pings[$id]) : '';

        $lines = [];
        if ($primary && (bool) ($site->scheduler ?? true)) {
            $lines[] = "* * * * * {$user} cd {$current} && {$binary} artisan schedule:run >> /dev/null 2>&1".$ping(0);
        }
        foreach (Sites::scheduledJobs($site) as $job) {
            if ((bool) $job['paused'] || ! ($primary || (bool) $job['everywhere'])) {
                continue;
            }
            $command = str_replace('{php}', $binary, (string) $job['command']);
            // In a crontab a bare % ends the command, so it is escaped.
            $line = "{$job['cron']} {$user} cd {$current} && flock -n /tmp/cm-job-{$job['id']}.lock -c ".escapeshellarg($command).' >> '.self::jobLog($site, (int) $job['id']).' 2>&1'.$ping((int) $job['id']);
            $lines[] = '# '.preg_replace('/[^\w .-]/', '', (string) $job['name'])."\n".str_replace('%', '\\%', $line);
        }
        if ($lines === []) {
            return ['id' => 'scheduler', 'type' => 'absent', 'path' => $path];
        }

        // Files in /etc/cron.d are ignored if their name contains a dot, and must end in a newline.
        return ['id' => 'scheduler', 'type' => 'file', 'path' => $path, 'content_b64' => base64_encode("# Managed by cloud-manager.\n".implode("\n", $lines)."\n"), 'mode' => '0644'];
    }

    public static function jobLog(stdClass $site, int $job): string
    {
        return Sites::root($site)."/shared/storage/logs/cron-{$job}.log";
    }

    /** Runs the setup script on a server that has never run it: a server joining the application. */
    public function ensureServerScript(stdClass $on): void
    {
        if (Sites::serverScript($on)['content'] !== '' && ! DB::table('site_script_runs')->where('site_id', $on->id)->where('node_id', $on->node_id)->exists()) {
            $this->runServerScript($on);
        }
    }

    /**
     * Runs the application's setup script as root on one server, through the
     * agent, from a file removed afterwards. A non-zero exit fails the step,
     * and so the join. The run is recorded only if it succeeded.
     */
    public function runServerScript(stdClass $on, ?Closure $log = null): void
    {
        $script = Sites::serverScript($on);
        if ($script['content'] === '') {
            return;
        }
        $this->log = $log ?? $this->log;
        $path = "/root/.cm-setup-{$on->name}.sh";
        try {
            $this->recipe($on, ['name' => "server-script-{$on->name}", 'steps' => [
                ['id' => 'script', 'type' => 'file', 'path' => $path, 'mode' => '0700', 'content_b64' => base64_encode($script['content'])],
                ['id' => 'run', 'type' => 'command', 'name' => 'bash', 'args' => [$path], 'description' => 'server setup script, version '.$script['version']],
            ]], self::SCRIPT_TIMEOUT);
        } finally {
            try {
                $this->recipe($on, ['name' => 'forget-server-script', 'steps' => [['id' => 'forget', 'type' => 'absent', 'path' => $path]]]);
            } catch (Throwable) {
            }
        }
        DB::table('site_script_runs')->updateOrInsert(['site_id' => $on->id, 'node_id' => $on->node_id], ['version' => $script['version'], 'ran_at' => now()]);
    }

    /** Runs the setup script on every server the application is on, stopping at the first that fails. */
    public function applyServerScript(stdClass $site, ?Closure $log = null): void
    {
        $this->log = $log;
        foreach (Sites::nodes($site) as $node) {
            $this->runServerScript($this->at($site, $node), $log);
        }
        $this->node = null;
    }

    /**
     * Writes the cron file on every server the site runs on, without a deploy,
     * and marks each job installed, or failed with the reason.
     */
    public function applyScheduler(stdClass $site, ?Closure $log = null): void
    {
        $this->log = $log;
        try {
            foreach (Sites::nodes($site) as $node) {
                $on = $this->at($site, $node);
                $this->recipe($on, ['name' => "scheduler-{$site->name}", 'steps' => [$this->schedulerStep($on, $node === $site->node_id)]]);
            }
        } catch (Throwable $e) {
            DB::table('scheduled_jobs')->where('site_id', $site->id)->where('state', 'pending')->update(['state' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            throw $e;
        }
        DB::table('scheduled_jobs')->where('site_id', $site->id)->where('state', '!=', 'installed')->update(['state' => 'installed', 'error' => null]);
        $this->say('scheduler', 'cron file written: schedule:run '.((bool) $site->scheduler ? 'on' : 'off').', '.DB::table('scheduled_jobs')->where('site_id', $site->id)->where('paused', false)->count().' job(s) active');
    }

    /** The tail of a job's log on the primary, or a note that it has not run. */
    public function jobOutput(stdClass $site, int $job): string
    {
        try {
            $out = (string) ($this->command($site, 'exec', ['name' => 'tail', 'args' => ['-n', '100', self::jobLog($site, $job)]])['detail']['output'] ?? '');
        } catch (RuntimeException) {
            return '';
        }

        return mb_substr($out, -6000);
    }

    /** Runs a job's command once as the deploy user, from the live release, and returns what it printed. */
    public function runJob(stdClass $site, string $command): string
    {
        $binary = ServerStack::phpBinary(Sites::phpVersion($site));
        try {
            $result = $this->command($site, 'exec', ['name' => 'runuser', 'args' => ['-u', ServerStack::DEPLOY_USER, '--', 'sh', '-c', 'cd '.Sites::current($site).' && '.str_replace('{php}', $binary, $command)]], 120);
        } catch (RuntimeException $e) {
            return $e->getMessage();
        }

        return mb_substr((string) ($result['detail']['output'] ?? ''), -6000);
    }

    /**
     * Asks the live release which tasks its code schedules and keeps the answer.
     *
     * @return list<array{command: string, expression: string, description: string, next: string}>
     */
    public function readSchedule(stdClass $site): array
    {
        $artisan = ['-u', ServerStack::DEPLOY_USER, '--', ServerStack::phpBinary(Sites::phpVersion($site)), Sites::current($site).'/artisan', 'schedule:list'];
        try {
            $result = $this->command($site, 'exec', ['name' => 'runuser', 'args' => [...$artisan, '--json']], 60);
        } catch (RuntimeException) {
            // Older Laravel has no --json: read the table it prints.
            $result = $this->command($site, 'exec', ['name' => 'runuser', 'args' => $artisan], 60);
        }
        $output = (string) ($result['detail']['output'] ?? '');
        $tasks = Sites::parseSchedule($output);
        DB::table('sites')->where('id', $site->id)->update(['schedule_tasks' => json_encode($tasks), 'schedule_tasks_at' => now()]);

        return $tasks;
    }

    // ---- applications from a Dockerfile or a Docker image (docs/specs/templates.md, step 6) ----------------------------------

    /**
     * Deploys a container application: the commit is checked out as usual, the image is built on the server (or, for an image
     * application, pulled and pinned by digest), and a new container starts on a free host port beside the old one. Only when it
     * passes its health check does nginx switch to it; then the old container is removed. A failure anywhere before the switch
     * removes the new container and leaves the old one serving, which is what "unchanged" means here.
     *
     * Stages: setup, fetch, build, start, health, switch, prune (an image application: setup, pull, start, health, switch, prune).
     *
     * @return array{release: string, commit: string}
     */
    private function deployContainer(stdClass $site, ?Closure $progress, ?string $now, ?Closure $log): array
    {
        $progress ??= static function (string $stage): void {};
        $this->log = $log;
        $this->multi = false;
        $startedAt = now();
        $state = ContainerApps::state($site);
        $image = $state['kind'] === Runtimes::IMAGE;
        $releaseId = self::releaseId($now ?? gmdate('Ymd\THis\Z'), $image ? 'image' : $site->branch);
        $path = Sites::root($site)."/releases/{$releaseId}";
        $resource = "{$site->node_id}/{$site->name}";
        $on = $this->at($site, $site->node_id);
        $name = $site->name;
        $started = null;
        $built = null;

        try {
            $progress('setup');
            $this->assertContainersReady($site);
            $this->recipe($on, $this->sites->setupRecipe($on));
            $this->recipe($on, AppRecipes::check($name));
            $env = ContainerApps::environment($this->sites->env($site));

            if ($image) {
                $progress('pull');
                $built = $this->pinnedImage($site, $on, $state);
            } else {
                $progress('fetch');
                $this->say('release', "{$releaseId} from {$site->branch}");
                $this->cloneRelease($on, $path, $this->sourceToken($site), storage: false);
                $commit = trim((string) ($this->command($on, 'exec', ['name' => 'runuser', 'args' => ['-u', ServerStack::DEPLOY_USER, '--', 'git', '-C', $path, 'rev-parse', 'HEAD']])['detail']['output'] ?? ''));
                $this->say('commit', $commit);
                $this->recipe($on, AppRecipes::verifySource($name, $path, $state['dockerfile'], $state['context']));

                $progress('build');
                $this->recipe($on, AppRecipes::build($name, $path, $state['dockerfile'], $state['context'], $commit), 1800);
                $tag = AppRecipes::tag($name, $commit);
                $built = ['ref' => $tag, 'id' => trim($this->execOutput($on, 'docker', AppRecipes::idArguments($tag))), 'commit' => $commit, 'digest' => null];
            }

            $progress('start');
            $started = $this->startBeside($site, $on, $built['ref'], $env, $progress);
        } catch (Throwable $e) {
            $this->abandon($site, $on, $started);
            if ($image && ($state['pending_digest'] ?? null) !== null) {
                ContainerApps::merge((int) $site->id, ['pending_digest' => null]);
            }
            $this->node = null;
            $this->audit->record($site->organization_id, $this->actor(), 'site.deploy_failed', outcome: Outcome::Failed, resourceType: 'site', resourceId: $resource,
                metadata: ['release' => $releaseId, 'servers' => [$site->node_id], 'error' => mb_substr($e->getMessage(), 0, 500)]);

            throw new RuntimeException("Deploy of {$releaseId} failed; the live release is unchanged. ".$e->getMessage(), previous: $e);
        }

        $progress('switch');
        $entry = ['image' => $built['ref'], 'id' => $built['id'], 'commit' => $built['commit'], 'digest' => $built['digest'], 'release' => $releaseId, 'at' => now()->toIso8601String()];
        $this->cutOver($site, $on, $started, $entry, $image ? ['digest' => $built['digest'], 'pending_digest' => null, 'candidate' => null] : []);
        DB::table('sites')->where('id', $site->id)->update(['current_release' => $releaseId, 'updated_at' => now()]);
        DB::table('sites')->where('id', $site->id)->where('pending_since', '<=', $startedAt)->update(['pending_since' => null]);
        $this->audit->record($site->organization_id, $this->actor(), 'site.deployed', resourceType: 'site', resourceId: $resource,
            metadata: ['release' => $releaseId, 'commit' => $built['commit'], 'kind' => $state['kind'], 'digest' => $built['digest'], 'servers' => [$site->node_id]]);

        $progress('prune');
        $this->pruneImages($site, $on);
        $this->node = null;

        return ['release' => $releaseId, 'commit' => $built['commit']];
    }

    /**
     * The image an image application runs: the pinned digest, or on the first deploy whatever the tag is now, which is then pinned.
     * A confirmed update runs the digest the person was shown. The image is pulled only when it is not already on the server.
     *
     * @param  array<string, mixed>  $state
     * @return array{ref: string, id: string, commit: string, digest: string}
     */
    private function pinnedImage(stdClass $site, stdClass $on, array $state): array
    {
        $repo = AppRecipes::repository((string) $state['image_ref']);
        $target = $state['pending_digest'] ?? $state['digest'];
        if (is_string($target)) {
            $ref = "{$repo}@{$target}";
            try {
                $id = trim($this->execOutput($on, 'docker', AppRecipes::idArguments($ref)));
                $this->say('image', "{$ref} is already on the server");
            } catch (Throwable) {
                $id = '';
            }
            if ($id === '') {
                $found = $this->pullImage($site, $on, $ref);
                if ($found['digest'] !== $target) {
                    throw new RuntimeException("The registry answered {$found['digest']} for the pinned digest {$target}. Nothing was started.");
                }
                $id = $found['id'];
            }
        } else {
            $found = $this->pullImage($site, $on, (string) $state['image_ref']);
            $target = $found['digest'];
            $ref = "{$repo}@{$target}";
            $id = $found['id'];
            // The first pull pins the digest: from here the application runs this image, whatever the tag later points at.
            ContainerApps::merge((int) $site->id, ['digest' => $target]);
            $this->say('digest', "pinned {$target}");
        }

        return ['ref' => $ref, 'id' => $id, 'commit' => substr($target, 7, 12), 'digest' => $target];
    }

    /** @return array{digest: string, id: string} */
    private function pullImage(stdClass $site, stdClass $on, string $ref): array
    {
        $found = ContainerApps::pull($site, $ref, fn (array $spec, ?int $timeout) => $this->recipe($on, $spec, $timeout), fn (string $n, array $a) => $this->execOutput($on, $n, $a));
        $this->say('pull', "{$ref} is {$found['digest']}");

        return $found;
    }

    /**
     * A new container beside the live one: a free host port is reserved (recorded as pending on the site, in the same band and under
     * the same lock as services' ports), the environment file and volumes are made, the container started, and its health check passed.
     *
     * @param  array<string, string>  $env
     * @return array{port: int, container: string}
     */
    private function startBeside(stdClass $site, stdClass $on, string $image, array $env, Closure $progress): array
    {
        $state = ContainerApps::state($site);
        $port = app(\App\Lib\Services\Services::class)->reserveHostPort($site->node_id, fn (int $p) => ContainerApps::merge((int) $site->id, ['pending_port' => $p]));
        $started = ['port' => $port, 'container' => AppRecipes::container($site->name, $port)];
        $c = [
            'name' => $site->name, 'container' => $started['container'], 'image' => $image, 'env_file' => AppRecipes::envPath($site->name), 'env' => $env,
            'memory' => (string) Definition::parseSize((string) $state['memory']), 'read_only' => (bool) $state['read_only'],
            'volumes' => array_map(fn ($path, $i) => ['name' => "cm-app-{$site->name}-data{$i}", 'path' => $path], $state['volumes'], array_keys($state['volumes'])),
            'host_port' => $port, 'container_port' => (int) $state['container_port'],
        ];
        $this->recipe($on, AppRecipes::prepare($c));
        $this->recipe($on, AppRecipes::run($c));
        $this->say('start', "{$started['container']} on 127.0.0.1:{$port}, beside the live container");
        $progress('health');
        $this->recipe($on, AppRecipes::health($c, $state['health'] === '' ? [] : ['path' => $state['health']]), 180);
        $this->say('health', 'answers');

        return $started;
    }

    /** Takes a new container that never went live away again, and gives its port back. Best effort: the old one is still serving. */
    private function abandon(stdClass $site, stdClass $on, ?array $started): void
    {
        $port = $started['port'] ?? ContainerApps::state(DB::table('sites')->where('id', $site->id)->first())['pending_port'];
        if ($port === null) {
            return;
        }
        try {
            $this->recipe($on, AppRecipes::discard($site->name, AppRecipes::container($site->name, (int) $port)));
        } catch (Throwable $e) {
            $this->say('skip', 'the new container could not be removed: '.$e->getMessage());
        }
        ContainerApps::merge((int) $site->id, ['pending_port' => null]);
        $this->say('undo', 'the new container was removed; the live one keeps serving');
    }

    /**
     * Points nginx at the new container and, once nginx has reloaded, removes the old one. The site's state names the new port
     * before the vhost is written (it is built from the state); if nginx refuses the config the state is put back, the new container
     * removed and the old one is still serving.
     *
     * @param  array{port: int, container: string}  $started
     * @param  array<string, mixed>  $entry  the image now live: image, id, commit, release, at
     * @param  array<string, mixed>  $also  other state to write with the switch
     */
    private function cutOver(stdClass $site, stdClass $on, array $started, array $entry, array $also = []): void
    {
        $before = ContainerApps::state(DB::table('sites')->where('id', $site->id)->first());
        $old = $before['host_port'];
        ContainerApps::merge((int) $site->id, ['host_port' => $started['port'], 'pending_port' => null]);
        try {
            $fresh = Sites::on(DB::table('sites')->where('id', $site->id)->first(), $site->node_id);
            $this->recipe($fresh, $this->sites->vhostRecipe($fresh));
            $this->say('nginx', "upstream is now 127.0.0.1:{$started['port']}; nginx reloaded");
        } catch (Throwable $e) {
            ContainerApps::merge((int) $site->id, ['host_port' => $old, 'pending_port' => $started['port']]);
            $this->abandon($site, $on, $started);

            throw new RuntimeException("nginx did not accept the switch: {$e->getMessage()}", previous: $e);
        }
        $images = array_values(array_filter((array) $before['images'], fn ($i) => ($i['image'] ?? null) !== $entry['image'] || ($i['id'] ?? null) !== $entry['id']));
        ContainerApps::merge((int) $site->id, ['images' => [$entry, ...$images]] + $also);
        if ($old !== null) {
            try {
                $this->recipe($on, AppRecipes::discard($site->name, AppRecipes::container($site->name, (int) $old)));
                $this->say('retire', 'the old container is stopped and removed');
            } catch (Throwable $e) {
                $this->say('skip', 'the old container could not be removed: '.$e->getMessage());
            }
        }
    }

    /** Keeps the live image and the two before it; older ones are removed by id, best effort. */
    private function pruneImages(stdClass $site, stdClass $on): void
    {
        $state = ContainerApps::state(DB::table('sites')->where('id', $site->id)->first());
        $keep = array_slice((array) $state['images'], 0, 1 + ContainerApps::KEEP_PREVIOUS);
        $drop = array_slice((array) $state['images'], 1 + ContainerApps::KEEP_PREVIOUS);
        $keepIds = array_column($keep, 'id');
        $ids = array_values(array_filter(array_unique(array_column($drop, 'id')), fn ($id) => $id !== '' && ! in_array($id, $keepIds, true)));
        ContainerApps::merge((int) $site->id, ['images' => $keep]);
        if ($ids === []) {
            $this->say('prune', 'no old images to remove');

            return;
        }
        try {
            $this->recipe($on, AppRecipes::prune($site->name, $ids));
            $this->say('prune', count($ids).' old image(s) removed');
        } catch (Throwable $e) {
            $this->say('skip', 'prune: '.$e->getMessage());
        }
    }

    /**
     * Rollback: the previous image is started on a new port, checked, and nginx switched back to it; the container that was live is
     * removed. The two images swap places, so a second rollback goes forward again.
     */
    private function rollbackContainer(stdClass $site, ?Closure $log): string
    {
        $this->log = $log;
        $this->multi = false;
        $on = $this->at($site, $site->node_id);
        $state = ContainerApps::state($site);
        $target = $state['images'][1] ?? null;
        if ($target === null) {
            throw new RuntimeException("{$site->name} has no previous version kept on the server to go back to. Deploy again.");
        }
        $started = null;
        try {
            $this->assertContainersReady($site);
            try {
                $this->execOutput($on, 'docker', AppRecipes::idArguments((string) $target['image']));
            } catch (Throwable) {
                throw new RuntimeException("The previous image {$target['image']} is no longer on the server, so there is nothing to go back to. Deploy again.");
            }
            $env = ContainerApps::environment($this->sites->env($site));
            $started = $this->startBeside($site, $on, (string) $target['image'], $env, static function (string $stage): void {});
        } catch (Throwable $e) {
            $this->abandon($site, $on, $started);
            $this->node = null;

            throw new RuntimeException("Rollback failed; the live release is unchanged. ".$e->getMessage(), previous: $e);
        }
        $images = (array) $state['images'];
        // cutOver puts the target first and the version rolled back from after it; an image application's pin follows what runs.
        $this->cutOver($site, $on, $started, $target, $state['kind'] === Runtimes::IMAGE && isset($target['digest']) ? ['digest' => $target['digest'], 'pending_digest' => null] : []);
        $release = (string) ($target['release'] ?? '');
        DB::table('sites')->where('id', $site->id)->update(['current_release' => $release, 'updated_at' => now()]);
        $this->say('switch', "now running {$target['image']}".(isset($images[0]['image']) ? " (was {$images[0]['image']})" : ''));
        $this->audit->record($site->organization_id, $this->actor(), 'site.rolled_back', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['release' => $release]);
        $this->node = null;

        return $release;
    }

    private function assertContainersReady(stdClass $site): void
    {
        if (DB::table('nodes')->where('node_id', $site->node_id)->value('containers_ready_at') === null) {
            throw new RuntimeException("{$site->node_id} is not prepared for containers. Prepare it from its Containers page, then deploy again.");
        }
    }

    /** One command's output from a server. @param list<string> $args */
    private function execOutput(stdClass $on, string $name, array $args): string
    {
        return (string) ($this->command($on, 'exec', ['name' => $name, 'args' => $args])['detail']['output'] ?? '');
    }

    /**
     * Rewrites the routes on the ingress in front of the site's server, now, when there is one: a name must route
     * before Let's Encrypt checks it, which happens in the same operation that sets it. Returns the routes written.
     *
     * @return array<string, string>|null
     */
    public function syncIngress(stdClass $site): ?array
    {
        if (\App\Lib\Providers\Incus\Ingress::sourceFor($site) === null || ! \App\Lib\Providers\Incus\Ingress::active((string) $site->organization_id)) {
            return null;
        }
        $routes = \App\Lib\Providers\Incus\Ingress::routes((string) $site->organization_id);
        $ingress = (object) ['organization_id' => $site->organization_id, 'node_id' => \App\Lib\Providers\Incus\Ingress::nodeId((string) $site->organization_id), 'name' => 'ingress'];
        $this->recipe($ingress, \App\Lib\Providers\Incus\Ingress::recipe($routes), 600);

        return $routes;
    }

    private function recipe(stdClass $site, array $spec, ?int $timeout = null): void
    {
        $result = $this->command($site, 'apply_recipe', ['spec' => $spec], $timeout);

        foreach ($result['detail']['run']['steps'] ?? [] as $step) {
            $this->say((string) ($step['action'] ?? '?'), (string) ($step['description'] ?? $step['id'] ?? ''));
        }
    }

    private function say(string $action, string $text): void
    {
        if ($this->log !== null) {
            $where = $this->multi && $this->node !== null ? '['.(strstr($this->node, '.', true) ?: $this->node).'] ' : '';
            ($this->log)($action, $where.$text);
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array<string, mixed>
     */
    private function command(stdClass $site, string $kind, array $args, ?int $timeout = null): array
    {
        $result = $this->gateway->run(
            organizationId: $site->organization_id,
            nodeId: $site->node_id,
            kind: $kind,
            args: $args,
            timeoutSeconds: $timeout,
            waitSeconds: $timeout !== null ? $timeout + 30 : null,
            reason: "Deploy {$site->name}",
        );

        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException(sprintf('%s %s: %s', $kind, $result['status'] ?? 'failed', $result['error'] ?? 'no detail'));
        }

        return $result;
    }
}
