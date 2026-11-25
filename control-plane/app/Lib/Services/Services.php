<?php

declare(strict_types=1);

namespace App\Lib\Services;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Projects;
use App\Lib\Auth\Roles;
use App\Lib\Containers\ContainerApps;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Operations\OperationRunner;
use App\Lib\Providers\Incus\IncusHost;
use App\Lib\Sites\NewApplications;
use App\Lib\Sites\Runtimes;
use App\Lib\Sites\Sites;
use App\Lib\Templates\Definition;
use App\Lib\Templates\Entry;
use App\Lib\Templates\Sources;
use App\Lib\Templates\UpdateCheck;
use App\Lib\Vault\Vault;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * Installing, driving and removing template services (docs/specs/templates.md).
 *
 * Every public action goes through here and checks its role first, in one
 * place: install and remove need `infrastructure` (Manager and up), start,
 * stop and restart need `deploy` (Developer and up). The caller says which
 * role the person has in this team ($role); the command line, with no
 * session, passes the role of the person it acts as. Everything is scoped by
 * the organisation id: a server or service of another team is "not found".
 *
 * Work is queued as operations (OperationRunner); this class validates,
 * records the row, holds the secrets in the vault, and refuses what cannot fit.
 */
final class Services
{
    public const ACTIONS = ['start', 'stop', 'restart'];

    /** A server reports a little under its nominal memory; a "1 GB" server must pass a 1 GB floor. */
    private const MEMORY_TOLERANCE = 0.85;

    /** Free disk needed beyond the service's own storage input: the download and its unpacked copy. */
    private const DISK_HEADROOM = 512 * 1024 * 1024;

    /** Metrics older than this mean the server has not been reporting. */
    private const METRICS_MAX_AGE_MINUTES = 15;

    public function __construct(
        private readonly Sources $sources,
        private readonly OperationRunner $runner,
        private readonly GatewayClient $gateway,
        private readonly Vault $vault,
        private readonly AuditLog $audit,
        private readonly ServiceBackups $backups,
    ) {}

    // ---- the gated actions -------------------------------------------------

    /**
     * Records the service and queues its install. A failed service of the same name, template and server is retried (its secrets are kept).
     *
     * $portsApproved is the person's yes to the template's extra firewall ports (the console's review screen); without it a template
     * that asks for any is refused. $projectId is the project it goes in, or null for Default; it must be this team's.
     *
     * @param  array<string, mixed>  $inputs  by input key; secret inputs included, held in the vault and never stored
     * @return array{service_id: int, operation_id: int}
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException with text for the person installing
     */
    public function install(string $organizationId, string $nodeId, string $templateId, array $inputs, string $actor, string $role, bool $acknowledged = false, bool $dispatch = true, ?int $projectId = null, bool $portsApproved = false, string $trigger = 'cli'): array
    {
        $a = $this->assess($organizationId, $nodeId, $templateId, $inputs, $role, $acknowledged, $portsApproved, $projectId);
        ['node' => $node, 'def' => $def, 'name' => $name, 'effective' => $effective, 'context' => $context, 'project' => $project] = $a;
        $secretKeys = array_column(array_filter($def->inputs, fn ($i) => $i['type'] === 'secret'), 'key');
        $plain = array_diff_key($effective, array_flip($secretKeys));

        return $this->exclusive($organizationId, $name, function () use ($organizationId, $node, $def, $name, $effective, $plain, $secretKeys, $context, $actor, $dispatch, $project, $portsApproved, $trigger) {
            $existing = $this->assessPlacement($organizationId, $node, $def, $name, $effective);

            $entry = $this->sources->find($def->id);
            $row = [
                'node_id' => $node->node_id,
                'template_id' => $def->id,
                'template_source' => $entry !== null && $entry->source === 'builtin' ? 'builtin' : (string) ($entry->tier ?? 'custom'),
                'template_version' => $def->version,
                'runtime' => $def->runtime,
                'inputs' => json_encode($plain),
                'status' => 'installing',
                'data_path' => $context['data'],
                'run_user' => $context['user'],
                'image' => $def->runtime === 'container' ? $context['image'] : null,
                'installed' => json_encode(self::summarize($def, $entry, $context)),
                'previous' => null,
                'error' => null,
                'updated_at' => now(),
            ];
            if ($existing !== null) {
                DB::table('services')->where('id', $existing->id)->update($row);
                $id = (int) $existing->id;
            } else {
                $id = (int) DB::table('services')->insertGetId($row + [
                    'organization_id' => $organizationId,
                    'project_id' => $project,
                    'name' => $name,
                    'created_by' => $actor,
                    'created_at' => now(),
                ]);
            }

            if ($def->extraPorts !== [] && $portsApproved) {
                $this->audit->record($organizationId, Actor::user($actor, $actor), 'service.ports_approved', resourceType: 'service', resourceId: "{$node->node_id}/{$name}",
                    metadata: ['template' => $def->id, 'ports' => array_map(fn ($p) => "{$p['port']}/{$p['proto']}", $def->extraPorts)]);
            }

            // Secrets: the ones we generate are kept across a retry; the ones a person supplied are always the latest.
            foreach ($def->secrets as $s) {
                if (! $this->vault->has($organizationId, self::secretName($id, $s['key']))) {
                    $this->vault->put($organizationId, self::secretName($id, $s['key']), self::generate($s['generate']));
                }
            }
            foreach ($secretKeys as $key) {
                $this->vault->put($organizationId, self::secretName($id, $key), (string) $effective[$key]);
            }

            return ['service_id' => $id, 'operation_id' => $this->runner->queueTemplateServiceInstall($id, $trigger, $actor, $dispatch)];
        });
    }

