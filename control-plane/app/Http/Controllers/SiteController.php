<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Auth\Org;
use App\Lib\Containers\ContainerApps;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Git\GitRepo;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Present;
use App\Lib\Sites\Runtimes;
use App\Lib\Sites\Sites;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * An application's overview: what is live, the certificate, what it runs on and its latest deployments,
 * and the actions on the page (deploy, roll back, the certificate).
 */
final class SiteController extends Controller
{
    public function show(Request $request): Response
    {
        Gate::authorize('view');

        return Inertia::render('Site', [
            'view' => fn () => $this->view((int) $request->query('site')),
            // What the checks found in the last upload that was queued; flashed once, never the key.
            'certSummary' => fn () => $request->session()->get('certSummary'),
        ]);
    }

    public function deploy(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        $site = $this->siteId($request);

        return $this->toOperation(app(OperationRunner::class)->queueDeploy($site, 'console', (string) $request->user()->email));
    }

    public function rollback(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        $site = $this->siteId($request);

        return $this->toOperation(app(OperationRunner::class)->queueRollback($site, 'console', (string) $request->user()->email));
    }

    /** Re-reads the certificate's end date from the server. */
    public function certificateCheck(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        $site = $this->siteId($request, tls: true);

        return $this->toOperation(app(OperationRunner::class)->queueCertificateCheck($site, 'console', (string) $request->user()->email));
    }

    /** Forces a new Let's Encrypt certificate now. */
    public function certificateRenew(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        $site = $this->siteId($request, tls: true);

        return $this->toOperation(app(OperationRunner::class)->queueCertificateRenew($site, 'console', (string) $request->user()->email));
    }

