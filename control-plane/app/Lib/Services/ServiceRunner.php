<?php

declare(strict_types=1);

namespace App\Lib\Services;

use App\Lib\Audit\Actor;
use App\Lib\Audit\AuditLog;
use App\Lib\Providers\Incus\Ingress;
use App\Lib\Servers\Firewall;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use App\Lib\Templates\Definition;
use App\Lib\Vault\Vault;
use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * The bodies of the three service operations (template_service_install,
 * template_service_action, template_service_remove). OperationRunner owns the
 * queueing, the stages and the outcome; this class does the work, applying
 * recipes through the $apply closure it is handed (OperationRunner::applyRecipe).
 *
 * Nothing here logs or audits a secret value. Secrets are read from the vault
 * into the environment file recipe, which is a `sensitive` step.
 */
final class ServiceRunner
{
    public function __construct(
        private readonly Services $services,
        private readonly Sites $sites,
        private readonly Deployer $deployer,
        private readonly Vault $vault,
        private readonly AuditLog $audit,
        private readonly ServiceBackups $backups,
    ) {}

    /**
     * check, fetch, prepare, run, proxy, health. A failure stops the unit, keeps the data folder
     * and marks the service failed with the reason; the exception then fails the operation.
     *
     * @param  Closure(array<string, mixed>, int): void  $apply
     */
    public function install(object $operation, Closure $stage, Closure $log, Closure $apply): void
    {
        $service = $this->service($operation);
        $def = $this->services->definition($service->template_id);
        if ($def->version !== (int) $service->template_version) {
            throw new RuntimeException("The template {$def->id} is now at version {$def->version}; this install was for version {$service->template_version}. Install again.");
        }
        $effective = $this->inputs($service, $def);
        $secrets = $this->secrets($service, $def);
        $container = $def->runtime === 'container';
        $r = $container ? ContainerRecipes::class : ServiceRecipes::class;
        $c = ServiceRecipes::context($def, $service->name, $effective, $secrets);

        try {
            $stage('check');
            $node = DB::table('nodes')->where('node_id', $service->node_id)->first() ?? throw new RuntimeException("No server {$service->node_id}.");
            $this->services->checkFit($node, $def, $effective);
            if ($container) {
                if ($node->containers_ready_at === null) {
                    throw new RuntimeException("{$service->node_id} is not prepared for containers.");
                }
                $domains = array_map(fn ($p) => $effective[preg_replace('/^\{\{\s*inputs\.(\w+)\s*\}\}$/', '$1', $p)], $def->runs['ports']);
                $c = ServiceRecipes::context($def, $service->name, $effective, $secrets, $this->services->allocateHostPorts($service->node_id, (int) $service->id, $domains));
                $log('ports', implode(', ', array_map(fn ($h, $cp) => "127.0.0.1:{$h} -> container port {$cp}", $c['host_ports'], array_keys($c['host_ports']))));
            } else {
                $this->services->checkPorts($service->node_id, $def, (int) $service->id);
            }
            $apply($r::check($c), 120);

            $stage('fetch');
            $apply($r::fetch($c), 1200);
            $log($container ? 'image' : 'download', $container
                ? $c['repository'].' pulled and its digest matched '.substr($c['digest'], 0, 19).'...'
                : basename($c['download']).' downloaded and its sha256 matched '.substr($c['sha256'], 0, 12).'...');

            $stage('prepare');
            $apply($r::prepare($c), 300);
            $log($container ? 'volume' : 'unit', $container ? 'environment file written (0600), volumes '.implode(', ', array_column($c['volumes'], 'name')) : "{$c['unit']}.service written, running as {$c['user']}");

            $stage('run');
            $apply($r::run($c), 300);
            $log($container ? 'container' : 'unit', $c['setup'] ? "setup action {$c['setup']} done; started" : 'started');

            $stage('proxy');
            foreach ($c['ports'] as $port => $domain) {
                $this->endpoint($service, $c, (int) ($container ? $c['host_ports'][$port] : $port), (string) $domain, $apply, $log);
            }
            if ($c['ports'] === []) {
                $log('proxy', 'this service has no address to serve');
            }

            $stage('health');
            if (($c['health'] ?? []) === []) {
                $log('health', $container ? 'the template has no health check; the container is running' : 'the template has no health check; the unit is running');
            } else {
                $apply($r::health($c), 120);
                $log('health', 'answers');
            }
        } catch (Throwable $e) {
            // Stop it (a container is removed, its volumes kept), keep the data, say why.
            try {
                $apply($r::stop($service->name), 60);
            } catch (Throwable) {
            }
            DB::table('services')->where('id', $service->id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 1000), 'updated_at' => now()]);