    /**
     * Everything install() checks, with nothing written: the console's review screen. Refuses exactly what install would.
     *
     * @param  array<string, mixed>  $inputs
     * @return array{def: Definition, node: object, name: string, effective: array<string, string>, context: array<string, mixed>, project: int}
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function check(string $organizationId, string $nodeId, string $templateId, array $inputs, string $role, bool $acknowledged = false, bool $portsApproved = false, ?int $projectId = null): array
    {
        $a = $this->assess($organizationId, $nodeId, $templateId, $inputs, $role, $acknowledged, $portsApproved, $projectId);
        $this->assessPlacement($organizationId, $a['node'], $a['def'], $a['name'], $a['effective']);

        return $a;
    }

    /**
     * The same review for a server that will be made for this service: everything that does not need the server to exist.
     * The checks that read the server (its ports, memory and disk) run when the install does, on the server that was made.
     *
     * @param  array<string, mixed>  $inputs
     * @return array{def: Definition, node: object, name: string, effective: array<string, string>, context: array<string, mixed>, project: int}
     *
     * @throws AuthorizationException
     * @throws InvalidArgumentException
     */
    public function checkOnNewServer(string $organizationId, string $slug, string $templateId, array $inputs, string $role, bool $acknowledged = false, bool $portsApproved = false, ?int $projectId = null): array
    {
        $a = $this->assess($organizationId, "{$slug}.{$organizationId}", $templateId, $inputs, $role, $acknowledged, $portsApproved, $projectId, newServer: true);
        if (DB::table('services')->where('organization_id', $organizationId)->where('name', $a['name'])->exists()) {
            throw new InvalidArgumentException("A service called {$a['name']} already exists in this team.");
        }
        if (($why = Sites::nameInUse($organizationId, $a['name'])) !== null) {
            throw new InvalidArgumentException("{$a['name']} cannot be used: {$why}.");
        }
        $this->checkDomains($organizationId, $a['def'], $a['effective'], null);

        return $a;
    }

    /** The checks that need no lock. @return array{def: Definition, node: object, name: string, effective: array<string, string>, context: array<string, mixed>, project: int} */
    private function assess(string $organizationId, string $nodeId, string $templateId, array $inputs, string $role, bool $acknowledged, bool $portsApproved, ?int $projectId, bool $newServer = false): array
    {
        $this->authorize($role, 'infrastructure');
        $node = $newServer ? (object) ['node_id' => $nodeId, 'role' => null, 'containers_ready_at' => null] : $this->node($organizationId, $nodeId);
        $def = $this->definition($templateId);
        if ($newServer && $def->runtime !== 'native') {
            throw new InvalidArgumentException("{$def->name} runs in a container, which needs a server prepared for containers. Install it on an existing server.");
        }

        // What this version cannot do is refused, not skipped.
        if (IncusHost::reserved($node->role ?? null)) {
            throw new InvalidArgumentException("{$node->node_id} is ".(($node->role ?? null) === 'ingress' ? "the Incus host's IPv4 ingress" : 'an Incus host').': nothing runs on it directly. Install services on a server on the host instead.');
        }
        if ($def->runtime === 'container' && $node->containers_ready_at === null) {
            throw new InvalidArgumentException("{$def->name} runs in a container and {$node->node_id} is not prepared for containers. A Manager can prepare it from the server's Containers page.");
        }
        if ($def->extraPorts !== [] && ! $portsApproved) {
            $ports = implode(', ', array_map(fn ($p) => "{$p['port']}/{$p['proto']} ({$p['label']})", $def->extraPorts));
            throw new InvalidArgumentException("{$def->name} needs the firewall to open {$ports}. Approve these ports first; they are never opened silently.");
        }
        if ($def->acknowledge !== null && ! $acknowledged) {
            throw new InvalidArgumentException("Acknowledge first: {$def->acknowledge}");
        }

        $errors = $def->checkInputs($inputs);
        if ($errors !== []) {
            throw new InvalidArgumentException(implode('; ', array_map(fn ($k, $m) => "{$k} {$m}", array_keys($errors), $errors)));
        }
        $project = Projects::resolve($organizationId, $projectId);
        // A server not made yet has no address: the placeholder stands in for it here, and the real one is used when the install runs.
        $effective = $this->effective($def, $inputs, $newServer ? 'server-address' : NewApplications::publicIp($organizationId, $nodeId));
        $name = $effective['name'];

        // The same planning the operation does, so a bad value is refused here and not halfway through an install.
        $context = $this->plan($def, $name, $effective);

        return ['def' => $def, 'node' => $node, 'name' => $name, 'effective' => $effective, 'context' => $context, 'project' => $project];
    }

    /** The checks that read what exists now (run inside the lock by install). @return ?object the failed service this install retries */
    private function assessPlacement(string $organizationId, object $node, Definition $def, string $name, array $effective): ?object
    {
        $existing = DB::table('services')->where('organization_id', $organizationId)->where('name', $name)->first();
        if ($existing !== null) {
            if ($existing->status !== 'failed' || $existing->node_id !== $node->node_id || $existing->template_id !== $def->id) {
                throw new InvalidArgumentException("A service called {$name} already exists in this team.");
            }
        } elseif (($why = Sites::nameInUse($organizationId, $name)) !== null) {
            throw new InvalidArgumentException("{$name} cannot be used: {$why}.");
        }

        $this->checkDomains($organizationId, $def, $effective, $existing->id ?? null);
        if ($def->runtime === 'native') {
            $this->checkPorts($node->node_id, $def, $existing->id ?? null);
        }
        $this->checkFit($node, $def, $effective);

        return $existing;
    }

