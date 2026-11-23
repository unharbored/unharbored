<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Lib\Api\ApiTokens;
use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Projects;
use App\Lib\Auth\Roles;
use App\Lib\Services\Services;
use App\Lib\Gateway\GatewayClient;
use App\Http\Controllers\Controller;
use App\Lib\Operations\OperationRunner;
use App\Lib\Operations\Operations;
use App\Lib\Servers\ServerStack;
use App\Lib\Sites\SiteBackups;
use App\Lib\Sites\Sites;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use stdClass;

/**
 * The public API, version 1: what the console does, for the cm CLI, its MCP
 * server and anything else holding a token. Everything is scoped to the
 * token's organisation; a site is named by its name or id.
 */
final class V1Controller extends Controller
{
    /** Artisan commands that never return: they would hold the call until it times out. */
    private const LONG_RUNNING = ['tinker', 'serve', 'queue:work', 'queue:listen', 'schedule:work', 'reverb:start', 'horizon', 'octane:start', 'pail'];

    public function __construct(
        private readonly Sites $sites,
        private readonly OperationRunner $runner,
        private readonly Operations $operations,
        private readonly AuditLog $audit,
    ) {}

    public function whoami(Request $request): JsonResponse
    {
        $token = $this->token($request);

        return response()->json(['organization' => $token->organization_id, 'token' => $token->name, 'scopes' => json_decode($token->scopes, true)]);
    }

    public function sites(Request $request): JsonResponse
    {
        $org = $this->token($request)->organization_id;
        $query = DB::table('sites')->where('organization_id', $org);
        if ($request->query('project') !== null) {
            // A filter, not a boundary: find() still reaches every site of the organisation.
            $project = DB::table('projects')->where('organization_id', $org)->where('slug', (string) $request->query('project'))->value('id');
            if ($project === null) {
                abort(response()->json(['error' => 'No project '.$request->query('project').'.'], 404));
            }
            $query->where('project_id', $project);
        }
        $rows = $query->orderBy('name')->get();

        return response()->json(['sites' => $rows->map(fn ($s) => $this->siteSummary($s))->all()]);
    }

    public function projects(Request $request): JsonResponse
    {
        return response()->json(['projects' => Projects::listing($this->token($request)->organization_id)]);
    }

    /** Template services (docs/specs/templates.md), narrowed by ?project=<slug> like sites. Never secrets. */
    public function services(Request $request, Services $services): JsonResponse
    {
        $org = $this->token($request)->organization_id;
        $project = null;
        if ($request->query('project') !== null) {
            $project = DB::table('projects')->where('organization_id', $org)->where('slug', (string) $request->query('project'))->value('id');
            if ($project === null) {
                abort(response()->json(['error' => 'No project '.$request->query('project').'.'], 404));
            }
        }

        return response()->json(['services' => array_map($this->serviceSummary(...), $services->describe($org, $project === null ? null : (int) $project))]);
    }

    public function service(Request $request, string $service, Services $services): JsonResponse
    {
        $row = $this->findService($request, $service);

        return response()->json($this->serviceSummary($services->describe($row->organization_id, null, (int) $row->id)[0]) + [
            'recent_operations' => DB::table('operations')->where('organization_id', $row->organization_id)->where('meta->service_id', (int) $row->id)->orderByDesc('id')->limit(10)->get()
                ->map(fn ($o) => $this->operationSummary($o))->all(),
        ]);
    }