    /** Flips certbot's automatic renewal. Deploy-gated like Renew now: it changes when, not what, is served. */
    public function certificateAutoRenew(Request $request): RedirectResponse
    {
        Gate::authorize('deploy');
        $row = DB::table('sites')->where('organization_id', Org::id())->where('id', (int) $request->input('site'))->where('tls', true)->first() ?? abort(404);
        try {
            $id = app(OperationRunner::class)->queueCertificateAutoRenew((int) $row->id, ! (bool) $row->cert_auto_renew, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['certificate' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    /**
     * Checks and queues an uploaded certificate. The chain and key go to the vault for the queued job; only what
     * the checks found comes back. They are in the POST body only, and bootstrap/app.php keeps them out of the
     * flashed input if validation fails.
     */
    public function certificateUpload(Request $request): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate([
            'certChain' => 'required|string|max:100000',
            'certKey' => 'required|string|max:20000',
        ]);
        $site = $this->siteId($request);
        try {
            $queued = app(OperationRunner::class)->queueCertificateUpload($site, $data['certChain'], $data['certKey'], 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            // The key itself is never in these messages.
            throw ValidationException::withMessages(['certificate' => $e->getMessage()]);
        }
        $queued['summary']['expires_label'] = Carbon::parse($queued['summary']['expires_at'])->utc()->format('j M Y');

        return back()->with('certSummary', $queued['summary'] + ['id' => $queued['id']]);
    }

    /** Back to a Let's Encrypt certificate. */
    public function certificateRevert(Request $request): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $site = $this->siteId($request, tls: true);
        try {
            $id = app(OperationRunner::class)->queueCertificateRevert($site, 'console', (string) $request->user()->email);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['certificate' => $e->getMessage()]);
        }

        return $this->toOperation($id);
    }

    private function toOperation(int $id): RedirectResponse
    {
        return redirect("/operation?operation={$id}");
    }

    /** The site's id from the request, which must be this team's own (and with HTTPS on, when asked). */
    private function siteId(Request $request, bool $tls = false): int
    {
        $query = DB::table('sites')->where('organization_id', Org::id())->where('id', (int) $request->input('site'));
        abort_unless($tls ? $query->where('tls', true)->exists() : $query->exists(), 404);

        return (int) $request->input('site');
    }

    /** Everything the site page shows. @return array<string, mixed> */
    private function view(int $site): array
    {
        $row = DB::table('sites')->where('organization_id', Org::id())->where('id', $site)->first();
        if ($row === null) {
            return ['missing' => true];
        }

        $ops = DB::table('operations')->where('organization_id', Org::id())->where('site_id', $row->id)->whereIn('kind', ['deploy', 'rollback'])->orderByDesc('id')->limit(12)->get();
        $succeeded = $ops->where('kind', 'deploy')->where('status', 'succeeded')->values();
        $liveOp = $succeeded->firstWhere('release_id', $row->current_release);
        // The agent rolls back to the release before the current one. That
        // is the previous successful deploy only while the live release is
        // the newest; after a rollback, offer nothing rather than guess.
        $previous = ($liveOp !== null && $succeeded->first()?->id === $liveOp->id) ? $succeeded->get(1) : null;
        // A container application rolls back to the image it kept, whichever deploy made it.
        $container = Runtimes::isContainer($row) ? ContainerApps::state($row) : null;
        if ($container !== null) {
            $kept = $container['images'][1]['release'] ?? null;
            $previous = $kept === null ? null : $succeeded->firstWhere('release_id', $kept);
        }
        $running = $ops->first(fn ($op) => in_array($op->status, ['queued', 'running'], true));
        // How the latest thing done to this site ended: a failure is said on the page, not only on the operation's own.
        $latest = $ops->sortByDesc('id')->first();
        $failedLast = $latest !== null && $latest->status === 'failed' ? [
            'id' => $latest->id, 'title' => $latest->title, 'ago' => Present::ago($latest->finished_at ?? $latest->created_at),
            'why' => mb_substr(trim((string) strtok((string) $latest->error, "\n")), 0, 240),
        ] : null;

        $releases = [];
        foreach ($ops->where('kind', 'deploy') as $op) {
            $status = match (true) {
                in_array($op->status, ['queued', 'running'], true) => ['running', 'Deploying'],
                $op->status === 'failed' => ['failed', 'Failed at '.(Present::currentStage($op) ?? 'start')],
                $op->release_id === $row->current_release => ['live', 'Live'],
                $previous !== null && $op->id === $previous->id => ['', 'Previous'],
                default => ['', 'Kept'],
            };
            $releases[] = [
                'id' => $op->id,
                'release' => $op->release_id,
                'commit' => Present::commit($op->commit),
                'by' => Present::startedBy($op),
                'took' => $op->finished_at ? Present::duration($op->started_at, $op->finished_at) : '',
                'class' => $status[0],
                'label' => $status[1],
                'rollback' => $previous !== null && $op->id === $previous->id,
                'neverLive' => $op->status === 'failed',
                'state' => match (true) {
                    in_array($op->status, ['queued', 'running'], true) => 'running', $op->status === 'failed' => 'failed', default => 'ok'
                },
                'ago' => Present::ago($op->finished_at ?? $op->started_at ?? $op->created_at),
            ];
        }

        $daemons = array_map(fn ($d) => $d['name'].' ×'.$d['processes'], Sites::daemons($row));

        return [
            'missing' => false,
            'id' => $row->id,
            'name' => $row->name,
            'env' => $row->parent_site_id === null ? 'production' : ($row->pr_number ? "PR #{$row->pr_number}" : $row->branch),
            'runtime' => Runtimes::runtime($row),
            'container' => $container === null ? null : [
                'kind' => $container['kind'], 'image' => $container['image_ref'], 'digest' => $container['digest'], 'port' => $container['container_port'],
                'hostPort' => $container['host_port'], 'memory' => $container['memory'], 'readOnly' => (bool) $container['read_only'],
                'dockerfile' => $container['kind'] === 'dockerfile' ? $container['dockerfile'] : null, 'kept' => count($container['images']),
            ],
            'static' => Runtimes::runtime($row) === 'static',
            // Production only: how many previews it has, for the set-up prompt.
            'previews' => $container !== null ? null : ($row->parent_site_id === null ? DB::table('sites')->where('organization_id', Org::id())->where('parent_site_id', $row->id)->count() : null),
            'domain' => $row->domain,
            'url' => ((bool) $row->tls ? 'https://' : 'http://').$row->domain,
            'tls' => (bool) $row->tls,
            'canDeploy' => Gate::allows('deploy'),
            'canInfra' => Gate::allows('infrastructure'),
            'canRenew' => (bool) $row->tls && $row->cert_source === 'letsencrypt',
            'autoRenew' => (bool) $row->cert_auto_renew,
            'certCustom' => (bool) $row->tls && $row->cert_source === 'custom',
            'cert' => (bool) $row->tls ? Sites::certificateStatus($row->cert_expires_at) : null,
            'node' => strstr($row->node_id, '.', true) ?: $row->node_id,
            'servers' => array_map(fn ($n) => strstr($n, '.', true) ?: $n, Sites::nodes($row)),
            'branch' => $row->branch,
            'live' => $row->current_release,
            'liveShort' => Present::shortRelease($row->current_release),
            'liveCommit' => Present::commit($liveOp->commit ?? null),
            'liveAgo' => $liveOp ? Present::ago($liveOp->finished_at) : null,
            'liveTook' => $liveOp ? Present::duration($liveOp->started_at, $liveOp->finished_at) : null,
            'workers' => (int) $row->queue_workers,
            'reverbPort' => (bool) $row->reverb ? Sites::reverbPort($row) : null,
            'daemons' => $daemons,
            'repo' => $row->repository === null ? '' : $this->repo($row->repository),
            'source' => $container !== null && $container['kind'] === 'image' ? 'Docker image' : ($row->git_source === 'github_app' ? 'GitHub App' : 'Deploy key'),
            'push' => (bool) $row->push_to_deploy,
            'running' => $running ? ['id' => $running->id, 'title' => $running->title] : null,
            'failedLast' => $failedLast,
            'awaitingKey' => Sites::awaitingDeployKey($row),
            'releases' => $releases,
            'topology' => $this->topology($row),
        ];
    }

    /**
     * What the environment runs on, for the overview: network, compute and
     * data, each a card of label and value. Read from the records only, so
     * it costs no cloud API call; servers are marked online from the gateway.
     *
     * @return array<string, mixed>
     */
    private function topology(object $row): array
    {
        $resource = fn ($id) => $id ? DB::table('resources')->where('organization_id', Org::id())->where('id', $id)->first(['slug', 'state', 'attributes']) : null;
        $attrs = fn ($r) => $r ? (json_decode((string) $r->attributes, true) ?: []) : [];
        try {
            $online = collect(app(GatewayClient::class)->connectedNodes())->pluck('node_id')->flip();
        } catch (\Throwable) {
            $online = null;
        }

        $balancer = $resource($row->load_balancer_id ?? null);
        $network = [['title' => 'Domain', 'rows' => [
            ['Host', $row->domain, null],
            ['HTTPS', (bool) $row->tls ? ($row->cert_source === 'custom' ? 'Your own certificate' : 'Let\'s Encrypt') : 'off', (bool) $row->tls ? 'ok' : 'warn'],
        ]]];
        if ($balancer) {
            $network[] = ['title' => 'Load balancer', 'note' => $balancer->slug, 'rows' => [
                ['Address', $attrs($balancer)['ip'] ?? 'pending', null],
                ['Health check', 'GET /up', null],
                ['State', $balancer->state, $balancer->state === 'active' ? 'ok' : 'warn'],
            ]];
        }

        $poolId = $row->autoscale_pool_id ?? null;
        $servers = [];
        foreach (Sites::nodes($row) as $i => $node) {
            $member = $poolId && DB::table('site_nodes')->where('site_id', $row->id)->where('node_id', $node)->whereNotNull('autoscale_pool_id')->exists();
            $short = strstr($node, '.', true) ?: $node;
            $servers[] = [$short.($i === 0 ? ' · primary' : ($member ? ' · pool' : '')),
                $online === null ? 'unknown' : ($online->has($node) ? 'online' : 'offline'), $online === null ? null : ($online->has($node) ? 'ok' : 'bad'),
                '/servers#node-'.$short];
        }
        $compute = [['title' => 'Servers', 'note' => count($servers).($poolId ? ' now' : ''), 'rows' => $servers]];
        if (Runtimes::runtime($row) === 'static') {
            // Files nginx serves: no processes, database, cache or uploads to show.
            return ['Network' => $network, 'Compute' => $compute];
        }
        if (Runtimes::isContainer($row)) {
            // One container on one server: no workers, scheduler, Reverb, balancer or pool to show.
            $c = ContainerApps::state($row);
            $compute = [$compute[0], ['title' => 'Container', 'note' => $c['kind'] === 'image' ? 'Docker image' : 'Dockerfile', 'rows' => [
                [$c['kind'] === 'image' ? 'Image' : 'Built from', $c['kind'] === 'image' ? (string) $c['image_ref'] : (string) $c['dockerfile'], null],
                ['Digest', $c['digest'] === null ? 'not pulled yet' : substr((string) $c['digest'], 0, 19), null],
                ['Port', 'container '.$c['container_port'].($c['host_port'] === null ? '' : ' · 127.0.0.1:'.$c['host_port']), null],
                ['Memory limit', (string) $c['memory'], null],
            ]]];
            $connected = array_filter([['title' => 'Database', 'note' => $db0 = $resource($row->database_cluster_id ?? null)?->slug, 'rows' => [['Cluster', (string) $db0, null]]]], fn ($c) => $c['note'] !== null);

            return ['Network' => $network, 'Compute' => $compute] + ($connected === [] ? [] : ['Data' => array_values($connected)]);
        }
        $processes = [['Queue worker ×'.(int) $row->queue_workers, (int) $row->queue_workers > 0 ? 'Running' : 'Off', (int) $row->queue_workers > 0 ? 'ok' : null]];
        if ((bool) $row->reverb) {
            $processes[] = ['Reverb · :'.Sites::reverbPort($row), 'Running', 'ok'];
        }
        foreach (Sites::daemons($row) as $d) {
            $processes[] = [$d['name'].' ×'.$d['processes'], 'Running', 'ok'];
        }
        $processes[] = (bool) ($row->scheduler ?? true) ? ['Scheduler', 'every minute', null] : ['Scheduler', 'Off', 'warn'];
        $compute[] = ['title' => 'Processes', 'note' => Present::shortRelease($row->current_release) ?: null, 'rows' => $processes];
        if ($pool = $resource($poolId)) {
            $p = $attrs($pool);
            $compute[] = ['title' => 'Autoscale pool', 'note' => $pool->slug, 'rows' => [
                ['Servers', ($p['live_min'] ?? $p['min'] ?? '?').' to '.($p['max'] ?? '?'), null],
                ['Adds one above', isset($p['cpu']) ? round($p['cpu'] * 100).'% CPU' : '—', null],
            ]];
        }

        $db = $resource($row->database_cluster_id ?? null);
        $cache = $resource($row->cache_cluster_id ?? null);
        $bucket = $resource($row->bucket_id ?? null);
        $engine = fn ($r) => ['pg' => 'PostgreSQL', 'mysql' => 'MySQL', 'valkey' => 'Valkey'][$attrs($r)['engine'] ?? ''] ?? 'cluster';
        $data = [
            ['title' => 'Database', 'note' => $db?->slug, 'rows' => $db
                ? [['Engine', $engine($db), null], ['Database', str_replace('-', '_', $row->name), null], ['State', $db->state, $db->state === 'active' ? 'ok' : 'warn']]
                : [['Engine', $this->envEngine($row), null], ['Where', 'on the server', null]]],
            ['title' => 'Cache, sessions, queue', 'note' => $cache?->slug, 'rows' => [['Engine', $cache ? $engine($cache) : 'the database', null]]],
            ['title' => 'Uploads', 'note' => $bucket?->slug, 'rows' => [['Kept on', $bucket ? 'a Spaces bucket' : 'the server disk', null]]],
        ];

        return ['Network' => $network, 'Compute' => $compute, 'Data' => $data];
    }

    /** The engine a site without a database cluster uses: what DB_CONNECTION says in the env it runs with (injected and its own variables). */
    private function envEngine(object $row): string
    {
        $env = app(Sites::class)->env($row);
        $connection = preg_match('/^DB_CONNECTION=["\']?([^"\'\s]*)/m', $env, $m) ? strtolower($m[1]) : 'sqlite';

        return match ($connection) {
            'mysql', 'mariadb' => 'MySQL',
            'pgsql', 'postgres', 'postgresql' => 'PostgreSQL',
            'sqlite', '' => 'SQLite',
            default => $connection,
        };
    }

    private function repo(?string $repository): string
    {
        try {
            return GitRepo::parse($repository)->display();
        } catch (\Throwable) {
            return (string) $repository;
        }
    }
}