    /** start, stop or restart. @throws AuthorizationException @throws InvalidArgumentException */
    public function action(string $organizationId, string $nodeId, string $name, string $action, string $actor, string $role, bool $dispatch = true, string $trigger = 'cli'): int
    {
        $this->authorize($role, 'deploy');
        if (! in_array($action, self::ACTIONS, true)) {
            throw new InvalidArgumentException('The action is start, stop or restart.');
        }
        $service = $this->find($organizationId, $nodeId, $name);

        return $this->exclusive($organizationId, $name, function () use ($service, $action, $actor, $dispatch, $trigger) {
            $this->assertIdle($service);
            if (! in_array($service->status, ['running', 'stopped'], true)) {
                throw new InvalidArgumentException("{$service->name} is {$service->status}; install it again or remove it.");
            }

            return $this->runner->queueTemplateServiceAction((int) $service->id, $action, $trigger, $actor, $dispatch);
        });
    }

    /**
     * Removes the service. What happens to its data is the caller's decision and is never defaulted:
     * $deleteData must be true or false, not null.
     *
     * @throws AuthorizationException @throws InvalidArgumentException
     */
    public function remove(string $organizationId, string $nodeId, string $name, ?bool $deleteData, string $actor, string $role, bool $dispatch = true, string $trigger = 'cli'): int
    {
        $this->authorize($role, 'infrastructure');
        if ($deleteData === null) {
            throw new InvalidArgumentException('Say what happens to the data: keep it, or delete it. It is never decided silently.');
        }
        $service = $this->find($organizationId, $nodeId, $name);

        return $this->exclusive($organizationId, $name, function () use ($service, $deleteData, $actor, $dispatch, $trigger) {
            $this->assertIdle($service);
            DB::table('services')->where('id', $service->id)->update(['status' => 'removing', 'updated_at' => now()]);

            return $this->runner->queueTemplateServiceRemove((int) $service->id, $deleteData, $trigger, $actor, $dispatch);
        });
    }

    /**
     * One generated secret's value, for the person who asked. Audited (the key, never the value); the role is checked again here.
     *
     * @throws AuthorizationException @throws InvalidArgumentException
     */
    public function reveal(string $organizationId, int $serviceId, string $key, Actor $actor, string $role): string
    {
        $this->authorize($role, 'view.secrets');
        $service = DB::table('services')->where('organization_id', $organizationId)->where('id', $serviceId)->first()
            ?? throw new InvalidArgumentException('There is no such service in this team.');
        $def = $this->definition($service->template_id);
        if (! in_array($key, array_column($def->secrets, 'key'), true)) {
            throw new InvalidArgumentException("{$def->name} has no generated secret called {$key}.");
        }
        $value = $this->vault->get($organizationId, self::secretName($serviceId, $key)) ?? throw new InvalidArgumentException('That secret is not stored.');
        $this->audit->record($organizationId, $actor, 'service.secret_revealed', resourceType: 'service', resourceId: "{$service->node_id}/{$service->name}",
            metadata: ['template' => $service->template_id, 'revealed' => $key]);

        return $value;
    }

    /** @return list<object> services of this team, with their endpoints */
    public function list(string $organizationId): array
    {
        $services = DB::table('services')->where('organization_id', $organizationId)->orderBy('name')->get();
        $endpoints = DB::table('service_endpoints')->whereIn('service_id', $services->pluck('id'))->orderBy('port')->get()->groupBy('service_id');

        return $services->map(function ($s) use ($endpoints) {
            $s->endpoints = ($endpoints[$s->id] ?? collect())->values()->all();

            return $s;
        })->all();
    }

    /**
     * What the console and the API show of each service: no secrets, no vault names. Optionally one project's, or one service.
     *
     * @return list<array<string, mixed>>
     */
    public function describe(string $organizationId, ?int $projectId = null, ?int $serviceId = null): array
    {
        $rows = DB::table('services')->where('organization_id', $organizationId)
            ->when($projectId !== null, fn ($q) => $q->where('project_id', $projectId))
            ->when($serviceId !== null, fn ($q) => $q->where('id', $serviceId))
            ->orderBy('name')->get();
        $endpoints = DB::table('service_endpoints')->whereIn('service_id', $rows->pluck('id'))->orderBy('port')->get()->groupBy('service_id');
        $projects = DB::table('projects')->where('organization_id', $organizationId)->get()->keyBy('id');

        return $rows->map(function ($s) use ($endpoints, $projects) {
            $ops = fn () => DB::table('operations')->where('organization_id', $s->organization_id)->where('meta->service_id', (int) $s->id);
            $active = $ops()->whereIn('status', ['queued', 'running'])->orderByDesc('id')->first(['id', 'kind', 'status']);
            $last = $ops()->orderByDesc('id')->first(['id', 'kind', 'status']);
            $m = DB::table('node_metrics')->where('node_id', $s->node_id)->orderByDesc('collected_at')->first();
            $p = $projects[$s->project_id] ?? null;

            return [
                'id' => (int) $s->id,
                'name' => $s->name,
                'template' => $s->template_id,
                'version' => (int) $s->template_version,
                'source' => $s->template_source,
                'server' => $s->node_id,
                'project' => $p === null ? null : ['id' => (int) $p->id, 'name' => $p->name, 'slug' => $p->slug],
                'status' => $s->status,
                'error' => $s->error,
                'runtime' => $s->runtime,
                'image' => $s->image,
                'data_path' => $s->data_path,
                'created_at' => $s->created_at,
                'update' => ($u = $this->updateAvailable($s)) === null ? null : ['version' => $u['version'], 'allowed' => $u['allowed'], 'confirm' => $u['confirm'], 'changes' => $u['changes']],
                'endpoints' => ($endpoints[$s->id] ?? collect())->map(fn ($e) => ['port' => (int) $e->port, 'domain' => $e->domain, 'tls' => (bool) $e->tls,
                    'listen' => '127.0.0.1:'.$e->port] + ($e->container_port !== null ? ['container_port' => (int) $e->container_port] : []))->values()->all(),
                'operation' => $active === null ? null : ['id' => (int) $active->id, 'kind' => $active->kind, 'status' => $active->status],
                'last_operation' => $last === null ? null : ['id' => (int) $last->id, 'kind' => $last->kind, 'status' => $last->status],
                'memory_used' => $m && $m->mem_total > 0 ? (int) round(100 * ($m->mem_total - $m->mem_available) / $m->mem_total) : null,
                'disk_free' => $m ? self::human((int) $m->disk_free) : null,
            ];
        })->all();
    }