    /** start, stop or restart, queued as an operation like a deploy. The token's deploy scope is the Developer permission. */
    public function serviceAction(Request $request, string $service, string $action, Services $services): JsonResponse
    {
        $row = $this->findService($request, $service);
        try {
            $id = $services->action($row->organization_id, $row->node_id, $row->name, $action, $this->actorLabel($request), Roles::DEVELOPER, true, 'api');
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return $this->started($id);
    }

    /** This service's backups, newest first. No bucket keys. */
    public function serviceBackups(Request $request, string $service, Services $services): JsonResponse
    {
        $row = $this->findService($request, $service);

        return response()->json(['backups' => $services->backups($row->organization_id, (int) $row->id)]);
    }

    /** A backup of the service's data now, queued as an operation. The manage scope is the Manager permission. */
    public function serviceBackup(Request $request, string $service, Services $services): JsonResponse
    {
        $row = $this->findService($request, $service);
        try {
            $id = $services->backup($row->organization_id, $row->node_id, $row->name, 'manual', $this->actorLabel($request), Roles::MANAGER, true, 'api');
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return $this->started($id);
    }

    /**
     * Updates the service to its template's newer version. A change of download host or image repository is refused unless the body
     * says `confirm: true`; `inputs` gives values for inputs the new version adds; `approve_ports` and `acknowledge` re-approve what
     * the new version asks for.
     */
    public function serviceUpdate(Request $request, string $service, Services $services): JsonResponse
    {
        $row = $this->findService($request, $service);
        $inputs = $request->input('inputs', []);
        if (! is_array($inputs)) {
            return response()->json(['error' => 'inputs is an object of key: value.'], 422);
        }
        try {
            $id = $services->update($row->organization_id, $row->node_id, $row->name, $inputs, $this->actorLabel($request), Roles::MANAGER, $request->boolean('confirm'),
                $request->boolean('approve_ports'), $request->boolean('acknowledge'), true, 'api');
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return $this->started($id);
    }

    public function site(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);
        $ops = DB::table('operations')->where('site_id', $row->id)->orderByDesc('id')->limit(10)->get();

        return response()->json($this->siteSummary($row) + [
            'servers' => array_map(fn ($n) => ['node' => $n, 'primary' => $n === $row->node_id], Sites::nodes($row)),
            'pending_changes' => $row->pending_since !== null,
            'recent_operations' => $ops->map(fn ($o) => $this->operationSummary($o))->all(),
        ]);
    }

    public function deploy(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);
        $commit = $request->input('commit');
        if ($commit !== null && ! preg_match('/^[0-9a-f]{7,40}$/', (string) $commit)) {
            return response()->json(['error' => 'commit is a hex SHA.'], 422);
        }
        $id = $this->runner->queueDeploy($row->id, 'api', $this->actorLabel($request), $commit);

        return $this->started($id);
    }

    public function rollback(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);

        return $this->started($this->runner->queueRollback($row->id, 'api', $this->actorLabel($request)));
    }

    /** An operation, with its log from byte `after` on, so a client can follow it. */
    public function operation(Request $request, int $id): JsonResponse
    {
        $op = DB::table('operations')->where('organization_id', $this->token($request)->organization_id)->where('id', $id)->first();
        if ($op === null) {
            return response()->json(['error' => "No operation {$id}."], 404);
        }
        $after = max(0, (int) $request->query('after', 0));
        $log = (string) $op->log;

        return response()->json($this->operationSummary($op) + [
            'stages' => json_decode((string) $op->stages, true) ?: [],
            'error' => $op->error,
            'log' => substr($log, min($after, strlen($log))),
            'next' => strlen($log),
        ]);
    }

    public function logs(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);
        $since = Carbon::now()->subMinutes(min(10080, max(1, (int) $request->query('minutes', 60))));
        $query = DB::table('log_lines')->where('organization_id', $row->organization_id)->whereIn('node_id', Sites::nodes($row))
            ->whereIn('source', ["laravel:{$row->name}", "worker:{$row->name}", "app:{$row->name}", "nginx.error:{$row->name}", 'nginx.error'])->where('logged_at', '>=', $since);
        if (($q = (string) $request->query('q', '')) !== '') {
            $query->where('text', 'like', '%'.mb_substr($q, 0, 200).'%');
        }
        $lines = $query->orderByDesc('logged_at')->orderByDesc('id')->limit(min(1000, max(1, (int) $request->query('limit', 200))))->get();