            throw $e;
        }

        DB::table('services')->where('id', $service->id)->update(['status' => 'running', 'error' => null, 'updated_at' => now()]);
        $this->audit($operation, $service, $def, 'service.installed', ['endpoints' => array_values($c['ports'])]);
        $this->openExtraPorts($service->node_id, $def, $apply, $log);
    }

    /**
     * The nginx vhost for one address, then its certificate (the existing certbot recipe, HTTP-01 over port 80), then the HTTPS vhost.
     *
     * @param  Closure(array<string, mixed>, int): void  $apply
     */
    private function endpoint(object $service, array $c, int $port, string $domain, Closure $apply, Closure $log): void
    {
        DB::table('service_endpoints')->updateOrInsert(['service_id' => $service->id, 'port' => $port],
            ['node_id' => $service->node_id, 'domain' => $domain, 'tls' => false, 'updated_at' => now(), 'created_at' => now()]);

        $ingress = Ingress::sourceForNode((string) $service->organization_id, (string) $service->node_id);
        $apply(ServiceRecipes::vhost($c, $port, $domain, false, $ingress), 120);
        // On an Incus host the name must route through the ingress before Let's Encrypt checks it.
        $this->deployer->syncIngress((object) ['organization_id' => $service->organization_id, 'node_id' => $service->node_id]);
        $site = (object) ['name' => "service-{$service->name}", 'domain' => $domain];
        $apply($this->sites->clearStaleCertificateRecipe($domain), 60);
        $apply($this->sites->certificateRecipe($site), 180);
        DB::table('service_endpoints')->where('service_id', $service->id)->where('port', $port)->update(['tls' => true, 'updated_at' => now()]);
        $apply(ServiceRecipes::vhost($c, $port, $domain, true, $ingress), 120);
        $log('proxy', "https://{$domain}/ serves 127.0.0.1:{$port}");
    }

    /** @param Closure(array<string, mixed>, int): void $apply */
    public function action(object $operation, Closure $stage, Closure $log, Closure $apply): void
    {
        $service = $this->service($operation);
        $action = (string) ($this->meta($operation)['action'] ?? '');

        $stage('apply');
        $apply(($service->runtime === 'container' ? ContainerRecipes::class : ServiceRecipes::class)::action($service->name, $action), 120);
        DB::table('services')->where('id', $service->id)->update(['status' => $action === 'stop' ? 'stopped' : 'running', 'updated_at' => now()]);
        $log('service', "{$service->name}: {$action} done");
        $this->audit($operation, $service, $this->definitionOrNull($service), 'service.action', ['action' => $action]);
    }

    /** stop, endpoints, data. The data question was answered by the caller; it is in the operation's meta. @param Closure(array<string, mixed>, int): void $apply */
    public function remove(object $operation, Closure $stage, Closure $log, Closure $apply): void
    {
        $service = $this->service($operation);
        $deleteData = (bool) ($this->meta($operation)['delete_data'] ?? throw new RuntimeException('The operation does not say what to do with the data.'));
        $endpoints = DB::table('service_endpoints')->where('service_id', $service->id)->orderBy('port')->get()->map(fn ($e) => ['port' => (int) $e->port, 'domain' => (string) $e->domain])->all();

        $container = $service->runtime === 'container';
        $stage('stop');
        $apply(($container ? ContainerRecipes::class : ServiceRecipes::class)::removeUnit($service->name), 120);

        $stage('endpoints');
        $apply(ServiceRecipes::removeEndpoints($service->name, $endpoints), 120);
        $log('proxy', $endpoints === [] ? 'no addresses' : implode(', ', array_column($endpoints, 'domain')).' removed with their certificates');

        $stage('data');
        if ($container) {
            $volumes = ContainerRecipes::removeVolumes($service->name, $deleteData);
            $volumes === null || $apply($volumes, 300);
        } else {
            $apply(ServiceRecipes::removeFiles($service->name, $service->run_user, $service->data_path, $deleteData), 300);
        }
        $log('data', $deleteData ? "{$service->data_path} deleted" : "{$service->data_path} kept");

        $def = $this->definitionOrNull($service);
        foreach ($def?->secrets ?? [] as $s) {
            $this->vault->forget($service->organization_id, Services::secretName((int) $service->id, $s['key']));
        }
        foreach ($def?->inputs ?? [] as $i) {
            if ($i['type'] === 'secret') {
                $this->vault->forget($service->organization_id, Services::secretName((int) $service->id, $i['key']));
            }
        }
        try {
            $this->backups->forget($service, $deleteData, $apply);
        } catch (Throwable $e) {
            $log('backups', 'The service\'s backups could not be cleaned up: '.$e->getMessage());
            DB::table('service_backups')->where('service_id', $service->id)->delete();
        }
        DB::table('service_endpoints')->where('service_id', $service->id)->delete();
        DB::table('services')->where('id', $service->id)->delete();
        $this->audit($operation, $service, $def, 'service.removed', ['data' => $deleteData ? 'deleted' : 'kept']);
        $this->openExtraPorts($service->node_id, $def, $apply, $log);
    }

    /**
     * Re-applies the server's firewall with the extra ports its services have asked for (and the person approved), when the
     * firewall is on. Called after an install or removal; a server with the firewall off is left alone.
     *
     * @param  Closure(array<string, mixed>, int): void  $apply
     */
    private function openExtraPorts(string $nodeId, ?Definition $def, Closure $apply, Closure $log): void
    {
        $settings = Firewall::settings($nodeId);
        if (! $settings['enabled'] || ($def !== null && $def->extraPorts === [])) {
            return;
        }
        $extra = $this->services->extraPortsOn($nodeId);
        $apply(Firewall::recipe($settings + ['extra' => $extra]), 300);
        $log('firewall', $extra === [] ? 'firewall re-applied' : 'firewall re-applied with '.implode(', ', array_map(fn ($p) => "{$p['port']}/{$p['proto']}", $extra)));
    }

    // ---- backup, restore, update (step 5) ------------------------------------------------------------------------

    /** @param Closure(array<string, mixed>, int): void $apply */
    public function backup(object $operation, Closure $stage, Closure $log, Closure $apply): void
    {
        $service = $this->service($operation);
        $kind = (string) ($this->meta($operation)['kind'] ?? 'manual');

        $stage('backup');
        $this->backups->take($service, $kind, $apply, $log, (string) $operation->actor);
    }

    /**
     * snapshot (a safety backup of what is there now), stop, restore, start, health. If the restore itself fails the service is
     * started again, and the message names the safety backup.
     *
     * @param  Closure(array<string, mixed>, int): void  $apply
     */
    public function restore(object $operation, Closure $stage, Closure $log, Closure $apply): void
    {
        $service = $this->service($operation);
        $backup = DB::table('service_backups')->where('organization_id', $service->organization_id)->where('service_id', $service->id)->where('id', (int) ($this->meta($operation)['backup_id'] ?? 0))->first()
            ?? throw new RuntimeException('That backup no longer exists.');
        if (! ServiceBackups::validName($backup->name)) {
            throw new RuntimeException('That backup has a name that cannot be restored.');
        }
        $r = $service->runtime === 'container' ? ContainerRecipes::class : ServiceRecipes::class;

        $stage('snapshot');
        $safety = $this->backups->take($service, 'pre_restore', $apply, $log, (string) $operation->actor);

        $stage('stop');
        $apply($r::action($service->name, 'stop'), 120);
        $stage('restore');
        try {
            $apply(ServiceBackups::restore($service, $backup->name, $this->backups->downloadUrl($backup)), 1800);
        } catch (Throwable $e) {
            try {
                $apply($r::action($service->name, 'start'), 120);
            } catch (Throwable) {
            }
            DB::table('services')->where('id', $service->id)->update(['status' => 'running', 'error' => null, 'updated_at' => now()]);

            throw new RuntimeException("The restore failed: {$e->getMessage()} The service was started again. What was there before is also in the backup {$safety['name']}.");
        }
        $log('restore', "{$backup->name} put back; what was there is kept as {$safety['name']}");

        $stage('start');
        $apply($r::action($service->name, 'start'), 120);
        $stage('health');
        $c = $this->healthContext($service);
        if ($c['health'] === []) {
            $log('health', 'the template has no health check; the service is running');
        } else {
            $apply($r::health($c), 120);
            $log('health', 'answers');
        }
        DB::table('services')->where('id', $service->id)->update(['status' => 'running', 'error' => null, 'updated_at' => now()]);
        $this->auditRaw($operation, $service, 'service.restored', ['backup' => $backup->name, 'kind' => $backup->kind, 'safety' => $safety['name']]);
    }

    /**
     * snapshot, fetch, switch, health. The snapshot comes first and, if it fails, nothing has changed. The new release or image
     * is fetched beside the old one and verified before anything stops. If the new version does not start or does not answer,
     * the previous one is put back and started: the service is running on the old version with the failure recorded on it, and
     * the operation fails. If putting back fails too, the service is failed with both errors. The data folder, the volumes and
     * every existing secret are untouched; the environment file is written again only if the new template's environment differs.
     *
     * @param  Closure(array<string, mixed>, int): void  $apply
     */
    public function update(object $operation, Closure $stage, Closure $log, Closure $apply): void
    {
        $service = $this->service($operation);
        $meta = $this->meta($operation);
        $from = (int) $service->template_version;
        $to = (int) ($meta['to_version'] ?? 0);
        $container = $service->runtime === 'container';
        $r = $container ? ContainerRecipes::class : ServiceRecipes::class;

        try {
            $u = $this->services->updateAvailable($service) ?? throw new RuntimeException("There is no newer version of {$service->template_id} to update to.");
            $def = $u['def'];
            if ($def->version !== $to) {
                throw new RuntimeException("The template {$def->id} is now at version {$def->version}; this update was for version {$to}. Update again.");
            }
            if (! $u['allowed']) {
                throw new RuntimeException((string) $u['refused']);
            }
            if ($u['confirm'] && empty($meta['confirmed'])) {
                throw new RuntimeException('This update changes where the program comes from and was not confirmed.');
            }
            $envChanged = ($u['installed']['env'] ?? null) !== md5((string) json_encode($def->runs['env'] ?? []));

            // Existing secrets are never regenerated; only the ones the new version adds are made.
            foreach ($def->secrets as $sec) {
                if (! $this->vault->has($service->organization_id, Services::secretName((int) $service->id, $sec['key']))) {
                    $this->vault->put($service->organization_id, Services::secretName((int) $service->id, $sec['key']), Services::generate($sec['generate']));
                }
            }
            $plain = (json_decode((string) $service->inputs, true) ?: []) + ($meta['new_inputs'] ?? []);
            $effective = $this->services->effective($def, $plain + array_diff_key($this->inputs($service, $def), $plain));
            $c = ServiceRecipes::context($def, $service->name, $effective, $this->secrets($service, $def), $container ? $this->hostPorts($service) : []);

            $stage('snapshot');
            try {
                $apply($container ? ContainerRecipes::updateCheck($service->name, (string) $service->image) : ServiceRecipes::updateCheck($service->name, $from), 60);
            } catch (Throwable) {
                throw new RuntimeException($container
                    ? "The image now running ({$service->image}) is no longer on this server, so a failed update could not be put back. Nothing was changed."
                    : 'The previous release folder '.ServiceRecipes::release($service->name, $from).' is gone from this server, so a failed update could not be put back. Nothing was changed. Install the service again, keeping its data, to move to the new version.');
            }
            try {
                $snapshot = $this->backups->take($service, 'pre_update', $apply, $log, (string) $operation->actor);
            } catch (Throwable $e) {
                throw new RuntimeException("The snapshot before the update failed, so nothing was changed: {$e->getMessage()}");
            }
            $log('snapshot', "{$snapshot['name']} taken before anything changes");

            $stage('fetch');
            $apply($r::fetch($c), 1200);
            $log($container ? 'image' : 'download', $container
                ? $c['repository'].' pulled and its digest matched '.substr($c['digest'], 0, 19).'...'
                : basename($c['download']).' downloaded to '.$c['release'].' and its sha256 matched '.substr($c['sha256'], 0, 12).'...; the previous release is kept');
        } catch (Throwable $e) {
            $this->auditRaw($operation, $service, 'service.update_failed', ['from' => $from, 'to' => $to, 'stage' => 'before the switch', 'changed' => false]);

            throw $e;
        }

        $stage('switch');
        $failure = null;
        try {
            $apply($r::updateSwitch($c, $envChanged), 300);
            $log($container ? 'container' : 'unit', "stopped, and {$def->name} version {$to} started");
            $stage('health');
            if (($c['health'] ?? []) === []) {
                $log('health', 'the template has no health check; it is running');
            } else {
                $apply($r::health($c), 120);
                $log('health', 'answers');
            }
        } catch (Throwable $e) {
            $failure = $e;
        }

        if ($failure !== null) {
            $this->putBack($operation, $service, $c, $r, $envChanged, $from, $to, $failure, $log, $apply);
        }

        // Healthy: only now is the row changed.
        $previous = array_values(array_merge([['version' => $from, 'ref' => $container ? (string) $service->image : null]], json_decode((string) $service->previous, true) ?: []));
        $kept = array_slice($previous, 0, ServiceRecipes::KEEP_PREVIOUS);
        $drop = $container ? array_values(array_filter(array_column(array_slice($previous, ServiceRecipes::KEEP_PREVIOUS), 'ref'), fn ($ref) => $ref !== null && $ref !== $c['image'] && ! in_array($ref, array_column($kept, 'ref'), true))) : [];
        $entry = $this->services->sourcesEntry($service->template_id);
        DB::table('services')->where('id', $service->id)->update([
            'template_version' => $to, 'image' => $container ? $c['image'] : null, 'inputs' => json_encode($plain), 'installed' => json_encode(Services::summarize($def, $entry, $c)),
            'previous' => json_encode($kept), 'status' => 'running', 'error' => null, 'updated_at' => now(),
        ]);
        try {
            $apply($container ? ContainerRecipes::updateFinish($service->name, $drop) : ServiceRecipes::updateFinish($service->name, $to), 120);
        } catch (Throwable $e) {
            $log('prune', 'Old releases could not be removed this time: '.$e->getMessage());
        }
        $this->auditRaw($operation, $service, 'service.updated', ['from' => $from, 'to' => $to, 'checksum' => $def->runs['sha256'] ?? null, 'digest' => $container ? $c['digest'] : null]);
        $this->openExtraPorts($service->node_id, $def, $apply, $log);
    }

    /**
     * The new version failed after the switch began. Put the previous one back. Always ends in an exception: the operation failed
     * either way, and the message says plainly what state the service is in.
     *
     * @param  class-string  $r
     * @param  array<string, mixed>  $c
     * @param  Closure(array<string, mixed>, int): void  $apply
     * @param  Closure(string, string): void  $log
     */
    private function putBack(object $operation, object $service, array $c, string $r, bool $envChanged, int $from, int $to, Throwable $failure, Closure $log, Closure $apply): never
    {
        $log('rollback', "Version {$to} failed ({$failure->getMessage()}); putting version {$from} back.");
        try {
            $apply($r::updateSwitchBack($c, $envChanged), 300);
        } catch (Throwable $back) {
            $message = "Update to version {$to} failed: {$failure->getMessage()} Putting version {$from} back failed too: {$back->getMessage()} The service is not running; its data is untouched and the backup taken before the update is in the Backups list.";
            DB::table('services')->where('id', $service->id)->update(['status' => 'failed', 'error' => mb_substr($message, 0, 1000), 'updated_at' => now()]);
            $this->auditRaw($operation, $service, 'service.update_failed', ['from' => $from, 'to' => $to, 'stage' => 'switch', 'changed' => true, 'rolled_back' => false]);

            throw new RuntimeException($message);
        }
        $message = "Version {$to} did not start or did not answer ({$failure->getMessage()}), so version {$from} was put back and is running. Nothing else was changed.";
        DB::table('services')->where('id', $service->id)->update(['status' => 'running', 'error' => 'The update to version '.$to.' failed and was rolled back: '.mb_substr($failure->getMessage(), 0, 600), 'updated_at' => now()]);
        $this->auditRaw($operation, $service, 'service.update_rolled_back', ['from' => $from, 'to' => $to]);

        throw new RuntimeException($message);
    }

    /** What the health recipe needs, from the row and its endpoints, without the definition. @return array<string, mixed> */
    private function healthContext(object $service): array
    {
        $installed = Services::installedSummary($service);
        $rows = DB::table('service_endpoints')->where('service_id', $service->id)->orderBy('port')->get();
        $container = $service->runtime === 'container';

        return [
            'name' => $service->name, 'unit' => $container ? ContainerRecipes::container($service->name) : ServiceRecipes::unit($service->name),
            'container' => ContainerRecipes::container($service->name), 'health' => $installed['health'] ?? [],
            'ports' => $rows->mapWithKeys(fn ($e) => [(int) ($container ? $e->container_port : $e->port) => $e->domain])->all(),
            'host_ports' => $container ? $rows->mapWithKeys(fn ($e) => [(int) $e->container_port => (int) $e->port])->all() : [],
        ];
    }

    /** @return array<int, int> container port => host port, as installed */
    private function hostPorts(object $service): array
    {
        return DB::table('service_endpoints')->where('service_id', $service->id)->whereNotNull('container_port')->pluck('port', 'container_port')->map(fn ($p) => (int) $p)->all();
    }

    /** @param array<string, mixed> $extra */
    private function auditRaw(object $operation, object $service, string $action, array $extra): void
    {
        $this->audit->record($service->organization_id, Actor::user((string) $operation->actor, (string) $operation->actor), $action,
            resourceType: 'service', resourceId: "{$service->node_id}/{$service->name}", metadata: ['template' => $service->template_id] + $extra);
    }

    // ---- pieces ------------------------------------------------------------

    private function service(object $operation): object
    {
        $id = (int) ($this->meta($operation)['service_id'] ?? 0);

        return DB::table('services')->where('id', $id)->where('organization_id', $operation->organization_id)->first()
            ?? throw new RuntimeException('The service for this operation no longer exists.');
    }

    /** @return array<string, mixed> */
    private function meta(object $operation): array
    {
        return json_decode((string) $operation->meta, true) ?: [];
    }

    /** The saved inputs plus the secret inputs from the vault. @return array<string, string> */
    private function inputs(object $service, Definition $def): array
    {
        $inputs = json_decode((string) $service->inputs, true) ?: [];
        foreach ($def->inputs as $i) {
            if ($i['type'] === 'secret') {
                $inputs[$i['key']] = (string) $this->vault->get($service->organization_id, Services::secretName((int) $service->id, $i['key']));
            }
        }

        return $inputs;
    }

    /** @return array<string, string> */
    private function secrets(object $service, Definition $def): array
    {
        $out = [];
        foreach ($def->secrets as $s) {
            $out[$s['key']] = (string) $this->vault->get($service->organization_id, Services::secretName((int) $service->id, $s['key']));
        }

        return $out;
    }

    private function definitionOrNull(object $service): ?Definition
    {
        try {
            $def = $this->services->definition($service->template_id);

            return $def->version === (int) $service->template_version ? $def : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $extra */
    private function audit(object $operation, object $service, ?Definition $def, string $action, array $extra): void
    {
        $this->audit->record($service->organization_id, Actor::user((string) $operation->actor, (string) $operation->actor), $action,
            resourceType: 'service', resourceId: "{$service->node_id}/{$service->name}",
            metadata: ['template' => $service->template_id, 'version' => (int) $service->template_version, 'checksum' => $def?->runs['sha256'] ?? null,
                'digest' => $service->image === null ? null : substr((string) strstr($service->image, '@'), 1)] + $extra);
    }
}