    // ---- updates and backups (step 5) ---------------------------------------------------------------------------

    /**
     * What a service's definition looks like once installed: enough to say what an update changes after the source has moved
     * on to a newer file. Never a value; the environment is a hash of the template's own text (placeholders unfilled).
     *
     * @param  array<string, mixed>  $context  the install's context (data folder, user)
     * @return array<string, mixed>
     */
    public static function summarize(Definition $def, ?Entry $entry, array $context): array
    {
        return [
            'version' => $def->version,
            'publisher' => $entry->publisher ?? 'Cloud Manager',
            'download_host' => $def->downloadHost(),
            'image_repo' => $def->imageRepository(),
            'sha256' => $def->runs['sha256'] ?? null,
            'digest' => isset($def->runs['image']) ? substr((string) strstr($def->runs['image'], '@'), 1) : null,
            'extra_ports' => array_map(fn ($p) => "{$p['port']}/{$p['proto']}", $def->extraPorts),
            'acknowledge' => $def->acknowledge,
            'inputs' => array_column($def->inputs, 'key'),
            'ports' => array_map('intval', array_keys($def->runs['ports'])),
            'env' => md5((string) json_encode($def->runs['env'] ?? [])),
            'health' => $def->runs['health'] ?? [],
            'backup_stop' => (bool) ($def->backup['stop'] ?? false),
            'setup' => $def->runs['setup'] ?? null,
        ];
    }

    /**
     * The summary stored at install or last update. A row without one (made before updates existed) gets what the row itself
     * knows; the download host is then unknown, which makes an update ask for confirmation.
     *
     * @return array<string, mixed>
     */
    public static function installedSummary(object $service): array
    {
        $stored = json_decode((string) ($service->installed ?? ''), true);
        if (is_array($stored)) {
            return $stored;
        }
        $repo = $service->image === null ? null : explode('@', (string) $service->image)[0];

        return ['version' => (int) $service->template_version, 'publisher' => $service->template_source === 'builtin' ? 'Cloud Manager' : null, 'download_host' => null,
            'image_repo' => $repo, 'sha256' => null, 'digest' => $service->image === null ? null : substr((string) strstr((string) $service->image, '@'), 1),
            'extra_ports' => [], 'acknowledge' => null, 'inputs' => array_keys(json_decode((string) $service->inputs, true) ?: []), 'ports' => null, 'env' => null,
            'health' => [], 'backup_stop' => false, 'setup' => null];
    }

    /**
     * Is there a newer version of this service's template, and what would it change? Reads only what Sources already has
     * (the loaded catalogue and the cache): refreshing the catalogue is a separate action. Null when there is nothing newer.
     *
     * 'allowed' is false for what UpdateCheck refuses (a new publisher) and for what an update cannot do yet; 'refused' says
     * why. 'confirm' is true when the download host or image repository changes. 'reapprove' names what the person must
     * approve again: the extra ports, the acknowledgement.
     *
     * @return array{version: int, from: int, allowed: bool, refused: ?string, confirm: bool, changes: list<string>, new_inputs: list<array<string, mixed>>, reapprove: array{ports: list<string>, acknowledge: ?string}, def: Definition, installed: array<string, mixed>}|null
     */
    public function updateAvailable(object $service): ?array
    {
        $entry = $this->sources->load()->find($service->template_id);
        $from = (int) $service->template_version;
        if ($entry === null || $entry->version <= $from) {
            return null;
        }
        try {
            $def = $this->sources->cachedDefinition($entry);
        } catch (Throwable) {
            return null;
        }
        if ($def === null) {
            return null;
        }
        $in = self::installedSummary($service);
        $result = fn (bool $allowed, ?string $refused, bool $confirm, array $changes, array $new, array $reapprove) => [
            'version' => $def->version, 'from' => $from, 'allowed' => $allowed, 'refused' => $refused, 'confirm' => $confirm, 'changes' => $changes,
            'new_inputs' => $new, 'reapprove' => $reapprove, 'def' => $def, 'installed' => $in,
        ];
        $none = ['ports' => [], 'acknowledge' => null];

        $cmp = UpdateCheck::compare(['version' => $from, 'publisher' => (string) ($in['publisher'] ?? $entry->publisher), 'download_host' => $in['download_host'] ?? null, 'image_repo' => $in['image_repo'] ?? null], $def, $entry->publisher);
        if (! $cmp['allowed']) {
            return $result(false, ucfirst((string) $cmp['refused']).'.', false, [], [], $none);
        }
        $changes = $cmp['changes'];

        if (($in['sha256'] ?? null) !== ($def->runs['sha256'] ?? null) && isset($def->runs['sha256'])) {
            $changes[] = 'the download checksum (sha256) changed to '.substr($def->runs['sha256'], 0, 16).'...';
        }
        $digest = isset($def->runs['image']) ? substr((string) strstr($def->runs['image'], '@'), 1) : null;
        if ($digest !== null && ($in['digest'] ?? null) !== $digest) {
            $changes[] = 'the image digest changed to '.substr($digest, 0, 23).'...';
        }
        if (($in['env'] ?? null) !== md5((string) json_encode($def->runs['env'] ?? []))) {
            $changes[] = 'the environment the program is started with changed (secrets that exist are kept; none is regenerated)';
        }

        $new = array_values(array_filter($def->inputs, fn ($i) => ! in_array($i['key'], $in['inputs'] ?? [], true)));
        foreach ($new as $i) {
            $changes[] = 'a new input: '.$i['label'].(array_key_exists('default', $i) ? ' (has a default)' : ' (required)');
        }

        $ports = array_map(fn ($p) => "{$p['port']}/{$p['proto']}", $def->extraPorts);
        $newPorts = array_values(array_diff($ports, $in['extra_ports'] ?? []));
        if ($newPorts !== [] || count($ports) !== count($in['extra_ports'] ?? [])) {
            $changes[] = 'the firewall ports it asks for changed: '.($ports === [] ? 'none' : implode(', ', $ports)).' (they must be approved again)';
        }
        $ack = $def->acknowledge !== null && $def->acknowledge !== ($in['acknowledge'] ?? null) ? $def->acknowledge : null;
        if ($ack !== null) {
            $changes[] = 'it now asks for an acknowledgement (it must be accepted again)';
        }
        $reapprove = ['ports' => $newPorts, 'acknowledge' => $ack];

        $blocked = null;
        if ($def->runtime !== $service->runtime) {
            $blocked = 'This version changes how the service runs ('.$service->runtime.' to '.$def->runtime.'); that is a new install, not an update.';
        } elseif (($in['ports'] ?? null) !== null && array_map('intval', array_keys($def->runs['ports'])) !== $in['ports']) {
            $blocked = 'This version changes the addresses the service serves; an update does not change addresses yet. Install it again beside this one.';
        } elseif ($service->runtime === 'native' && SetupActions::writesRelease((string) ($def->runs['setup'] ?? ''))) {
            $blocked = 'This service keeps its own state inside its release folder, so a new release would lose it; updating it is not supported yet.';
        }

        return $result($blocked === null, $blocked, $cmp['confirm'], $changes, $new, $reapprove);
    }