        return response()->json(['lines' => $lines->reverse()->values()->map(fn ($l) => [
            'at' => Carbon::parse($l->logged_at)->toIso8601ZuluString(), 'server' => $l->node_id, 'source' => $l->source, 'text' => $l->text,
        ])->all()]);
    }

    /** Key names; the contents too with ?reveal=1 and the manage scope, audited like the console's reveal. */
    public function env(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);
        $env = $this->sites->env($row);
        preg_match_all('/^([A-Za-z_][A-Za-z0-9_]*)=/m', $env, $m);
        // keys is every name the server gets; injected and overridden say which
        // were set by Unharbored and which of those the user's own variables replace.
        $layers = $this->sites->envLayers($row);
        $out = ['keys' => $m[1], 'injected' => array_column($layers['injected'], 'key'), 'overridden' => $layers['overridden'],
            'versions' => array_slice($this->sites->envVersions($row), 0, 5)];
        if ($request->boolean('reveal')) {
            if (! ApiTokens::allows($this->token($request), 'manage')) {
                return response()->json(['error' => 'Revealing .env needs the manage scope.'], 403);
            }
            $this->audit->record($row->organization_id, ApiTokens::actor($this->token($request)), 'site.env_read', resourceType: 'site',
                resourceId: "{$row->node_id}/{$row->name}");
            // contents is what the server gets; custom is the part you can change.
            $out['contents'] = $env;
            $out['custom'] = $layers['custom'];
        }

        return response()->json($out);
    }

    /**
     * Sets your custom variables ({"values": {"KEY": "value"}}); a null value removes one.
     * A value for a name Unharbored injects overrides it. Takes effect on the next deploy.
     */
    public function setEnv(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);
        $values = (array) $request->input('values', []);
        if ($values === []) {
            return response()->json(['error' => 'values is an object of KEY: value.'], 422);
        }
        $env = $this->sites->customEnv($row);
        $mine = Sites::envKeys($env);
        foreach ($values as $key => $value) {
            if (! preg_match('/^[A-Z][A-Z0-9_]*$/', (string) $key) || (is_string($value) && str_contains($value, "\n"))) {
                return response()->json(['error' => "{$key}: keys are UPPER_CASE, values one line."], 422);
            }
            if ($value === null && ! isset($mine[(string) $key])) {
                return response()->json(['error' => "{$key} is not one of your variables. If Unharbored injects it, set a value to override it."], 422);
            }
            $pattern = '/^'.preg_quote((string) $key, '/').'=.*\n?/m';
            if ($value === null) {
                $env = (string) preg_replace($pattern, '', $env);
            } else {
                $line = "{$key}={$value}";
                $env = preg_match($pattern, $env) === 1
                    ? (string) preg_replace('/^'.preg_quote((string) $key, '/').'=.*$/m', str_replace(['\\', '$'], ['\\\\', '\\$'], $line), $env)
                    : rtrim($env, "\n")."\n{$line}\n";
            }
        }
        $this->sites->setEnv($row, $env, 'set over the API by '.$this->actorLabel($request).': '.implode(', ', array_keys($values)));

        return response()->json(['saved' => array_keys($values), 'note' => 'Applies on the next deploy.']);
    }

    /** Runs php artisan on the site's primary, from the live release, and returns what it printed. */
    public function artisan(Request $request, string $site, GatewayClient $gateway): JsonResponse
    {
        $row = $this->find($request, $site);
        $args = $request->input('args');
        if (! is_array($args) || $args === [] || count($args) > 30 || array_filter($args, fn ($a) => ! is_string($a) || strlen($a) > 500) !== []) {
            return response()->json(['error' => 'args is a list of strings: ["migrate:status"] or ["cache:clear"].'], 422);
        }
        if (in_array($args[0], self::LONG_RUNNING, true)) {
            return response()->json(['error' => "{$args[0]} runs until stopped; it cannot be run over the API."], 422);
        }
        if ($row->current_release === null) {
            return response()->json(['error' => 'Deploy the site first.'], 409);
        }
        // The gateway keeps no output from a failed command, so the exit
        // code is reported in the output and the shell always succeeds.
        $result = $gateway->run($row->organization_id, $row->node_id, 'exec', [
            'name' => 'runuser',
            'args' => ['-u', ServerStack::DEPLOY_USER, '--', 'sh', '-c', 'cd "$1" && shift && "$0" artisan "$@" 2>&1; echo "__CM_EXIT=$?"',
                ServerStack::phpBinary(Sites::phpVersion($row)), Sites::root($row).'/current', ...$args],
        ], timeoutSeconds: 120, actor: ApiTokens::actor($this->token($request)), reason: 'php artisan '.implode(' ', $args).' over the API');
        if (($result['status'] ?? null) !== 'succeeded') {
            return response()->json(['error' => $result['error'] ?? 'The command did not run.'], 502);
        }
        $output = (string) ($result['detail']['output'] ?? '');
        preg_match('/__CM_EXIT=(\d+)\s*$/', $output, $m);

        return response()->json(['exit_code' => isset($m[1]) ? (int) $m[1] : null, 'output' => mb_substr((string) preg_replace('/__CM_EXIT=\d+\s*$/', '', $output), -65536)]);
    }

    public function backups(Request $request, string $site, SiteBackups $backups): JsonResponse
    {
        $row = $this->find($request, $site);

        return response()->json(['backups' => array_map(fn ($b) => ['backup' => $b['object'], 'at' => $b['at']->toIso8601ZuluString(), 'kind' => $b['kind'], 'bytes' => $b['bytes']],
            $backups->enabled() ? $backups->list($row) : [])]);
    }

    public function backUp(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);

        return $this->started($this->runner->queueSiteBackup($row->id, 'manual', 'api', $this->actorLabel($request)));
    }

    public function restore(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);
        $backup = (string) $request->input('backup', '');
        if (! str_starts_with($backup, SiteBackups::prefix($row))) {
            return response()->json(['error' => 'backup is one of this site\'s, as GET …/backups lists them.'], 422);
        }

        return $this->started($this->runner->queueSiteRestore($row->id, $backup, 'api', $this->actorLabel($request)));
    }

    public function previews(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);

        return response()->json(['previews' => DB::table('sites')->where('parent_site_id', $row->id)->orderByDesc('id')->get()
            ->map(fn ($p) => $this->siteSummary($p) + ['follows_pushes' => (bool) $p->push_to_deploy, 'idle_days' => $p->preview_idle_days, 'database' => $p->preview_database, 'pull_request' => $p->pr_number])->all()]);
    }

    /**
     * {"branch": "…", "follow": false, "idle_days": 7, "database": "clone"}; idle_days null keeps it
     * until deleted; database (fresh, clone or shared) defaults to the site's choice.
     */
    public function createPreview(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);
        $branch = (string) $request->input('branch', '');
        $days = $request->has('idle_days') ? ($request->input('idle_days') === null ? null : (int) $request->input('idle_days')) : \App\Lib\Sites\Previews::DEFAULT_IDLE_DAYS;
        try {
            $id = $this->runner->queuePreviewCreate($row->id, $branch, $request->boolean('follow'), $days, 'api', $this->actorLabel($request),
                $request->filled('database') ? (string) $request->input('database') : null);
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        $preview = DB::table('sites')->where('id', DB::table('operations')->where('id', $id)->value('site_id'))->first();

        return $this->started($id, ['preview' => $preview->name, 'preview_url' => 'https://'.$preview->domain]);
    }

    /**
     * Makes an application: the wizard's answers (name, repository, runtime, server, ...) checked into a plan, then
     * started. The response is the operation to follow; the application exists at once.
     */
    public function createSite(Request $request, \App\Lib\Sites\NewApplications $apps): JsonResponse
    {
        $org = $this->token($request)->organization_id;
        try {
            $plan = $apps->plan($org, $request->json()->all());
            $started = $apps->start($org, $plan, $this->actorLabel($request));
        } catch (InvalidArgumentException $e) {
            [$field, $message] = array_pad(explode(': ', $e->getMessage(), 2), 2, $e->getMessage());

            return response()->json(['error' => $e->getMessage(), 'field' => $message === $e->getMessage() ? null : $field], 422);
        }

        return $this->started($started['operation'], ['site' => $plan['name'], 'server' => $plan['server']['node'] ?? null]);
    }

    /**
     * Deletes a preview (a deploy token can), or an application (a manage token; ?data=1 also erases its database,
     * uploaded files and stored secrets). Servers and shared clusters are never destroyed by this.
     */
    public function deleteSite(Request $request, string $site): JsonResponse
    {
        $row = $this->find($request, $site);
        if ($row->parent_site_id !== null) {
            return $this->started($this->runner->queuePreviewDestroy($row->id, 'api', $this->actorLabel($request)));
        }
        if (! ApiTokens::allows($this->token($request), 'manage')) {
            return response()->json(['error' => 'This token cannot manage. Deleting an application needs the manage scope.'], 403);
        }
        try {
            return $this->started($this->runner->queueAppDelete($row->id, $request->boolean('data'), 'api', $this->actorLabel($request)));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Destroys a server this platform created, once nothing is served from it. */
    public function deleteServer(Request $request, string $node): JsonResponse
    {
        $org = $this->token($request)->organization_id;
        if (! DB::table('nodes')->where('organization_id', $org)->where('node_id', $node)->exists()) {
            return response()->json(['error' => "No server {$node}."], 404);
        }
        try {
            return $this->started($this->runner->queueServerDestroy($node, 'api', $this->actorLabel($request)));
        } catch (InvalidArgumentException $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    /** Each connected provider: what it supplies, its catalog, and which shapes are open or locked and why. */
    public function providers(Request $request, \App\Lib\Providers\Providers $providers): JsonResponse
    {
        $org = $this->token($request)->organization_id;

        return response()->json(['providers' => array_values(array_map(\App\Lib\Providers\Shapes::describe(...), $providers->connected($org)))]);
    }

    public function servers(Request $request, GatewayClient $gateway): JsonResponse
    {
        $org = $this->token($request)->organization_id;
        try {
            $connected = collect($gateway->connectedNodes())->pluck('node_id')->flip();
        } catch (\Throwable) {
            $connected = collect();
        }

        return response()->json(['servers' => DB::table('nodes')->where('organization_id', $org)->orderBy('node_id')->get()->map(function ($n) use ($connected) {
            $m = DB::table('node_metrics')->where('node_id', $n->node_id)->orderByDesc('collected_at')->first();

            return ['node' => $n->node_id, 'connected' => $connected->has($n->node_id), 'agent' => $n->agent_version, 'provider' => $n->provider,
                'cpu' => $m ? round((float) $m->cpu, 1) : null,
                'memory_used' => $m && $m->mem_total > 0 ? round(100 * ($m->mem_total - $m->mem_available) / $m->mem_total, 1) : null,
                'disk_used' => $m && $m->disk_total > 0 ? round(100 * ($m->disk_total - $m->disk_free) / $m->disk_total, 1) : null];
        })->all()]);
    }

    private function token(Request $request): stdClass
    {
        return $request->attributes->get('api_token');
    }

    private function actorLabel(Request $request): string
    {
        return 'token '.$this->token($request)->name;
    }

    private function find(Request $request, string $site): stdClass
    {
        $org = $this->token($request)->organization_id;
        $rows = DB::table('sites')->where('organization_id', $org)
            ->where(fn ($q) => ctype_digit($site) ? $q->where('id', (int) $site) : $q->where('name', $site))->get();
        if ($rows->count() !== 1) {
            abort(response()->json(['error' => $rows->isEmpty() ? "No site {$site}." : "More than one site is called {$site}: use its id."], $rows->isEmpty() ? 404 : 409));
        }

        return $rows->first();
    }

    /** A service by id or by name (names are unique in a team). */
    private function findService(Request $request, string $service): stdClass
    {
        return DB::table('services')->where('organization_id', $this->token($request)->organization_id)
            ->where(fn ($q) => ctype_digit($service) ? $q->where('id', (int) $service) : $q->where('name', $service))->first()
            ?? abort(response()->json(['error' => "No service {$service}."], 404));
    }

    /** @param  array<string, mixed>  $s one of Services::describe() */
    private function serviceSummary(array $s): array
    {
        return ['id' => $s['id'], 'name' => $s['name'], 'template' => $s['template'], 'version' => $s['version'], 'server' => $s['server'],
            'project' => $s['project']['slug'] ?? null, 'status' => $s['status'], 'error' => $s['error'], 'runtime' => $s['runtime'],
            'endpoints' => array_map(fn ($e) => ['domain' => $e['domain'], 'tls' => $e['tls']] + ($s['runtime'] === 'container' ? ['listen' => $e['listen'], 'container_port' => $e['container_port'] ?? null] : []), $s['endpoints']),
            'update' => $s['update'],
            'operation' => $s['operation'] === null ? null : ['id' => $s['operation']['id'], 'status' => $s['operation']['status'], 'follow' => "/api/v1/operations/{$s['operation']['id']}"]];
    }

    private function siteSummary(stdClass $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'url' => ((bool) $s->tls ? 'https://' : 'http://').$s->domain,
            'primary' => $s->node_id, 'branch' => $s->branch, 'live_release' => $s->current_release,
            'project' => ($p = DB::table('projects')->where('id', $s->project_id)->first()) ? ['id' => (int) $p->id, 'name' => $p->name, 'slug' => $p->slug] : null,
            'preview_of' => $s->parent_site_id !== null ? DB::table('sites')->where('id', $s->parent_site_id)->value('name') : null,
            // php, node, go, static, or dockerfile / image (the container runner). Never a credential: an image's digest and reference are public.
            'runtime' => \App\Lib\Sites\Runtimes::runtime($s)]
            + (\App\Lib\Sites\Runtimes::isContainer($s) ? ['container' => (function () use ($s) {
                $c = \App\Lib\Containers\ContainerApps::state($s);

                return ['image' => $c['image_ref'], 'digest' => $c['digest'], 'dockerfile' => $c['kind'] === 'dockerfile' ? $c['dockerfile'] : null,
                    'container_port' => $c['container_port'], 'memory' => $c['memory'], 'private_registry' => $c['registry'] !== null];
            })()] : []);
    }

    private function operationSummary(stdClass $o): array
    {
        return ['id' => $o->id, 'kind' => $o->kind, 'title' => $o->title, 'status' => $o->status, 'trigger' => $o->trigger,
            'actor' => $o->actor, 'release' => $o->release_id, 'created_at' => Carbon::parse($o->created_at)->toIso8601ZuluString(),
            'url' => rtrim((string) config('app.url'), '/')."/operation?operation={$o->id}"];
    }

    /** @param  array<string, mixed>  $extra */
    private function started(int $id, array $extra = []): JsonResponse
    {
        return response()->json(['operation' => $id, 'follow' => "/api/v1/operations/{$id}",
            'url' => rtrim((string) config('app.url'), '/')."/operation?operation={$id}"] + $extra, 202);
    }
}