    /**
     * Queues the update of a running service to the template's newer version. $newInputs are the values for the inputs the new
     * version adds (a secret one goes to the vault now). $confirmed is the person's yes when the download host or image repository
     * changes, $portsApproved and $acknowledged their yes to the new firewall ports and acknowledgement.
     *
     * @param  array<string, mixed>  $newInputs
     *
     * @throws AuthorizationException @throws InvalidArgumentException
     */
    public function update(string $organizationId, string $nodeId, string $name, array $newInputs, string $actor, string $role, bool $confirmed = false, bool $portsApproved = false, bool $acknowledged = false, bool $dispatch = true, string $trigger = 'cli'): int
    {
        $this->authorize($role, 'infrastructure');
        $service = $this->find($organizationId, $nodeId, $name);

        return $this->exclusive($organizationId, $name, function () use ($service, $newInputs, $actor, $confirmed, $portsApproved, $acknowledged, $dispatch, $trigger) {
            $this->assertIdle($service);
            if ($service->status !== 'running') {
                throw new InvalidArgumentException("{$service->name} is {$service->status}; only a running service is updated. Start it first.");
            }
            $u = $this->updateAvailable($service) ?? throw new InvalidArgumentException("There is no newer version of {$service->template_id} for {$service->name}.");
            if (! $u['allowed']) {
                throw new InvalidArgumentException((string) $u['refused']);
            }
            $def = $u['def'];
            if ($u['confirm'] && ! $confirmed) {
                throw new InvalidArgumentException('This update changes where the program comes from ('.implode('; ', array_filter($u['changes'], fn ($c) => str_contains($c, 'host') || str_contains($c, 'repository'))).'). Confirm it explicitly.');
            }
            if ($u['reapprove']['ports'] !== [] && ! $portsApproved) {
                throw new InvalidArgumentException('This update asks for firewall ports ('.implode(', ', $u['reapprove']['ports']).'). Approve them first; they are never opened silently.');
            }
            if ($u['reapprove']['acknowledge'] !== null && ! $acknowledged) {
                throw new InvalidArgumentException('Acknowledge first: '.$u['reapprove']['acknowledge']);
            }

            $newKeys = array_column($u['new_inputs'], 'key');
            foreach (array_keys($newInputs) as $k) {
                if (! in_array($k, $newKeys, true)) {
                    throw new InvalidArgumentException("{$k} is not a new input of this version; an update does not change the values given at install.");
                }
            }
            $secretKeys = array_column(array_filter($def->inputs, fn ($i) => $i['type'] === 'secret'), 'key');
            $full = (json_decode((string) $service->inputs, true) ?: []) + $newInputs;
            foreach ($secretKeys as $k) {
                $full[$k] ??= (string) $this->vault->get($service->organization_id, self::secretName((int) $service->id, $k));
            }
            $errors = $def->checkInputs($full);
            if ($errors !== []) {
                throw new InvalidArgumentException(implode('; ', array_map(fn ($k, $m) => "{$k} {$m}", array_keys($errors), $errors)));
            }
            $effective = $this->effective($def, $full, NewApplications::publicIp($service->organization_id, $service->node_id));
            $context = $this->plan($def, $service->name, $effective);
            if ($context['data'] !== $service->data_path || ($service->runtime === 'native' && $context['user'] !== $service->run_user)) {
                throw new InvalidArgumentException('This version moves the data folder or changes the user the service runs as; an update does not do that.');
            }
            $node = $this->node($service->organization_id, $service->node_id);
            if ($service->runtime === 'container' && $node->containers_ready_at === null) {
                throw new InvalidArgumentException("{$service->node_id} is no longer prepared for containers.");
            }
            $this->checkFit($node, $def, []);

            foreach ($secretKeys as $k) {
                if (array_key_exists($k, $newInputs)) {
                    $this->vault->put($service->organization_id, self::secretName((int) $service->id, $k), (string) $newInputs[$k]);
                }
            }
            if ($u['reapprove']['ports'] !== []) {
                $this->audit->record($service->organization_id, Actor::user($actor, $actor), 'service.ports_approved', resourceType: 'service', resourceId: "{$service->node_id}/{$service->name}",
                    metadata: ['template' => $def->id, 'ports' => $u['reapprove']['ports'], 'at' => 'update']);
            }

            return $this->runner->queueTemplateServiceUpdate((int) $service->id, $def->version, array_diff_key($newInputs, array_flip($secretKeys)), $u['confirm'], $trigger, $actor, $dispatch);
        });
    }

    /**
     * Queues a backup of the service's data. $kind is manual (the console, the API, service:backup), scheduled (the nightly
     * job) or pre_update. Needs `infrastructure`: stopping the service around it is what a template may ask for, and the
     * archive holds everything the service stores.
     *
     * @throws AuthorizationException @throws InvalidArgumentException
     */
    public function backup(string $organizationId, string $nodeId, string $name, string $kind, string $actor, string $role, bool $dispatch = true, string $trigger = 'cli'): int
    {
        $this->authorize($role, 'infrastructure');
        if (! in_array($kind, ServiceBackups::KINDS, true)) {
            throw new InvalidArgumentException('The kind of a backup is manual, scheduled or pre_update.');
        }
        $service = $this->find($organizationId, $nodeId, $name);

        return $this->exclusive($organizationId, $name, function () use ($service, $kind, $actor, $dispatch, $trigger) {
            $this->assertIdle($service);
            if (! in_array($service->status, ['running', 'stopped'], true)) {
                throw new InvalidArgumentException("{$service->name} is {$service->status}; there is no data to back up yet.");
            }

            return $this->runner->queueTemplateServiceBackup((int) $service->id, $kind, $trigger, $actor, $dispatch);
        });
    }

    /**
     * Queues putting a backup back: a safety backup of what is there now, stop, restore, start, health. The person types the
     * service's name to confirm; a backup that is not this service's, or a name that is not a backup's, is refused.
     *
     * @throws AuthorizationException @throws InvalidArgumentException
     */
    public function restore(string $organizationId, string $nodeId, string $name, int $backupId, string $confirmName, string $actor, string $role, bool $dispatch = true, string $trigger = 'cli'): int
    {
        $this->authorize($role, 'infrastructure');
        $service = $this->find($organizationId, $nodeId, $name);
        if ($confirmName !== $service->name) {
            throw new InvalidArgumentException("Type {$service->name} to confirm the restore: what is there now is replaced by the backup.");
        }
        $backup = DB::table('service_backups')->where('organization_id', $organizationId)->where('service_id', $service->id)->where('id', $backupId)->first()
            ?? throw new InvalidArgumentException("There is no backup {$backupId} of {$service->name}.");
        if (! ServiceBackups::validName($backup->name)) {
            throw new InvalidArgumentException('That backup has a name that cannot be restored.');
        }

        return $this->exclusive($organizationId, $name, function () use ($service, $backup, $actor, $dispatch, $trigger) {
            $this->assertIdle($service);
            if (! in_array($service->status, ['running', 'stopped'], true)) {
                throw new InvalidArgumentException("{$service->name} is {$service->status}; install it again or remove it.");
            }

            return $this->runner->queueTemplateServiceRestore((int) $service->id, (int) $backup->id, $trigger, $actor, $dispatch);
        });
    }

    /** @return list<array<string, mixed>> this service's backups, newest first, for the console and the API */
    public function backups(string $organizationId, int $serviceId): array
    {
        return array_map(ServiceBackups::describe(...), $this->backups->list($organizationId, $serviceId));
    }

    // ---- placement checks (also run by the operation) ------------------------

    /**
     * Every input's effective value: what was supplied, else the default. A domain input marked generate: sslip that is left
     * blank becomes <service>-<input>.<server address>.sslip.io (the input key without "_host"), which needs no DNS record.
     *
     * @param  ?string  $address  the server's public address, for those generated names
     * @return array<string, string>
     */
    public function effective(Definition $def, array $inputs, ?string $address = null): array
    {
        $out = [];
        foreach ($def->inputs as $input) {
            $v = $inputs[$input['key']] ?? null;
            if (($v === null || $v === '') && ($input['generate'] ?? null) === 'sslip') {
                $name = (string) ($out['name'] ?? $inputs['name'] ?? '');
                if ($address === null || $name === '') {
                    throw new InvalidArgumentException("{$input['key']} was left blank for a free name, but this server's public address is not known yet. Type a domain instead.");
                }
                $v = NewApplications::sslipDomain($name.'-'.preg_replace('/_host$/', '', $input['key']), $address);
            }
            $out[$input['key']] = Definition::coerce($input, $v === null || $v === '' ? $input['default'] : $v);
        }

        return $out;
    }

    /**
     * Builds the recipes with placeholder secrets, which throws for anything the definition cannot do with these values.
     *
     * @param  array<string, string>  $effective
     * @return array<string, mixed> the context
     */
    public function plan(Definition $def, string $name, array $effective): array
    {
        try {
            $dummy = array_combine(array_keys($def->runs['ports']), array_slice(range(ContainerRecipes::PORT_MIN, ContainerRecipes::PORT_MAX), 0, count($def->runs['ports'])));
            $c = ServiceRecipes::context($def, $name, $effective, array_column(array_map(fn ($s) => ['k' => $s['key'], 'v' => 'x'], $def->secrets), 'v', 'k'), $dummy);
            if ($def->runtime === 'container') {
                ContainerRecipes::prepare($c);
                ContainerRecipes::run($c);
            } else {
                ServiceRecipes::prepare($c);
                ServiceRecipes::run($c);
            }
        } catch (InvalidArgumentException $e) {
            throw new InvalidArgumentException($e->getMessage());
        }

        return $c;
    }

    /** A domain belongs to one thing: not an application's, not another service's. */
    public function checkDomains(string $organizationId, Definition $def, array $effective, ?int $exceptServiceId): void
    {
        $domains = [];
        foreach ($def->runs['ports'] as $port => $_) {
            $input = preg_replace('/^\{\{\s*inputs\.(\w+)\s*\}\}$/', '$1', $def->runs['ports'][$port]);
            $domain = $effective[$input];
            if (in_array($domain, $domains, true)) {
                throw new InvalidArgumentException("{$domain} is used for two addresses of this service; give each its own.");
            }
            $domains[] = $domain;
            if (DB::table('sites')->where('organization_id', $organizationId)->where('domain', $domain)->exists()) {
                throw new InvalidArgumentException("{$domain} is already the address of an application in this team.");
            }
            $taken = DB::table('service_endpoints')->join('services', 'services.id', '=', 'service_endpoints.service_id')
                ->where('services.organization_id', $organizationId)->where('service_endpoints.domain', $domain)
                ->when($exceptServiceId !== null, fn ($q) => $q->where('services.id', '!=', $exceptServiceId))->exists();
            if ($taken) {
                throw new InvalidArgumentException("{$domain} is already the address of another service in this team.");
            }
        }
    }

    /**
     * The definition's ports are fixed (the program is told them on its command line), so two services
     * cannot share a server if they want the same one. Refused with a clear message, not reallocated.
     */
    public function checkPorts(string $nodeId, Definition $def, ?int $exceptServiceId): void
    {
        $used = $this->usedPorts($nodeId, $exceptServiceId);
        foreach (array_keys($def->runs['ports']) as $port) {
            if ($port < 1024 || Runtimes::reservedPort($port)) {
                throw new InvalidArgumentException("{$def->name} wants port {$port}, which Unharbored does not allow for services (use 1024 to 65535, not 20000 to 23999, which it keeps for itself).");
            }
            if (isset($used[$port])) {
                throw new InvalidArgumentException("Port {$port} on this server is already used by {$used[$port]}; {$def->name} needs it. Install it on another server.");
            }
        }
    }

    /**
     * One host port for each port a container publishes, from 23000 to 23999: the lowest free one on the node (not another
     * service's, an application's or a background process's). The endpoint rows are written here, under a lock, so two installs
     * cannot be given the same port; a retry gets the ports it already had.
     *
     * @param  array<int, string>  $domains  container port => domain
     * @return array<int, int> container port => host port
     *
     * @throws InvalidArgumentException when the range is full
     */
    public function allocateHostPorts(string $nodeId, int $serviceId, array $domains): array
    {
        $busy = new InvalidArgumentException("Could not reserve ports on {$nodeId}; another install is doing it. Try again.");
        try {
            return Cache::lock("service-ports:{$nodeId}", 15)->block(10, function () use ($nodeId, $serviceId, $domains) {
                $used = $this->usedPorts($nodeId, $serviceId);
                $mine = DB::table('service_endpoints')->where('service_id', $serviceId)->whereNotNull('container_port')->pluck('port', 'container_port');
                $out = [];
                foreach ($domains as $container => $domain) {
                    $host = $mine[$container] ?? null;
                    if ($host === null) {
                        $host = self::lowestFreePort($used, $nodeId);
                        DB::table('service_endpoints')->updateOrInsert(['service_id' => $serviceId, 'port' => $host],
                            ['node_id' => $nodeId, 'container_port' => $container, 'domain' => $domain, 'tls' => false, 'updated_at' => now(), 'created_at' => now()]);
                    }
                    $used[(int) $host] = 'this service';
                    $out[(int) $container] = (int) $host;
                }

                return $out;
            });
        } catch (LockTimeoutException) {
            throw $busy;
        }
    }

    /** The lowest port of the container band (23000 to 23999) not in $used. @param array<int, string> $used @throws InvalidArgumentException when the band is full */
    public static function lowestFreePort(array $used, string $nodeId): int
    {
        for ($p = ContainerRecipes::PORT_MIN; $p <= ContainerRecipes::PORT_MAX; $p++) {
            if (! isset($used[$p])) {
                return $p;
            }
        }

        throw new InvalidArgumentException('Every port from '.ContainerRecipes::PORT_MIN.' to '.ContainerRecipes::PORT_MAX." on {$nodeId} is taken.");
    }

    /**
     * One host port for a container application, from the same band and under the same lock as a service's. $record writes the
     * port (into the site's state) before the lock is released, so no service, application or background process can be given it
     * in between. @param  \Closure(int): void  $record
     */
    public function reserveHostPort(string $nodeId, \Closure $record): int
    {
        try {
            return Cache::lock("service-ports:{$nodeId}", 15)->block(10, function () use ($nodeId, $record) {
                $port = self::lowestFreePort($this->usedPorts($nodeId), $nodeId);
                $record($port);

                return $port;
            });
        } catch (LockTimeoutException) {
            throw new InvalidArgumentException("Could not reserve a port on {$nodeId}; another operation is doing it. Try again.");
        }
    }

    /**
     * The extra ports (SSH for Git, say) of the services on a node, for its firewall. A service cannot be installed without
     * the person approving them, so every service that exists has been approved.
     *
     * @return list<array{port: int, proto: string, label: string}>
     */
    public function extraPortsOn(string $nodeId): array
    {
        $out = [];
        foreach (DB::table('services')->where('node_id', $nodeId)->whereNotIn('status', ['removing'])->get(['template_id', 'template_version']) as $s) {
            try {
                $def = $this->definition($s->template_id);
            } catch (Throwable) {
                continue;
            }
            foreach ($def->extraPorts as $p) {
                $out[$p['port'].'/'.$p['proto']] = $p;
            }
        }

        return array_values($out);
    }

    /** @return array<int, string> port => who has it */
    public function usedPorts(string $nodeId, ?int $exceptServiceId = null): array
    {
        $used = [];
        $rows = DB::table('service_endpoints')->join('services', 'services.id', '=', 'service_endpoints.service_id')
            ->where('service_endpoints.node_id', $nodeId)
            ->when($exceptServiceId !== null, fn ($q) => $q->where('services.id', '!=', $exceptServiceId))
            ->get(['service_endpoints.port', 'services.name']);
        foreach ($rows as $r) {
            $used[(int) $r->port] = "the service {$r->name}";
        }
        foreach (DB::table('sites')->where('node_id', $nodeId)->get() as $site) {
            if (Runtimes::serves($site)) {
                $used[(int) Runtimes::config($site)['port']] ??= "the application {$site->name}";
            }
            foreach (Sites::daemons($site) as $d) {
                if (isset($d['port'])) {
                    $used[(int) $d['port']] ??= "a background process of {$site->name}";
                }
            }
            foreach (ContainerApps::ports($site) as $port) {
                $used[$port] ??= "the application {$site->name}";
            }
        }

        return $used;
    }

    /** The server is online, reports its memory and disk, and has enough of both. @param array<string, string> $effective */
    public function checkFit(object $node, Definition $def, array $effective): void
    {
        try {
            $online = $this->gateway->isConnected($node->node_id);
        } catch (Throwable) {
            $online = false;
        }
        if (! $online) {
            throw new InvalidArgumentException("The server {$node->node_id} is not online. Wait for it to reconnect.");
        }

        $m = DB::table('node_metrics')->where('node_id', $node->node_id)->orderByDesc('collected_at')->first();
        if ($m === null || now()->diffInMinutes($m->collected_at, true) > self::METRICS_MAX_AGE_MINUTES) {
            throw new InvalidArgumentException("The server {$node->node_id} has not reported its memory and disk recently, so whether {$def->name} fits cannot be checked.");
        }

        $floor = $def->memoryBytes();
        if ((int) $m->mem_total < $floor * self::MEMORY_TOLERANCE) {
            throw new InvalidArgumentException(sprintf('%s needs at least %s of memory and this server has %s. Use a bigger server.', $def->name, $def->memory, self::human((int) $m->mem_total)));
        }
        $storage = isset($effective['storage']) ? (int) Definition::parseSize($effective['storage']) : 0;
        $need = $storage + self::DISK_HEADROOM;
        if ((int) $m->disk_free < $need) {
            throw new InvalidArgumentException(sprintf('%s needs about %s of free disk%s and this server has %s free. Pick a smaller storage size or a server with more disk.', $def->name, self::human($need), $storage > 0 ? " ({$effective['storage']} of storage and room to unpack)" : '', self::human((int) $m->disk_free)));
        }
    }

    // ---- helpers -----------------------------------------------------------

    public static function secretName(int $serviceId, string $key): string
    {
        return "service:{$serviceId}:{$key}";
    }

    /** word: a lower case access-key style name; password: a long random string. Both are safe in an environment file. */
    public static function generate(string $kind): string
    {
        return $kind === 'word' ? strtolower(Str::random(16)) : Str::random(40);
    }

    public function definition(string $templateId): Definition
    {
        $entry = $this->sources->load()->find($templateId) ?? throw new InvalidArgumentException("There is no template called {$templateId}.");

        return $this->sources->definition($entry);
    }

    public function sourcesEntry(string $templateId): ?Entry
    {
        return $this->sources->load()->find($templateId);
    }

    public function find(string $organizationId, string $nodeId, string $name): object
    {
        return DB::table('services')->where('organization_id', $organizationId)->where('node_id', $nodeId)->where('name', $name)->first()
            ?? throw new InvalidArgumentException("There is no service {$name} on {$nodeId} in this team.");
    }

    private function node(string $organizationId, string $nodeId): object
    {
        return DB::table('nodes')->where('organization_id', $organizationId)->where('node_id', $nodeId)->first()
            ?? throw new InvalidArgumentException("There is no server {$nodeId} in this team.");
    }

    private function authorize(string $role, string $permission): void
    {
        if (! Roles::can($role, $permission)) {
            throw new AuthorizationException("Your role cannot do this; it needs the {$permission} permission.");
        }
    }

    private function assertIdle(object $service): void
    {
        if ($service->status === 'installing' || $service->status === 'removing' || DB::table('operations')->where('meta->service_id', (int) $service->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw new InvalidArgumentException("Another operation is already running for {$service->name}. Wait for it to finish.");
        }
    }

    /** One operation per service at a time: a lock around the check and the queueing. */
    private function exclusive(string $organizationId, string $name, \Closure $do): mixed
    {
        $busy = new InvalidArgumentException("Another operation is already running for {$name}. Wait for it to finish.");
        try {
            return Cache::lock("service-operation:{$organizationId}:{$name}", 15)->block(5, $do);
        } catch (LockTimeoutException) {
            throw $busy;
        }
    }

    private static function human(int $bytes): string
    {
        return $bytes >= 1024 ** 3 ? round($bytes / 1024 ** 3, 1).' GB' : round($bytes / 1024 ** 2).' MB';
    }
}
