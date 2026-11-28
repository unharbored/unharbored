<?php

declare(strict_types=1);

namespace App\Lib\Operations;

use App\Lib\Gateway\GatewayClient;
use App\Lib\Servers\AgentUpgrade;
use App\Lib\Servers\AdminAccess;
use App\Lib\Servers\PhpSettings;
use App\Lib\Servers\ServerStack;
use App\Lib\Sites\Deployer;
use App\Lib\Sites\Sites;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Starts long actions as operations and runs them.
 *
 * queue*() records an operation and puts it on the queue; run() is what the
 * queued job calls. Commands run the same run() inline so the CLI and the
 * console leave the same history.
 */
final class OperationRunner
{
    public function __construct(
        private readonly Operations $operations,
        private readonly Deployer $deployer,
        private readonly Sites $sites,
        private readonly GatewayClient $gateway,
        private readonly ServerStack $stack,
        private readonly AgentUpgrade $agentUpgrade,
    ) {}

    public function queueDeploy(int $siteId, string $trigger, string $actor, ?string $commit = null): int
    {
        return $this->queue($this->recordDeploy($siteId, $trigger, $actor, $commit));
    }

    /** Records a deploy without queueing it, for a command to run inline. */
    public function recordDeploy(int $siteId, string $trigger, string $actor, ?string $commit = null): int
    {
        $site = $this->site($siteId);
        $now = gmdate('Ymd\THis\Z');

        return $this->operations->create(
            organizationId: $site->organization_id,
            kind: 'deploy',
            title: "Deploy {$site->name}",
            trigger: $trigger,
            actor: $actor,
            stages: \App\Lib\Containers\ContainerApps::deployStages($site),
            siteId: $site->id,
            nodeId: $site->node_id,
            releaseId: Deployer::releaseId($now, $site->branch),
            commit: $commit,
            meta: ['now' => $now],
        );
    }

    public function queueRollback(int $siteId, string $trigger, string $actor): int
    {
        return $this->queue($this->recordRollback($siteId, $trigger, $actor));
    }

    public function recordRollback(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->operations->create($site->organization_id, 'rollback', "Roll back {$site->name}", $trigger, $actor, \App\Lib\Sites\Runtimes::isContainer($site) ? ['rollback'] : ['rollback', 'workers'], $site->id, $site->node_id);
    }

    public function queueHttps(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'https', "Issue a certificate for {$site->domain}", $trigger, $actor, ['certificate'], $site->id, $site->node_id));
    }

    /** Writes a site's saved web server settings (upload limit, timeout, snippet) to its servers. */
    public function queueWebServer(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'web_server', "Apply the web server settings of {$site->name}", $trigger, $actor, ['apply'], $site->id, $site->node_id));
    }

    /** Puts the application's variables on its running servers without a deploy. */
    public function queueEnvApply(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'env_apply', "Apply the variables of {$site->name}", $trigger, $actor, ['apply'], $site->id, $site->node_id));
    }

    /** Runs the application's server setup script on every server it is on. */
    public function queueServerScript(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        \App\Lib\Containers\ContainerApps::refuse($site, 'server script');

        return $this->queue($this->operations->create($site->organization_id, 'server_script', "Run the server setup script of {$site->name}", $trigger, $actor, ['run'], $site->id, $site->node_id));
    }

    /** Writes the Laravel scheduler's cron entry to the primary, or removes it, after its switch was changed. */
    public function queueScheduler(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        \App\Lib\Containers\ContainerApps::refuse($site, 'scheduler');

        return $this->queue($this->operations->create($site->organization_id, 'scheduler', "Apply the scheduler setting of {$site->name}", $trigger, $actor, ['apply'], $site->id, $site->node_id));
    }

    public function queueDomain(int $siteId, string $domain, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'domain', "Move {$site->name} to {$domain}", $trigger, $actor, ['move'], $site->id, $site->node_id, meta: ['domain' => $domain]));
    }

    public function queueStack(string $nodeId, string $trigger, string $actor): int
    {
        $node = $this->ordinaryNode($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'stack', "Install the server stack on {$nodeId}", $trigger, $actor, ['install'], nodeId: $nodeId));
    }

    public function queueAgentUpgrade(string $nodeId, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'agent_upgrade', "Upgrade the agent on {$nodeId}", $trigger, $actor, ['install', 'reconnect'], nodeId: $nodeId));
    }

    /** Installs one more PHP branch on a server, beside the default. */
    /** Writes a server's login user, its sudo password and its SSH keys, as they are now saved. */
    public function queueAdminAccess(string $nodeId, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'admin_access', "Apply the login user and SSH keys on {$nodeId}", $trigger, $actor,
            ['apply'], nodeId: $nodeId));
    }

    /** Locks SSH on a server down (no root, no passwords) or puts it back. */
    public function queueSshHardening(string $nodeId, bool $on, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'ssh_hardening', ($on ? 'Lock down SSH on ' : 'Allow root and password login again on ').$nodeId, $trigger, $actor,
            ['apply'], nodeId: $nodeId, meta: ['on' => $on]));
    }

    /** Applies a server's saved firewall: ufw rules rebuilt from its allow-lists, or off. */
    public function queueFirewall(string $nodeId, string $trigger, string $actor): int
    {
        $node = $this->ordinaryNode($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'firewall', "Apply the firewall on {$nodeId}", $trigger, $actor, ['apply'], nodeId: $nodeId));
    }

    /** Reads when the site's certificate ends, from the server itself. */
    public function queueCertificateCheck(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'certificate_check', "Check the certificate of {$site->name}", $trigger, $actor, ['read'], $site->id, $site->node_id));
    }

    /**
     * The daily check: the same read, refused (InvalidArgumentException) while
     * another certificate operation is queued or running, and started after
     * $delaySeconds so a run over many sites is spread out. A waiting check
     * stays queued, so the next day's run does not pile on.
     */
    public function queueScheduledCertificateCheck(int $siteId, int $delaySeconds = 0): int
    {
        $site = $this->site($siteId);

        return $this->exclusiveCertificateOperation($site, fn () => $this->queue($this->operations->create($site->organization_id, 'certificate_check', "Check the certificate of {$site->name}", 'schedule', 'system', ['read'], $site->id, $site->node_id), $delaySeconds));
    }

    /** Turns certbot's automatic renewal of a Let's Encrypt certificate on or off. */
    public function queueCertificateAutoRenew(int $siteId, bool $on, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        $this->assertRenewable($site);

        return $this->exclusiveCertificateOperation($site, fn () => $this->queue($this->operations->create($site->organization_id, 'certificate_autorenew', 'Turn automatic renewal of the certificate of '.$site->name.($on ? ' on' : ' off'), $trigger, $actor, ['apply'], $site->id, $site->node_id, meta: ['on' => $on])));
    }

    /**
     * Forces a new Let's Encrypt certificate now. Refused for a site without
     * Let's Encrypt HTTPS, and while a renewal or check is already queued or
     * running for it (a double click would spend the rate-limited quota twice).
     */
    public function queueCertificateRenew(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        $this->assertRenewable($site);

        return $this->exclusiveCertificateOperation($site, fn () => $this->queue($this->operations->create($site->organization_id, 'certificate_renew', "Renew the certificate of {$site->name}", $trigger, $actor, ['renew', 'expiry', 'balancer'], $site->id, $site->node_id)));
    }

    /**
     * Runs $queue only if no certificate operation is queued or running for
     * the site, and holds a lock while it does, so two requests at once
     * cannot both pass the check and both queue one.
     */
    private function exclusiveCertificateOperation(object $site, Closure $queue): mixed
    {
        $busy = new \InvalidArgumentException("A certificate operation is already running for {$site->name}. Wait for it to finish.");
        try {
            return Cache::lock("certificate-operation:{$site->id}", 15)->block(5, function () use ($site, $queue, $busy) {
                if (DB::table('operations')->where('site_id', $site->id)->whereIn('kind', ['certificate_renew', 'certificate_check', 'certificate_upload', 'certificate_revert', 'certificate_autorenew'])->whereIn('status', ['queued', 'running'])->exists()) {
                    throw $busy;
                }

                return $queue();
            });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException) {
            throw $busy;
        }
    }

    /**
     * Checks an uploaded certificate and key, then holds them for the queued
     * job. They go into the vault (encrypted per organisation, the way a
     * deploy key or the Tailscale auth key is), never into the operation row,
     * its log or the audit trail; the operation deletes the held copy when it
     * is done, whether it applied or failed. Returns the operation and what
     * the checks found (no key, no chain).
     *
     * @return array{id: int, summary: array{issuer: string, names: list<string>, expires_at: string, warnings: list<string>}}
     *
     * @throws \InvalidArgumentException with text for the person uploading
     */
    public function queueCertificateUpload(int $siteId, #[\SensitiveParameter] string $chain, #[\SensitiveParameter] string $key, string $trigger, string $actor): array
    {
        $site = $this->site($siteId);
        if (! (bool) $site->tls) {
            throw new \InvalidArgumentException("{$site->name} is not on HTTPS yet. Turn HTTPS on first, then upload the certificate.");
        }
        $bundle = \App\Lib\Sites\CertificateBundle::check($chain, $key, $site->domain);

        // The held copy is named after the operation, so two uploads can never overwrite each other's.
        $id = $this->exclusiveCertificateOperation($site, function () use ($site, $bundle, $trigger, $actor) {
            $id = $this->operations->create($site->organization_id, 'certificate_upload', "Upload a certificate for {$site->name}", $trigger, $actor, ['validate', 'apply', 'balancer'], $site->id, $site->node_id);
            $vault = app(\App\Lib\Vault\Vault::class);
            try {
                $vault->put($site->organization_id, self::heldCertificate($id), (string) json_encode(['chain' => $bundle['fullchain'], 'key' => $bundle['key']]));
                $this->queue($id);
            } catch (Throwable $e) {
                // Nothing will run this operation: drop the held copy and close the row so it does not block the next upload.
                $vault->forget($site->organization_id, self::heldCertificate($id));
                $this->operations->fail($id, 'The upload could not be queued.');
                throw $e;
            }

            return $id;
        });

        return ['id' => $id, 'summary' => [
            'issuer' => $bundle['issuer'],
            'names' => $bundle['names'],
            'expires_at' => $bundle['expires_at']->toIso8601String(),
            'warnings' => $bundle['warnings'],
        ]];
    }

    /** Back to a Let's Encrypt certificate: issued fresh, the uploaded files removed. */
    public function queueCertificateRevert(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        $this->assertCustomCertificate($site);

        return $this->exclusiveCertificateOperation($site, fn () => $this->queue($this->operations->create($site->organization_id, 'certificate_revert', "Use Let's Encrypt for {$site->name}", $trigger, $actor, ['revert', 'balancer'], $site->id, $site->node_id)));
    }

    private static function heldCertificate(int $operationId): string
    {
        return "certificate-upload:{$operationId}";
    }

    private function assertCustomCertificate(object $site): void
    {
        if (! (bool) $site->tls || $site->cert_source !== 'custom') {
            throw new \InvalidArgumentException("{$site->name} does not use an uploaded certificate.");
        }
    }

    /** The end of every certificate change: the balancer gets the active certificate, and the change is audited. @param array<string, mixed> $metadata */
    private function syncBalancerAndAudit(object $operation, object $site, Closure $stage, Closure $log, string $action, array $metadata): void
    {
        $stage('balancer');
        $log('balancer', $site->load_balancer_id !== null ? app(\App\Lib\Cloud\LoadBalancers::class)->syncCertificate($site) : 'no load balancer; nothing to upload');
        app(\App\Lib\Audit\AuditLog::class)->record($site->organization_id, \App\Lib\Audit\Actor::user((string) $operation->actor, (string) $operation->actor),
            $action, resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: $metadata);
    }

    private function assertRenewable(object $site): void
    {
        if (! (bool) $site->tls) {
            throw new \InvalidArgumentException("{$site->name} has no certificate: HTTPS is off.");
        }
        if ($site->cert_source === 'custom') {
            throw new \InvalidArgumentException("{$site->name} uses an uploaded certificate; upload a new certificate instead.");
        }
    }

    public function queueTailscaleJoin(string $nodeId, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'tailscale_join', "Join {$nodeId} to the tailnet", $trigger, $actor, ['join', 'verify'], nodeId: $nodeId));
    }

    public function queueTailscaleLeave(string $nodeId, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'tailscale_leave', "Take {$nodeId} off the tailnet", $trigger, $actor, ['firewall', 'leave'], nodeId: $nodeId));
    }

    /** Writes a server's saved php.ini settings for one PHP branch, checked by PHP-FPM before it reloads. */
    public function queuePhpSettings(string $nodeId, string $version, string $trigger, string $actor): int
    {
        $node = $this->ordinaryNode($nodeId);
        if (($why = ServerStack::phpRefusal($nodeId, $version)) !== null) {
            throw new \InvalidArgumentException($why);
        }

        return $this->queue($this->operations->create($node->organization_id, 'php_settings', "Apply the php{$version} settings on {$nodeId}", $trigger, $actor,
            ['apply'], nodeId: $nodeId, meta: ['version' => $version]));
    }

    public function queueInstallPhp(string $nodeId, string $version, string $trigger, string $actor): int
    {
        $node = $this->ordinaryNode($nodeId);
        if (($why = ServerStack::phpRefusal($nodeId, $version)) !== null) {
            throw new \InvalidArgumentException($why);
        }

        return $this->queue($this->operations->create($node->organization_id, 'install_php', "Install PHP {$version} on {$nodeId}".(ServerStack::phpFromSource(ServerStack::osOf($nodeId), $version) ? ' (built from source; this takes a while)' : ''), $trigger, $actor,
            ['install'], nodeId: $nodeId, meta: ['version' => $version]));
    }

    /** Applies patch updates to one PHP branch on a server and restarts its apps' workers. */
    public function queueUpdatePhp(string $nodeId, string $version, string $trigger, string $actor): int
    {
        $node = $this->ordinaryNode($nodeId);
        if (($why = ServerStack::phpRefusal($nodeId, $version)) !== null) {
            throw new \InvalidArgumentException($why);
        }

        return $this->queue($this->operations->create($node->organization_id, 'update_php', "Update PHP {$version} on {$nodeId}", $trigger, $actor,
            ['update', 'workers'], nodeId: $nodeId, meta: ['version' => $version]));
    }

    /** A DigitalOcean managed cluster, or, for any other provider, a database server Unharbored runs on a server of its own. */
    public function queueDatabaseCreate(string $organizationId, string $slug, string $engine, string $region, string $size, string $trigger, string $actor, string $provider = 'digitalocean'): int
    {
        return $this->queue($this->operations->create($organizationId, 'database_create', "Create database cluster {$slug}", $trigger, $actor,
            $provider === 'digitalocean' ? ['create', 'online'] : ['create', 'enroll', 'setup'], meta: compact('slug', 'engine', 'region', 'size', 'provider')));
    }

    /** Copies the site's database, from a fresh dump, into a new one called $name. */
    public function queueDatabaseClone(int $siteId, string $name, bool $schemaOnly, string $trigger, string $actor, string $conversion = ''): int
    {
        $site = $this->site($siteId);

        // The conversion text still has its placeholders: the hashes are made when the copy is, and are never stored.
        return $this->queue($this->operations->create($site->organization_id, 'database_clone', "Clone {$site->name}'s database as {$name}", $trigger, $actor,
            ['clone'], $site->id, $site->node_id, meta: ['name' => $name, 'schema_only' => $schemaOnly] + (trim($conversion) === '' ? [] : ['conversion' => trim($conversion)])));
    }

    public function queueDatabaseAttach(int $siteId, int $clusterResourceId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        $cluster = DB::table('resources')->where('id', $clusterResourceId)->first() ?? throw new RuntimeException('No such cluster.');
        \App\Lib\Servers\ServerArchiving::refuseIfClusterArchived($clusterResourceId);

        return $this->queue($this->operations->create($site->organization_id, 'database_attach', "Attach {$site->name} to {$cluster->slug}", $trigger, $actor,
            ['attach'], $site->id, $site->node_id, meta: ['cluster' => $clusterResourceId]));
    }

    /** Installs PostgreSQL, MySQL or Valkey on a server, listening on 127.0.0.1 only. */
    public function queueServiceInstall(string $nodeId, string $service, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'service_install', 'Install '.\App\Lib\Servers\LocalServices::label($service).' on '.(strstr($nodeId, '.', true) ?: $nodeId),
            $trigger, $actor, ['install'], nodeId: $nodeId, meta: ['service' => $service]));
    }

    /**
     * Template services (docs/specs/templates.md section 5). The kinds are template_service_*, since
     * service_install is already the server's PostgreSQL, MySQL and Valkey. The service id is in the
     * operation's meta, which also tells the per-service lock what is running. $dispatch is false for a
     * command that runs the operation itself.
     */
    public function queueTemplateServiceInstall(int $serviceId, string $trigger, string $actor, bool $dispatch = true): int
    {
        $service = $this->templateService($serviceId);

        return $this->queueIf($dispatch, $this->operations->create($service->organization_id, 'template_service_install', "Install {$service->name}", $trigger, $actor,
            ['check', 'fetch', 'prepare', 'run', 'proxy', 'health'], nodeId: $service->node_id, meta: ['service_id' => $serviceId]));
    }

    public function queueTemplateServiceAction(int $serviceId, string $action, string $trigger, string $actor, bool $dispatch = true): int
    {
        $service = $this->templateService($serviceId);

        return $this->queueIf($dispatch, $this->operations->create($service->organization_id, 'template_service_action', ucfirst($action)." {$service->name}", $trigger, $actor,
            ['apply'], nodeId: $service->node_id, meta: ['service_id' => $serviceId, 'action' => $action]));
    }

    /** $deleteData is the caller's answer to "keep or delete the data"; it is recorded, never defaulted. */
    public function queueTemplateServiceRemove(int $serviceId, bool $deleteData, string $trigger, string $actor, bool $dispatch = true): int
    {
        $service = $this->templateService($serviceId);

        return $this->queueIf($dispatch, $this->operations->create($service->organization_id, 'template_service_remove', "Remove {$service->name}".($deleteData ? ' and its data' : ', keeping its data'), $trigger, $actor,
            ['stop', 'endpoints', 'data'], nodeId: $service->node_id, meta: ['service_id' => $serviceId, 'delete_data' => $deleteData]));
    }

    /** @param array<string, mixed> $newInputs the plain values for the inputs the new version adds (secret ones are in the vault) */
    public function queueTemplateServiceUpdate(int $serviceId, int $toVersion, array $newInputs, bool $confirmed, string $trigger, string $actor, bool $dispatch = true): int
    {
        $service = $this->templateService($serviceId);

        return $this->queueIf($dispatch, $this->operations->create($service->organization_id, 'template_service_update', "Update {$service->name} to version {$toVersion}", $trigger, $actor,
            ['snapshot', 'fetch', 'switch', 'health'], nodeId: $service->node_id, meta: ['service_id' => $serviceId, 'to_version' => $toVersion, 'new_inputs' => $newInputs, 'confirmed' => $confirmed]));
    }

    public function queueTemplateServiceBackup(int $serviceId, string $kind, string $trigger, string $actor, bool $dispatch = true): int
    {
        $service = $this->templateService($serviceId);

        return $this->queueIf($dispatch, $this->operations->create($service->organization_id, 'template_service_backup', "Back up {$service->name}", $trigger, $actor,
            ['backup'], nodeId: $service->node_id, meta: ['service_id' => $serviceId, 'kind' => $kind]));
    }

    public function queueTemplateServiceRestore(int $serviceId, int $backupId, string $trigger, string $actor, bool $dispatch = true): int
    {
        $service = $this->templateService($serviceId);

        return $this->queueIf($dispatch, $this->operations->create($service->organization_id, 'template_service_restore', "Restore {$service->name} from a backup", $trigger, $actor,
            ['snapshot', 'stop', 'restore', 'start', 'health'], nodeId: $service->node_id, meta: ['service_id' => $serviceId, 'backup_id' => $backupId]));
    }

    /** Prepares a server for containers (Docker Engine). docs/specs/templates.md section 5. */
    public function queueServerContainers(string $nodeId, bool $acknowledgedSmall, string $trigger, string $actor, bool $dispatch = true): int
    {
        $node = $this->ordinaryNode($nodeId);

        return $this->queueIf($dispatch, $this->operations->create($node->organization_id, 'server_containers', 'Prepare '.(strstr($nodeId, '.', true) ?: $nodeId).' for containers', $trigger, $actor,
            ['check', 'install', 'configure', 'verify'], nodeId: $nodeId, meta: ['acknowledged_small' => $acknowledgedSmall]));
    }

    /** Removes dangling images and build cache; nothing else. */
    public function queueContainerCleanup(string $nodeId, string $trigger, string $actor, bool $dispatch = true): int
    {
        $node = $this->node($nodeId);

        return $this->queueIf($dispatch, $this->operations->create($node->organization_id, 'container_cleanup', 'Clean up unused Docker layers on '.(strstr($nodeId, '.', true) ?: $nodeId), $trigger, $actor,
            ['prune'], nodeId: $nodeId));
    }

    private function queueIf(bool $dispatch, int $id): int
    {
        return $dispatch ? $this->queue($id) : $id;
    }

    private function templateService(int $id): object
    {
        return DB::table('services')->where('id', $id)->first() ?? throw new RuntimeException("No service {$id}.");
    }

    /** Gives an application a database or cache on its own server, installing the service first if it is missing. */
    public function queueLocalService(int $siteId, string $service, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'local_service', "Set up {$site->name} on ".\App\Lib\Servers\LocalServices::label($service),
            $trigger, $actor, ['install', 'attach'], $site->id, $site->node_id, meta: ['service' => $service]));
    }

    /**
     * Adds a server to a site: admits it to the site's clusters, then
     * deploys the live branch everywhere, which sets the new server up.
     */
    public function queueServerAdd(int $siteId, string $nodeId, string $trigger, string $actor, string $role = Sites::ROLE_WEB): int
    {
        if (! in_array($role, [Sites::ROLE_WEB, Sites::ROLE_WORKER], true)) {
            throw new \InvalidArgumentException('A server is added as a web server or a worker server.');
        }
        $site = $this->site($siteId);
        \App\Lib\Containers\ContainerApps::refuse($site, 'servers');
        if (\App\Lib\Providers\Incus\IncusHost::reserved(DB::table('nodes')->where('node_id', $nodeId)->value('role'))) {
            throw new \InvalidArgumentException("{$nodeId} is an Incus host or its ingress: nothing runs on it directly.");
        }
        \App\Lib\Servers\ServerArchiving::refuseIfNodeArchived($nodeId);
        $short = strstr($nodeId, '.', true) ?: $nodeId;
        $now = gmdate('Ymd\THis\Z');

        return $this->queue($this->operations->create($site->organization_id, 'server_add', "Add {$short} to {$site->name}", $trigger, $actor,
            ['admit', ...Deployer::STAGES], $site->id, $site->node_id, meta: ['node' => $nodeId, 'now' => $now, 'role' => $role]));
    }

    /** Puts a DigitalOcean Load Balancer in front of the site's servers. */
    public function queueLoadBalancer(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        \App\Lib\Containers\ContainerApps::refuse($site, 'load balancer');

        return $this->queue($this->operations->create($site->organization_id, 'load_balancer', "Load balance {$site->name}", $trigger, $actor,
            ['create', 'online', 'servers', 'certificate'], $site->id, $site->node_id));
    }

    /** $destroy: once it is off the application, destroy the server too (a server this platform made, that nothing else uses). */
    public function queueServerRemove(int $siteId, string $nodeId, string $trigger, string $actor, bool $destroy = false, bool $dispatch = true): int
    {
        $site = $this->site($siteId);
        $short = strstr($nodeId, '.', true) ?: $nodeId;

        return $this->queueIf($dispatch, $this->operations->create($site->organization_id, 'server_remove', "Remove {$short} from {$site->name}".($destroy ? ' and destroy it' : ''), $trigger, $actor,
            ['remove'], $site->id, $site->node_id, meta: ['node' => $nodeId, 'destroy' => $destroy]));
    }

    /**
     * @param  array{min: int, max: int, size: string, cpu: float}  $spec
     */
    public function queuePoolCreate(int $siteId, array $spec, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        \App\Lib\Containers\ContainerApps::refuse($site, 'autoscaling');

        return $this->queue($this->operations->create($site->organization_id, 'pool_create', "Autoscale {$site->name}", $trigger, $actor,
            ['pool'], $site->id, $site->node_id, meta: ['spec' => $spec]));
    }

    /**
     * A member the pool booted has enrolled. The stack goes on under the
     * member's own name, so a deploy is not held up for those minutes;
     * pool_join, under the site's, follows it.
     */
    public function queuePoolJoin(int $siteId, string $nodeId): int
    {
        $site = $this->site($siteId);
        $short = strstr($nodeId, '.', true) ?: $nodeId;

        return $this->queue($this->operations->create($site->organization_id, 'pool_member', "Prepare {$short} for {$site->name}", 'autoscale', 'autoscaler',
            ['connect', 'stack'], $site->id, $nodeId));
    }

    /** The autoscaler adds one server: create it, wait for its agent, then prepare and join it as a pool member. */
    public function queueScalerUp(int $siteId, string $slug, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'scaler_up', "Autoscale {$site->name}: add {$slug}", $trigger, $actor,
            ['create', 'enroll'], $site->id, $site->node_id, meta: ['slug' => $slug]));
    }

    /** The autoscaler removes one server it made: off the balancer, a short drain, then destroyed. A stranded one skips the drain. */
    public function queueScalerDown(int $siteId, string $nodeId, string $trigger, string $actor, bool $stranded = false): int
    {
        $site = $this->site($siteId);
        $short = strstr($nodeId, '.', true) ?: $nodeId;

        return $this->queue($this->operations->create($site->organization_id, 'scaler_down', "Autoscale {$site->name}: remove {$short}", $trigger, $actor,
            ['drain', 'destroy'], $site->id, $site->node_id, meta: ['node' => $nodeId, 'stranded' => $stranded]));
    }

    /** @param list<string> $nodeIds */
    public function queuePoolLeave(int $siteId, array $nodeIds, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        $names = implode(', ', array_map(fn ($n) => strstr($n, '.', true) ?: $n, $nodeIds));

        return $this->queue($this->operations->create($site->organization_id, 'pool_leave', "Take {$names} off {$site->name}", $trigger, $actor,
            ['leave'], $site->id, $site->node_id, meta: ['nodes' => $nodeIds]));
    }

    public function queuePoolResize(int $siteId, int $min, int $max, string $trigger, string $actor, bool $scheduled = false): int
    {
        $site = $this->site($siteId);
        $title = $scheduled ? "Keep at least {$min} servers in {$site->name}'s pool, as scheduled" : "Autoscale {$site->name} between {$min} and {$max}";

        return $this->queue($this->operations->create($site->organization_id, 'pool_resize', $title, $trigger, $actor,
            ['resize'], $site->id, $site->node_id, meta: ['min' => $min, 'max' => $max, 'scheduled' => $scheduled]));
    }

    public function queuePoolRemove(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'pool_remove', "Stop autoscaling {$site->name}", $trigger, $actor,
            ['remove', 'leave'], $site->id, $site->node_id));
    }

    /**
     * Builds a base image for autoscale pools in a region: a throwaway
     * Droplet gets the stack, is wiped and snapshotted, then destroyed.
     */
    public function queueBaseImage(string $organizationId, string $region, string $trigger, string $actor): int
    {
        $slug = 'image-'.gmdate('YmdHi');

        return $this->queue($this->operations->create($organizationId, 'base_image', "Build a base image in {$region}", $trigger, $actor,
            ['preflight', 'create', 'enroll', 'stack', 'clean', 'snapshot', 'publish'], nodeId: "{$slug}.{$organizationId}",
            meta: ['slug' => $slug, 'spec' => ['region' => $region, 'size' => 's-1vcpu-1gb']]));
    }

    /** Gives the site a Spaces bucket for uploads. */
    public function queueStorageAttach(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'storage_attach', "Move {$site->name} uploads to Spaces", $trigger, $actor,
            ['bucket'], $site->id, $site->node_id));
    }

    /** Deletes the site's Spaces bucket and everything in it. */
    public function queueStorageDetach(int $siteId, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'storage_detach', "Delete {$site->name} uploads bucket", $trigger, $actor,
            ['bucket'], $site->id, $site->node_id));
    }

    /**
     * Makes a preview of the site at a branch, then builds it like any
     * deploy and gives it HTTPS.
     */
    public function queuePreviewCreate(int $parentId, string $branch, bool $followPushes, ?int $idleDays, string $trigger, string $actor, ?string $database = null): int
    {
        \App\Lib\Containers\ContainerApps::refuse($this->site($parentId), 'previews');
        $preview = app(\App\Lib\Sites\Previews::class)->create($this->site($parentId), $branch, $followPushes, $idleDays, $database);

        return $this->queue($this->operations->create($preview->organization_id, 'preview_create', "Preview {$branch} of ".$this->site($parentId)->name, $trigger, $actor,
            ['database', ...\App\Lib\Sites\Deployer::STAGES, 'https'], $preview->id, $preview->node_id));
    }

    /**
     * Carries a failed new application on from the step that failed: that
     * step's operation is run afresh, the steps before it are kept.
     */
    /**
     * @param  array{region: string, size: string}|null  $server  a different region and size for a server that could not be made
     */
    public function queueRetry(int $id, string $actor, ?array $server = null): int
    {
        $operation = $this->operations->find($id) ?? throw new \InvalidArgumentException("No operation {$id}.");
        if ($operation->kind === 'server_delete' && $operation->status === 'failed') {
            // What is done is gone from the list it works from, so running it again carries on where it stopped.
            $this->operations->reopen($id);
            $this->operations->log($id, 'retry', "by {$actor}");

            return $this->queue($id);
        }
        if ($operation->kind === 'database_create' && $operation->status === 'failed') {
            // The server exists and its agent connected; only its setup failed. Setup is safe to run again from the start.
            $meta = json_decode((string) $operation->meta, true) ?: [];
            $resources = app(\App\Lib\Resources\ResourceManager::class);
            $cluster = $resources->find($operation->organization_id, 'database_cluster', (string) ($meta['slug'] ?? ''));
            $server = $resources->find($operation->organization_id, 'server', \App\Lib\Cloud\DatabaseServers::serverSlug((string) ($meta['slug'] ?? '')));
            if (($meta['provider'] ?? 'digitalocean') === 'digitalocean' || $cluster === null || $cluster->state !== \App\Lib\Resources\ResourceState::Failed
                || $server === null || $server->state === \App\Lib\Resources\ResourceState::Deleted) {
                throw new \InvalidArgumentException('Its server is gone, so this cannot carry on. Destroy the cluster and make it again.');
            }
            $resources->transition($cluster, \App\Lib\Resources\ResourceState::Provisioning, reason: "Setting up again, by {$actor}", actor: \App\Lib\Audit\Actor::system('control-plane'));
            $this->operations->reopen($id);
            $this->operations->log($id, 'retry', "setup again, by {$actor}");

            return $this->queue($id);
        }
        if ($operation->kind !== 'app_create' || $operation->status !== 'failed') {
            throw new \InvalidArgumentException('Only a failed new application or database cluster can be retried.');
        }
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $failed = collect($meta['steps'])->first(fn ($s) => ! in_array($s, $meta['done'], true));
        if ($server !== null) {
            if ($failed !== 'server' || ($meta['server']['mode'] ?? '') !== 'new') {
                throw new \InvalidArgumentException('The server can only be changed while it is the step that failed.');
            }
            $provider = app(\App\Lib\Providers\Providers::class)->get((string) ($meta['provider'] ?? 'digitalocean'));
            $catalog = $provider->catalog();
            if (! $catalog->hasRegion($server['region']) || ! $catalog->hasSize($server['size'])) {
                throw new \InvalidArgumentException('Pick a region and size from the lists.');
            }
            $offered = method_exists($provider, 'availableSizes') ? $provider->availableSizes($operation->organization_id, $server['region']) : null;
            if ($offered !== null && ! in_array($server['size'], $offered, true)) {
                throw new \InvalidArgumentException("{$provider->label()} does not offer {$server['size']} in {$server['region']}. Pick another size or region.");
            }
            $meta['server'] = ['region' => $server['region'], 'size' => $server['size']] + $meta['server'];
            $this->operations->mergeMeta($id, ['server' => $meta['server']]);
            $actor .= ", on a {$server['size']} in {$server['region']}";
        }
        unset($meta['children'][$failed]);
        $this->operations->mergeMeta($id, ['children' => $meta['children'] ?: (object) []]);
        $this->operations->reopen($id);
        $this->operations->log($id, 'retry', "from {$failed}, by {$actor}");

        return $this->queue($id);
    }

    /** Removes a preview: off its server, then every record of it. */
    public function queuePreviewDestroy(int $previewId, string $trigger, string $actor, bool $dispatch = true): int
    {
        $preview = $this->site($previewId);
        if ($preview->parent_site_id === null) {
            throw new \InvalidArgumentException("{$preview->name} is not a preview.");
        }

        return $this->queueIf($dispatch, $this->operations->create($preview->organization_id, 'preview_destroy', "Delete preview {$preview->name}", $trigger, $actor,
            ['remove'], $preview->id, $preview->node_id, meta: ['name' => $preview->name, 'domain' => $preview->domain]));
    }

    /**
     * Deletes an application: off its servers, then its record. Its database
     * and files are kept unless $deleteData, so the safe default loses
     * nothing. Servers and shared clusters are never destroyed here.
     */
    public function queueAppDelete(int $siteId, bool $deleteData, string $trigger, string $actor, bool $dispatch = true): int
    {
        $site = $this->site($siteId);

        if ($site->parent_site_id !== null) {
            throw new \InvalidArgumentException("{$site->name} is a preview; delete it from its application's Previews tab.");
        }
        if (DB::table('sites')->where('parent_site_id', $site->id)->exists()) {
            throw new \InvalidArgumentException("Delete {$site->name}'s previews first.");
        }
        if (DB::table('operations')->where('site_id', $site->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw new \InvalidArgumentException("An operation is running for {$site->name}. Wait for it to finish.");
        }
        if (! $deleteData && $site->autoscale_pool_id !== null && ! $this->uploadsOnS3($site)) {
            throw new \InvalidArgumentException("{$site->name} autoscales and keeps uploads on its own servers; deleting it now would lose them. Tick \"Also delete its database, uploaded files and stored secrets\" or move uploads to Spaces first.");
        }

        return $this->queueIf($dispatch, $this->operations->create($site->organization_id, 'app_delete',
            "Delete {$site->name}".($deleteData ? ' and its data' : ''), $trigger, $actor,
            ['remove', 'data', 'forget'], $site->id, $site->node_id, meta: ['delete_data' => $deleteData]));
    }

    /**
     * Destroys a Droplet the platform created, after checking nothing still
     * depends on it. The agent is cut off as part of the same operation.
     */
    public function queueServerDestroy(string $nodeId, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);
        $slug = strstr($nodeId, '.', true) ?: $nodeId;

        $primary = DB::table('sites')->where('node_id', $nodeId)->pluck('name')->all();
        $secondary = DB::table('sites')->whereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id'))->pluck('name')->all();
        $sites = array_values(array_unique([...$primary, ...$secondary]));
        if ($sites !== []) {
            throw new \InvalidArgumentException("{$nodeId} still serves ".implode(', ', $sites).'. Delete those applications first.');
        }
        if (DB::table('operations')->where('node_id', $nodeId)->whereIn('status', ['queued', 'running'])->exists()) {
            throw new \InvalidArgumentException("An operation is running on {$nodeId}. Wait for it to finish.");
        }
        if (DB::table('site_nodes')->where('node_id', $nodeId)->whereNotNull('autoscale_pool_id')->exists()) {
            throw new \InvalidArgumentException("{$nodeId} is in an autoscale pool; stop autoscaling that application instead.");
        }
        if (($app = $this->archivedOn($node->organization_id, $nodeId)) !== null) {
            throw new \InvalidArgumentException("{$nodeId} holds retained data for {$app}. Delete that retained data first.");
        }
        $resource = DB::table('resources')->where('organization_id', $node->organization_id)->where('type', 'server')->where('slug', $slug)->first();
        if ($resource === null || $resource->external_id === null) {
            throw new \InvalidArgumentException("{$nodeId} is not a Droplet this platform created; disconnect it instead.");
        }
        if (self::poolOf($resource) !== null) {
            throw new \InvalidArgumentException("{$nodeId} is in an autoscale pool; stop autoscaling that application instead.");
        }
        if (($node->role ?? null) === 'ingress') {
            throw new \InvalidArgumentException('The IPv4 ingress belongs to the Incus host; it goes when the host is destroyed.');
        }
        $host = ($node->role ?? null) === \App\Lib\Providers\Incus\IncusHost::ROLE;
        if ($host) {
            \App\Lib\Providers\Incus\IncusHost::assertEmpty($node->organization_id);
        }

        return $this->queue($this->operations->create($node->organization_id, 'server_destroy',
            ($host ? "Destroy Incus host {$slug} in " : "Destroy {$slug} in ").$this->providerLabel($resource->provider ?? null), $trigger, $actor,
            $host ? ['host', 'destroy'] : ['destroy'], nodeId: $nodeId, meta: ['slug' => $slug]));
    }

    /**
     * Deletes a server and everything under it, as the person decided after seeing Underneath::of().
     *
     * @param  array<string, string>  $choices  item key => one of that item's choices
     */
    public function queueServerDelete(string $nodeId, array $choices, string $trigger, string $actor, bool $dispatch = true): int
    {
        $node = $this->node($nodeId);
        $slug = strstr($nodeId, '.', true) ?: $nodeId;
        $under = \App\Lib\Servers\Underneath::of($nodeId);
        if ($under['blockers'] !== []) {
            throw new \InvalidArgumentException(implode(' ', $under['blockers']));
        }
        self::assertChosen($under['items'], $choices);

        return $this->queueIf($dispatch, $this->operations->create($node->organization_id, 'server_delete',
            "Delete {$slug} and everything under it", $trigger, $actor, ['apps', 'retained', 'databases', 'server'],
            nodeId: $nodeId, meta: ['slug' => $slug, 'choices' => $choices]));
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>  $choices
     */
    private static function assertChosen(array $items, array $choices): void
    {
        foreach ($items as $item) {
            if ($item['choices'] === []) {
                continue;
            }
            if (! in_array($choices[$item['key']] ?? null, array_column($item['choices'], 'value'), true)) {
                throw new \InvalidArgumentException("Choose what happens to {$item['name']}.");
            }
        }
    }

    /**
     * Destroys a Droplet the platform made after it was disconnected, so a
     * server that is no longer enrolled can still have its billing stopped.
     */
    public function queueDropletDestroy(int $resourceId, string $trigger, string $actor): int
    {
        $resource = app(\App\Lib\Resources\ResourceManager::class)->findById($resourceId)
            ?? throw new \InvalidArgumentException('No such Droplet.');
        if ($resource->type !== 'server' || $resource->externalId === null) {
            throw new \InvalidArgumentException('That record is not a Droplet the platform can destroy.');
        }
        if (self::poolOf($resource) !== null) {
            throw new \InvalidArgumentException("{$resource->slug} is in an autoscale pool; stop autoscaling that application instead.");
        }
        if (($app = $this->archivedOn($resource->organizationId, $resource->slug)) !== null) {
            throw new \InvalidArgumentException("{$resource->slug} holds retained data for {$app}. Delete that retained data first.");
        }
        // The same dependency checks as the ordinary path, at queue time (and again when it runs).
        $nodeId = "{$resource->slug}.{$resource->organizationId}";
        $serving = array_values(array_unique([...DB::table('sites')->where('node_id', $nodeId)->pluck('name')->all(),
            ...DB::table('sites')->whereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id'))->pluck('name')->all()]));
        if ($serving !== []) {
            throw new \InvalidArgumentException("{$nodeId} still serves ".implode(', ', $serving).'. Delete those applications first.');
        }
        if (DB::table('operations')->where('node_id', $nodeId)->whereIn('status', ['queued', 'running'])->exists()) {
            throw new \InvalidArgumentException("An operation is running on {$nodeId}. Wait for it to finish.");
        }

        return $this->queue($this->operations->create($resource->organizationId, 'server_destroy',
            "Destroy {$resource->slug} in ".$this->providerLabel($resource->provider ?? null), $trigger, $actor, ['destroy'], nodeId: DB::table('nodes')->where('node_id', $nodeId)->exists() ? $nodeId : null, meta: ['slug' => $resource->slug, 'resource' => $resource->id]));
    }

    /** Permanently deletes an archived application's files, database and secrets. */
    public function queueArchiveDestroy(int $archiveId, string $trigger, string $actor, bool $dispatch = true): int
    {
        $archive = DB::table('site_archives')->where('id', $archiveId)->first()
            ?? throw new \InvalidArgumentException('No such retained data.');

        return $this->queueIf($dispatch, $this->operations->create($archive->organization_id, 'archive_destroy',
            "Delete retained data for {$archive->name}", $trigger, $actor, ['files', 'database', 'secrets'], meta: ['archive' => $archiveId]));
    }

    /** Copies the site's database to the backup bucket. */
    public function queueSiteBackup(int $siteId, string $kind, string $trigger, string $actor, bool $dispatch = true): int
    {
        $site = $this->site($siteId);

        return $this->queueIf($dispatch, $this->operations->create($site->organization_id, 'site_backup', "Back up {$site->name}'s database", $trigger, $actor,
            ['backup'], $site->id, $site->node_id, meta: ['kind' => $kind]));
    }

    /** Puts one of the site's backups back, in maintenance mode. */
    public function queueSiteRestore(int $siteId, string $object, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);

        return $this->queue($this->operations->create($site->organization_id, 'site_restore', "Restore {$site->name}'s database from ".basename($object), $trigger, $actor,
            ['backup', 'restore'], $site->id, $site->node_id, meta: ['object' => $object]));
    }

    /**
     * Puts the site's managed database back as it was in one of
     * DigitalOcean's daily backups of its cluster, through a temporary
     * cluster made from that backup.
     */
    public function queueSiteRestoreFromClusterBackup(int $siteId, string $backupCreatedAt, string $trigger, string $actor): int
    {
        $site = $this->site($siteId);
        $cluster = $site->database_cluster_id !== null ? app(\App\Lib\Resources\ResourceManager::class)->findById((int) $site->database_cluster_id) : null;
        if ($cluster === null) {
            throw new \InvalidArgumentException("{$site->name} is not on a managed database.");
        }
        if (! in_array($backupCreatedAt, array_column(app(\App\Lib\Cloud\DatabaseClusters::class)->backups($cluster, \App\Lib\Sites\Sites::attachedAt($site)), 'created_at'), true)) {
            throw new \InvalidArgumentException("{$cluster->slug} has no backup from {$backupCreatedAt} that holds {$site->name}'s database.");
        }
        $at = \Illuminate\Support\Carbon::parse($backupCreatedAt)->utc();

        return $this->queue($this->operations->create($site->organization_id, 'site_restore_time', "Restore {$site->name}'s database from DigitalOcean's backup of {$at->format('Y-m-d H:i')} UTC", $trigger, $actor,
            ['fork', 'online', 'restore', 'cleanup'], $site->id, $site->node_id,
            meta: ['backup' => $backupCreatedAt, 'slug' => "pitr-{$site->id}-".gmdate('YmdHis')]));
    }

    public function queueDatabaseDestroy(int $clusterResourceId, string $trigger, string $actor, bool $dispatch = true): int
    {
        $cluster = DB::table('resources')->where('id', $clusterResourceId)->first() ?? throw new RuntimeException('No such cluster.');

        return $this->queueIf($dispatch, $this->operations->create($cluster->organization_id, 'database_destroy', "Destroy database cluster {$cluster->slug}", $trigger, $actor,
            ['destroy'], meta: ['cluster' => $clusterResourceId]));
    }

    /** A new certificate for a self-run database server; $address is for a server of your own whose address changed. */
    public function queueDatabaseReissue(int $clusterResourceId, ?string $address, string $trigger, string $actor): int
    {
        $cluster = DB::table('resources')->where('id', $clusterResourceId)->first() ?? throw new RuntimeException('No such cluster.');
        \App\Lib\Servers\ServerArchiving::refuseIfClusterArchived($clusterResourceId);

        return $this->queue($this->operations->create($cluster->organization_id, 'database_reissue', "New certificate for database cluster {$cluster->slug}", $trigger, $actor,
            ['reissue'], meta: ['cluster' => $clusterResourceId, 'address' => $address]));
    }

    /** Follows a server someone else installs, from the command to the stack. */
    /** @param  array<string, mixed>  $meta  `database` makes the server a database server instead of a web server */
    public function queueAdopt(string $organizationId, string $nodeId, int $installId, string $trigger, string $actor, array $meta = []): int
    {
        $name = strstr($nodeId, '.', true) ?: $nodeId;
        $database = isset($meta['database']);

        return $this->queue($this->operations->create($organizationId, 'adopt', $database ? "Set up your server as database {$meta['database']['cluster']}" : "Add your own server {$name}", $trigger, $actor,
            ['install', 'enroll', $database ? 'setup' : 'stack'], nodeId: $nodeId, meta: ['install_id' => $installId] + $meta));
    }

    /** Logs in over SSH once, checks the machine, installs the agent, then follows it to the stack. The login is never stored. */
    public function queueAdoptSsh(string $organizationId, string $nodeId, int $adoptionId, string $trigger, string $actor, ?string $role = null): int
    {
        $name = strstr($nodeId, '.', true) ?: $nodeId;
        $host = $role === \App\Lib\Providers\Incus\IncusHost::ROLE;

        return $this->queue($this->operations->create($organizationId, 'adopt_ssh', $host ? "Connect {$name} over SSH as an Incus host" : "Connect {$name} over SSH", $trigger, $actor,
            ['connect', 'preflight', 'install', 'enroll', $host ? 'incus' : 'stack'], nodeId: $nodeId, meta: ['adoption_id' => $adoptionId] + ($host ? ['role' => $role] : [])));
    }

    /** Moves a node's agent from one control plane name to another. */
    public function queueReaddress(string $nodeId, string $from, string $to, string $trigger, string $actor): int
    {
        return $this->queue($this->recordReaddress($nodeId, $from, $to, $trigger, $actor));
    }

    public function recordReaddress(string $nodeId, string $from, string $to, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);

        return $this->operations->create($node->organization_id, 'readdress', "Point {$nodeId} at {$to}", $trigger, $actor,
            ['rewrite', 'reconnect', 'verify'], nodeId: $nodeId, meta: ['from' => $from, 'to' => $to]);
    }

    /**
     * Creates a Droplet and brings it all the way to a working server:
     * preflight, create, wait for the agent to enroll, install the stack.
     *
     * @param  array{region: string, size: string}  $spec
     */
    /** @param  list<int>|null  $keys  SSH keys to install, picked by the user; null for the organisation's defaults. */
    public function queueProvision(string $organizationId, string $slug, array $spec, string $trigger, string $actor, ?array $keys = null): int
    {
        $host = ($spec['role'] ?? null) === \App\Lib\Providers\Incus\IncusHost::ROLE;

        return $this->queue($this->operations->create($organizationId, 'provision', $host ? "Create Incus host {$slug}" : "Create server {$slug}", $trigger, $actor,
            ['preflight', 'create', 'enroll', $host ? 'incus' : 'stack'], nodeId: "{$slug}.{$organizationId}", meta: ['slug' => $slug, 'spec' => $spec] + ($keys !== null ? ['keys' => array_values($keys)] : [])));
    }

    /**
     * A service on a server made for it: the server first (a provision operation), then the install on it. The inputs are
     * held here in the clear, so a template that asks for a secret is refused for this path.
     *
     * @param  array<string, mixed>  $inputs
     * @param  array{slug: string, provider: string, region: string, size: string}  $server
     */
    public function queueServiceCreate(string $organizationId, string $template, string $name, array $inputs, array $server, ?int $project, bool $acknowledged, bool $portsApproved, string $role, string $actor): int
    {
        return $this->queue($this->operations->create($organizationId, 'service_create', "Create {$name} on a new server", 'console', $actor, ['server', 'service'],
            meta: ['template' => $template, 'name' => $name, 'inputs' => $inputs, 'server' => $server, 'project' => $project, 'acknowledged' => $acknowledged,
                'portsApproved' => $portsApproved, 'role' => $role, 'done' => [], 'children' => []]));
    }

    /** Makes a server for an application and adds it to it, as a web or a worker server: one operation, two child operations. */
    public function queueSiteServerCreate(int $siteId, array $server, string $role, string $actor): int
    {
        if (! in_array($role, [Sites::ROLE_WEB, Sites::ROLE_WORKER], true)) {
            throw new \InvalidArgumentException('A server is added as a web server or a worker server.');
        }
        $site = $this->site($siteId);
        \App\Lib\Containers\ContainerApps::refuse($site, 'servers');

        return $this->queue($this->operations->create($site->organization_id, 'site_server_create', "Make a new {$role} server for {$site->name}", 'console', $actor, ['server', 'add'],
            siteId: $site->id, nodeId: $site->node_id, meta: ['server' => $server, 'role' => $role, 'done' => [], 'children' => []]));
    }

    /** The IPv4 ingress on an Incus host: created like any server, then set up as the ingress instead of with the stack. */
    public function queueIngressCreate(string $organizationId, string $slug, string $actor): int
    {
        return $this->queue($this->operations->create($organizationId, 'provision', 'Create the IPv4 ingress on the Incus host', 'connect', $actor,
            ['preflight', 'create', 'enroll', 'ingress'], nodeId: "{$slug}.{$organizationId}",
            meta: ['slug' => $slug, 'spec' => ['provider' => 'incus', 'region' => 'host', 'size' => 'small', 'role' => 'ingress']]));
    }

    /** Rewrites the ingress's routes from the domains and servers on its host. */
    public function queueIngressSync(string $nodeId, string $trigger, string $actor): int
    {
        $node = $this->node($nodeId);

        return $this->queue($this->operations->create($node->organization_id, 'ingress_sync', 'Update the IPv4 ingress routes', $trigger, $actor, ['apply'], nodeId: $nodeId));
    }

    /**
     * Runs an operation to its end and records how it ended. Never throws:
     * the outcome is the record.
     *
     * @param  (Closure(string): void)|null  $echo  Each log line as it is written, for the CLI.
     */
    public function run(int $id, ?Closure $echo = null): string
    {
        $operation = $this->operations->find($id) ?? throw new RuntimeException("No operation {$id}.");
        $echo ??= static function (string $line): void {};

        // A job can outlive its operation's outcome: retried, or released
        // while waiting and the operation finished meanwhile. Never run an
        // operation twice.
        if (in_array($operation->status, ['succeeded', 'failed'], true)) {
            return $operation->status;
        }

        $stage = function (string $name) use ($id, $echo): void {
            $this->operations->stage($id, $name);
            $echo("== {$name}");
        };
        $log = function (string $action, string $text) use ($id, $echo): void {
            $this->operations->log($id, $action, $text);
            $echo(sprintf('   %-7s %s', $action, $text));
        };

        $this->operations->start($id);

        try {
            $fields = match ($operation->kind) {
                'deploy' => $this->deploy($operation, $stage, $log),
                'rollback' => $this->rollback($operation, $stage, $log),
                'https' => $this->https($operation, $stage, $log),
                'domain' => $this->domain($operation, $stage, $log),
                'web_server' => $this->webServer($operation, $stage, $log),
                'env_apply' => $this->envApply($operation, $stage, $log),
                'scheduler' => $this->scheduler($operation, $stage, $log),
                'server_script' => $this->serverScript($operation, $stage, $log),
                'stack' => $this->installStack($operation, $stage, $log),
                'agent_upgrade' => $this->upgradeAgent($operation, $stage, $log),
                'provision' => $this->provision($operation, $stage, $log),
                'readdress' => $this->readdress($operation, $stage, $log),
                'adopt' => $this->adopt($operation, $stage, $log),
                'adopt_ssh' => $this->adoptSsh($operation, $stage, $log),
                'database_create' => $this->databaseCreate($operation, $stage, $log),
                'database_attach' => $this->databaseAttach($operation, $stage, $log),
                'service_install' => $this->serviceInstall($operation, $stage, $log),
                'service_create' => $this->serviceCreate($operation, $stage, $log),
                'site_server_create' => $this->siteServerCreate($operation, $stage, $log),
                'local_service' => $this->localService($operation, $stage, $log),
                'template_service_install' => $this->templateServiceInstall($operation, $stage, $log),
                'template_service_action' => $this->templateServiceAction($operation, $stage, $log),
                'template_service_remove' => $this->templateServiceRemove($operation, $stage, $log),
                'template_service_update' => $this->templateServiceUpdate($operation, $stage, $log),
                'template_service_backup' => $this->templateServiceBackup($operation, $stage, $log),
                'template_service_restore' => $this->templateServiceRestore($operation, $stage, $log),
                'server_containers' => $this->serverContainers($operation, $stage, $log),
                'container_cleanup' => $this->containerCleanup($operation, $stage, $log),
                'database_clone' => $this->databaseClone($operation, $stage, $log),
                'database_destroy' => $this->databaseDestroy($operation, $stage, $log),
                'database_reissue' => $this->databaseReissue($operation, $stage, $log),
                'server_add' => $this->serverAdd($operation, $stage, $log),
                'server_remove' => $this->serverRemove($operation, $stage, $log),
                'load_balancer' => $this->loadBalancer($operation, $stage, $log),
                'storage_attach' => $this->storageAttach($operation, $stage, $log),
                'storage_detach' => $this->storageDetach($operation, $stage, $log),
                'scaler_up' => $this->scalerUp($operation, $stage, $log),
                'scaler_down' => $this->scalerDown($operation, $stage, $log),
                'pool_create' => $this->poolCreate($operation, $stage, $log),
                'pool_member' => $this->poolMember($operation, $stage, $log),
                'pool_join' => $this->poolJoin($operation, $stage, $log),
                'pool_leave' => $this->poolLeave($operation, $stage, $log),
                'pool_resize' => $this->poolResize($operation, $stage, $log),
                'pool_remove' => $this->poolRemove($operation, $stage, $log),
                'base_image' => $this->baseImage($operation, $stage, $log),
                'site_backup' => $this->siteBackup($operation, $stage, $log),
                'preview_create' => $this->previewCreate($operation, $stage, $log),
                'preview_destroy' => $this->previewDestroy($operation, $stage, $log),
                'site_restore' => $this->siteRestore($operation, $stage, $log),
                'site_restore_time' => $this->siteRestoreToTime($operation, $stage, $log),
                'app_create' => $this->appCreate($operation, $stage, $log),
                'app_delete' => $this->appDelete($operation, $stage, $log),
                'server_destroy' => $this->serverDestroy($operation, $stage, $log),
                'server_delete' => $this->serverDelete($operation, $stage, $log, $echo),
                'archive_destroy' => $this->archiveDestroy($operation, $stage, $log),
                'install_php' => $this->installPhp($operation, $stage, $log),
                'php_settings' => $this->phpSettings($operation, $stage, $log),
                'firewall' => $this->firewall($operation, $stage, $log),
                'certificate_check' => $this->certificateCheck($operation, $stage, $log),
                'certificate_renew' => $this->certificateRenew($operation, $stage, $log),
                'certificate_upload' => $this->certificateUpload($operation, $stage, $log),
                'certificate_revert' => $this->certificateRevert($operation, $stage, $log),
                'certificate_autorenew' => $this->certificateAutoRenew($operation, $stage, $log),
                'tailscale_join' => $this->tailscaleJoin($operation, $stage, $log),
                'tailscale_leave' => $this->tailscaleLeave($operation, $stage, $log),
                'admin_access' => $this->adminAccess($operation, $stage, $log),
                'ssh_hardening' => $this->sshHardening($operation, $stage, $log),
                'update_php' => $this->updatePhp($operation, $stage, $log),
                'ingress_sync' => $this->ingressSync($operation, $stage, $log),
                default => throw new RuntimeException("Unknown operation kind {$operation->kind}."),
            };
            $this->operations->succeed($id, $fields);
            $echo('== succeeded');
            if (in_array($operation->kind, \App\Lib\Providers\Incus\Ingress::AFFECTS, true)) {
                app(\App\Lib\Providers\Incus\Ingress::class)->queueSync($operation->organization_id, "after {$operation->kind}", 'system');
            }

            return 'succeeded';
        } catch (OperationWaiting) {
            return 'waiting';
        } catch (Throwable $e) {
            $log('error', $e->getMessage());
            $this->operations->fail($id, $e->getMessage());
            $echo('== failed');
            $this->noteScalerFailure($operation, $e->getMessage());

            return 'failed';
        }
    }

    /**
     * A new application, one step at a time. Each step that is an operation
     * of its own is queued and waited for; this one is re-entered every few
     * seconds until it finishes, so a step runs once however often this
     * does. A failed step stops here, naming it and why.
     *
     * @return array<string, mixed>
     */
    private function appCreate(object $operation, Closure $stage, Closure $log): array
    {
        $id = (int) $operation->id;
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $actor = (string) $operation->actor;

        foreach ($meta['steps'] as $step) {
            if (in_array($step, $meta['done'], true)) {
                continue;
            }
            $stage($step);
            $site = $this->site((int) $operation->site_id);

            if ($step === 'address') {
                $ip = \App\Lib\Sites\NewApplications::publicIp($site->organization_id, $site->node_id)
                    ?? throw new RuntimeException("{$site->node_id} has no public address recorded.");
                $domain = \App\Lib\Sites\NewApplications::sslipDomain($site->name, $ip);
                $site = $this->sites->changeDomain($site, $domain);
                $log('address', "{$domain}, until a domain of its own is added");
                if (($routes = $this->deployer->syncIngress($site)) !== null) {
                    $log('ingress', count($routes).' domain(s) routed by the host\'s IPv4 ingress');
                }
                $meta['done'][] = $step;
                $this->operations->mergeMeta($id, ['done' => $meta['done']]);

                continue;
            }

            // A private repository: wait, without holding a worker, until the host
            // has accepted this application's deploy key. The key is the only
            // thing the user has to do, so it is said once, in the log.
            if ($step === 'access') {
                $repo = \App\Lib\Git\GitRepo::parse($site->repository);
                $private = (string) $this->sites->deployKey($site);
                if ($private !== '' && app(\App\Lib\Git\GitRemote::class)->accepts($repo, $private)) {
                    // The key works; the branch must exist before anything is built, and can be changed in Source settings meanwhile.
                    $existing = app(\App\Lib\Git\GitSources::class)->siteBranches($site, $private);
                    if ($existing !== null && ! in_array($site->branch, $existing, true)) {
                        $problem = "{$repo->path} has no branch called {$site->branch}. It has: ".implode(', ', array_slice($existing, 0, 12)).'. Change the branch in the application\'s Source settings; this step continues by itself.';
                        if (($meta['branch_told'] ?? null) !== $site->branch) {
                            $log('access', $problem);
                            $this->operations->mergeMeta($id, ['branch_told' => $site->branch]);
                        }
                        $this->operations->mergeMeta($id, ['branch_problem' => $problem]);
                        if (now()->greaterThan(\Illuminate\Support\Carbon::parse($operation->created_at)->addMinutes(60))) {
                            throw new RuntimeException("{$repo->path} has no branch called {$site->branch}, and it was not changed within an hour. Nothing was deployed; set the branch in the application's Source settings, then deploy.");
                        }
                        throw new OperationWaiting();
                    }
                    $log('access', "{$repo->host} has accepted the deploy key"." and {$site->branch} exists");
                    $meta['done'][] = $step;
                    $this->operations->mergeMeta($id, ['done' => $meta['done'], 'branch_problem' => null]);

                    continue;
                }
                if (! ($meta['access_told'] ?? false)) {
                    $log('access', "Add this as a read-only deploy key on {$repo->host} for {$repo->path} (leave write access off). This step continues by itself once it is added:");
                    $log('access', trim((string) $site->deploy_key_public));
                    $this->operations->mergeMeta($id, ['access_told' => true]);
                }
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($operation->created_at)->addMinutes(60))) {
                    throw new RuntimeException("The deploy key was not added to {$repo->path} on {$repo->host} within an hour. Nothing was deployed; add it, then use the application's Source settings or create it again.");
                }
                throw new OperationWaiting();
            }

            // The move keeps HTTPS only if the site had it when the move began;
            // a move retried after failing part way did not. Make sure of it.
            if ($step === 'balancer-https' && (bool) $site->tls && ! isset($meta['children'][$step])) {
                $log($step, "https://{$site->domain}/ already has its certificate");
                $meta['done'][] = $step;
                $this->operations->mergeMeta($id, ['done' => $meta['done']]);

                continue;
            }

            $child = $meta['children'][$step] ?? null;
            if ($child === null) {
                $child = match ($step) {
                    'server' => $this->queueProvision($site->organization_id, (string) $meta['server']['slug'],
                        ['provider' => (string) ($meta['provider'] ?? 'digitalocean'), 'region' => (string) $meta['server']['region'], 'size' => (string) $meta['server']['size']], 'app_create', $actor),
                    // A cluster made for this application first, then attached by the name it was given.
                    'cluster-db' => $this->queueDatabaseCreate($site->organization_id, $meta['database']['new']['slug'], $meta['database']['new']['engine'], $meta['database']['new']['region'], $meta['database']['new']['size'], 'app_create', $actor),
                    'cluster-cache' => $this->queueDatabaseCreate($site->organization_id, $meta['cache']['new']['slug'], $meta['cache']['new']['engine'], $meta['cache']['new']['region'], $meta['cache']['new']['size'], 'app_create', $actor),
                    'database' => is_array($meta['database']) ? $this->queueDatabaseAttach((int) $site->id, $this->newCluster($site->organization_id, $meta['database']['new']['slug']), 'app_create', $actor)
                        : (is_string($meta['database']) ? $this->queueLocalService((int) $site->id, substr($meta['database'], 6), 'app_create', $actor)
                            : $this->queueDatabaseAttach((int) $site->id, (int) $meta['database'], 'app_create', $actor)),
                    'cache' => is_array($meta['cache']) ? $this->queueDatabaseAttach((int) $site->id, $this->newCluster($site->organization_id, $meta['cache']['new']['slug']), 'app_create', $actor)
                        : (is_string($meta['cache']) ? $this->queueLocalService((int) $site->id, substr($meta['cache'], 6), 'app_create', $actor)
                            : $this->queueDatabaseAttach((int) $site->id, (int) $meta['cache'], 'app_create', $actor)),
                    'storage' => $this->queueStorageAttach((int) $site->id, 'app_create', $actor),
                    'deploy' => $this->queueDeploy((int) $site->id, 'app_create', $actor),
                    'https' => $this->queueHttps((int) $site->id, 'app_create', $actor),
                    'balancer' => $this->queueLoadBalancer((int) $site->id, 'app_create', $actor),
                    'balancer-address' => $this->queueDomain((int) $site->id, \App\Lib\Sites\NewApplications::sslipDomain($site->name,
                        (string) (app(\App\Lib\Cloud\LoadBalancers::class)->of($site)->attributes['ip'] ?? throw new RuntimeException('The load balancer has no address yet.'))), 'app_create', $actor),
                    'pool' => $this->queuePoolCreate((int) $site->id, $meta['pool'], 'app_create', $actor),
                    'balancer-https' => $this->queueHttps((int) $site->id, 'app_create', $actor),
                    'redeploy' => $this->queueDeploy((int) $site->id, 'app_create', $actor),
                    default => throw new RuntimeException("Unknown step {$step}."),
                };
                $meta['children'][$step] = $child;
                $this->operations->mergeMeta($id, ['children' => $meta['children']]);
                $log($step, "operation #{$child} started");

                throw new OperationWaiting();
            }

            $result = $this->operations->find((int) $child);
            if (in_array($result->status ?? null, ['queued', 'running', 'waiting'], true)) {
                throw new OperationWaiting();
            }
            if (($result->status ?? null) !== 'succeeded') {
                throw new RuntimeException("{$step} failed (operation #{$child}): ".($result->error ?? 'no detail'));
            }
            if ($step === 'storage' && ($meta['storage'] ?? 'disk') === 'disk') {
                // The bucket carries builds to new servers; uploads stay on each server's disk.
                $this->sites->setInjected($this->site((int) $operation->site_id), ['FILESYSTEM_DISK' => 'local'], 'Uploads on the server disk');
            }
            $log($step, "operation #{$child} succeeded");
            $meta['done'][] = $step;
            $this->operations->mergeMeta($id, ['done' => $meta['done']]);
        }

        $site = $this->site((int) $operation->site_id);
        $log('done', "{$site->name} is live at https://{$site->domain}/");

        return [];
    }

    /** The id of the cluster an earlier step made, found by the name it was given. */
    private function newCluster(string $organizationId, string $slug): int
    {
        return (int) (DB::table('resources')->where('organization_id', $organizationId)->where('type', 'database_cluster')->where('slug', $slug)->value('id')
            ?? throw new RuntimeException("The cluster {$slug} was not made."));
    }

    /** The person who pressed the button, or the label of what started it (a push, a schedule). */
    private function actorOf(object $operation): \App\Lib\Audit\Actor
    {
        $who = (string) $operation->actor;

        return $operation->trigger === 'console' && str_contains($who, '@') ? \App\Lib\Audit\Actor::user($who, $who) : \App\Lib\Audit\Actor::system($who !== '' ? $who : 'control-plane');
    }

    /** @return array<string, mixed> */
    private function deploy(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        try {
            $result = $this->deployer->actingAs($this->actorOf($operation))->deploy($this->site((int) $operation->site_id), $stage, $meta['now'] ?? null, $log);
        } finally {
            $this->deployer->actingAs(null);
        }

        return ['release_id' => $result['release'], 'commit' => $result['commit']];
    }

    /**
     * Deploys a new preview, then asks for its certificate. HTTPS failing
     * (the wildcard record not there yet, say) leaves it serving on HTTP
     * and says so, rather than failing a preview that works.
     *
     * @return array<string, mixed>
     */
    private function previewCreate(object $operation, Closure $stage, Closure $log): array
    {
        $stage('database');
        $preview = $this->site((int) $operation->site_id);
        $parent = $this->site((int) $preview->parent_site_id);
        $database = $preview->preview_database ?? 'fresh';
        $clusters = app(\App\Lib\Cloud\DatabaseClusters::class);
        if ($database === 'shared') {
            foreach ($clusters->admitServer($preview, $preview->node_id) as $line) {
                $log('database', $line);
            }
            $log('database', "uses {$parent->name}'s database; migrations and seeders will not run");
        } elseif (! \App\Lib\Sites\Previews::onSqliteFor($parent, $database)) {
            // Re-entered after a wait: the cluster is attached once.
            if ($preview->database_cluster_id === null) {
                $cluster = app(\App\Lib\Resources\ResourceManager::class)->findById((int) $parent->database_cluster_id) ?? throw new RuntimeException("{$parent->name}'s cluster is gone.");
                foreach ($clusters->attach($preview, $cluster) as $line) {
                    $log('database', $line);
                }
            }
            $log('database', $database === 'clone' ? "its own database on {$parent->name}'s cluster, to be filled from {$parent->name}" : "its own empty database on {$parent->name}'s cluster");
        } else {
            $why = match (true) {
                $database === 'sqlite' => '',
                $parent->database_cluster_id === null && ! str_contains(app(\App\Lib\Sites\Sites::class)->env($parent), 'DB_CONNECTION=sqlite') => ": {$parent->name}'s database is on no cluster here to make another of its kind on",
                default => '',
            };
            $log('database', ($database === 'clone' ? "its own SQLite database, to be filled from {$parent->name}" : 'its own empty SQLite database').$why);
        }

        // A clone's copy goes in during the deploy, before its workers start.
        $fields = $this->deploy($operation, $stage, $log);
        $stage('https');
        $site = $this->site((int) $operation->site_id);
        try {
            $this->deployer->secure($site);
            $log('https', "https://{$site->domain}/ is live");
        } catch (Throwable $e) {
            $log('https', "serving on http://{$site->domain}/ only: {$e->getMessage()}");
        }

        return $fields;
    }

    /** @return array<string, mixed> */
    private function previewDestroy(object $operation, Closure $stage, Closure $log): array
    {
        $stage('remove');
        foreach (app(\App\Lib\Sites\Previews::class)->destroy($this->site((int) $operation->site_id)) as $line) {
            $log('preview', $line);
        }

        return [];
    }

    /**
     * Deletes an application from the console. Its own autoscale capacity and
     * balancer go with it; servers and shared clusters stay. Data is kept
     * unless the operation asked for it to be deleted.
     *
     * @return array<string, mixed>
     */
    private function appDelete(object $operation, Closure $stage, Closure $log): array
    {
        $id = (int) $operation->id;
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $deleteData = (bool) ($meta['delete_data'] ?? false);
        $site = $this->site((int) $operation->site_id);

        if ($site->parent_site_id !== null) {
            throw new RuntimeException("{$site->name} is a preview; delete it from its application's Previews tab.");
        }
        if (DB::table('sites')->where('parent_site_id', $site->id)->exists()) {
            throw new RuntimeException("Delete {$site->name}'s previews first, then delete the application.");
        }
        if (DB::table('operations')->where('site_id', $site->id)->where('id', '!=', $id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw new RuntimeException("Another operation is running for {$site->name}. Wait for it to finish.");
        }
        if (! $deleteData && $site->autoscale_pool_id !== null && ! $this->uploadsOnS3($site)) {
            throw new RuntimeException("{$site->name} autoscales and keeps uploads on its own servers; deleting it now would lose them. Tick \"Also delete its database, uploaded files and stored secrets\" or move uploads to Spaces first.");
        }

        // Capacity the application owns goes first, so nothing it created is
        // left billing once the record is gone.
        if ($site->load_balancer_id !== null) {
            $stage('remove');
            app(\App\Lib\Cloud\LoadBalancers::class)->destroy($site);
            $log('balancer', 'load balancer and its certificate deleted');
            $site = $this->site((int) $operation->site_id);
        }
        if ($site->autoscale_pool_id !== null) {
            $stage('remove');
            $members = (\App\Lib\Scaling\ServerScaler::manages($site) ? app(\App\Lib\Scaling\ServerScaler::class) : app(\App\Lib\Cloud\AutoscalePools::class))->destroy($site);
            $joined = DB::table('site_nodes')->where('site_id', $site->id)->whereNotNull('autoscale_pool_id')->pluck('node_id')->all();
            $log('pool', 'deleted, with its Droplets'.($members === [] ? '' : ': '.implode(', ', array_map(fn ($n) => strstr($n, '.', true) ?: $n, $members))));
            $this->leave($this->site($site->id), array_values(array_unique([...$members, ...$joined])), $log);
            $site = $this->site((int) $operation->site_id);
        }

        $nodes = Sites::nodes($site);

        $stage('remove');
        foreach ($nodes as $node) {
            try {
                $this->deployer->unserve($site, $node, keepData: ! $deleteData);
                $log('server', (strstr($node, '.', true) ?: $node).': nginx, workers and schedule stopped'.($deleteData ? ', files removed' : '; files kept'));
            } catch (Throwable $e) {
                // Never deployed: nothing was put on the server, so a server that cannot be reached (one that failed to
                // be made, or never joined) has nothing to remove, and the record can go.
                if ($site->current_release === null) {
                    $log('server', (strstr($node, '.', true) ?: $node).': not reached, and nothing was ever deployed to it ('.$e->getMessage().')');
                } else {
                    throw new RuntimeException("Could not remove {$site->name} from ".(strstr($node, '.', true) ?: $node).": {$e->getMessage()}. Nothing was forgotten; try again.", previous: $e);
                }
            }
            foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->releaseServer($site, $node) as $line) {
                $log('firewall', $line);
            }
        }

        $stage('data');
        if ($deleteData) {
            foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->dropDatabase($site, siteOwned: true) as $line) {
                $log('database', $line);
            }
            if ($site->bucket_id !== null) {
                try {
                    foreach (app(\App\Lib\Cloud\Spaces::class)->remove($site) as $line) {
                        $log('bucket', $line);
                    }
                } catch (Throwable $e) {
                    // The application is going either way; say what is left to delete, and where.
                    $log('bucket', "the Spaces bucket could not be deleted ({$e->getMessage()}); it keeps billing until you delete it under Spaces at DigitalOcean");
                }
            }
        } else {
            $log('data', 'kept at '.Sites::root($site).' on '.count($nodes).' server'.(count($nodes) === 1 ? '' : 's')
                .($site->database_cluster_id !== null ? ', with database '.str_replace('-', '_', $site->name) : ''));
        }

        $stage('forget');
        if ($deleteData) {
            $this->sites->forget($site);
            $log('record', 'application deleted; its code, data and secrets are gone');
        } else {
            $this->sites->archive($site, $nodes);
            $log('record', 'application deleted; its files, data and secrets are kept on the server as retained data');
        }

        return [];
    }

    /**
     * Destroys a Droplet the platform created and cuts its agent off. Refuses
     * while anything still depends on the machine.
     *
     * @return array<string, mixed>
     */
    private function serverDestroy(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $opNode = (string) ($operation->node_id ?? '');
        $slug = (string) ($meta['slug'] ?? ($opNode !== '' ? (strstr($opNode, '.', true) ?: $opNode) : ''));

        $resource = isset($meta['resource'])
            ? app(\App\Lib\Resources\ResourceManager::class)->findById((int) $meta['resource'])
            : ($opNode !== '' ? app(\App\Lib\Resources\ResourceManager::class)->find((string) DB::table('nodes')->where('node_id', $opNode)->value('organization_id'), 'server', $slug) : null);
        if ($resource === null || $resource->externalId === null) {
            throw new RuntimeException("{$slug} is not a Droplet this platform created.");
        }

        // The machine's identity comes from the resource, never from what the
        // operation happens to carry: a destroy queued with no node (audit
        // 2026-11-08, finding 5) must not read as "nothing runs here". The node
        // id is derived the way enrolment names it, and the checks run whether
        // or not the node is connected or its row still exists.
        $nodeId = $opNode !== '' ? $opNode : "{$resource->slug}.{$resource->organizationId}";
        $node = DB::table('nodes')->where('node_id', $nodeId)->first();

        $primary = DB::table('sites')->where('node_id', $nodeId)->pluck('name')->all();
        $secondary = DB::table('sites')->whereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id'))->pluck('name')->all();
        $sites = array_values(array_unique([...$primary, ...$secondary]));
        if ($sites !== []) {
            throw new RuntimeException("{$nodeId} still serves ".implode(', ', $sites).'. Delete those applications first.');
        }
        if (DB::table('operations')->where('node_id', $nodeId)->where('id', '!=', $operation->id)->whereIn('status', ['queued', 'running'])->exists()) {
            throw new RuntimeException("Another operation is running on {$nodeId}. Wait for it to finish.");
        }
        if (DB::table('site_nodes')->where('node_id', $nodeId)->whereNotNull('autoscale_pool_id')->exists()) {
            throw new RuntimeException("{$nodeId} is in an autoscale pool; stop autoscaling that application instead.");
        }
        if (self::poolOf($resource) !== null) {
            throw new RuntimeException("{$slug} is in an autoscale pool; stop autoscaling that application instead.");
        }
        if (($app = $this->archivedOn($resource->organizationId, $slug)) !== null) {
            throw new RuntimeException("{$slug} holds retained data for {$app}. Delete that retained data first.");
        }

        if (($node->role ?? null) === \App\Lib\Providers\Incus\IncusHost::ROLE) {
            $stage('host');
            app(\App\Lib\Providers\Incus\IncusHost::class)->teardown($resource->organizationId, $nodeId, $log, (string) $operation->actor);
        }

        $stage('destroy');
        app(\App\Lib\Cloud\ServerProvisioner::class)->destroy($resource, \App\Lib\Audit\Actor::system((string) $operation->actor));
        $selfHosted = app(\App\Lib\Providers\Providers::class)->has((string) $resource->provider)
            && app(\App\Lib\Providers\Providers::class)->get((string) $resource->provider) instanceof \App\Lib\Providers\SelfHosted;
        $log('server', "{$resource->externalId} destroyed".($selfHosted ? '; its share of the host is free again' : '; billing stops'));

        if ($node !== null) {
            try {
                app(\App\Lib\Servers\NodeRemoval::class)->revoke($node, 'destroyed');
                $log('server', 'removed from the platform; its certificate is revoked');
            } catch (Throwable $e) {
                $log('server', 'destroyed; the certificate revocation will apply at the next gateway sync: '.$e->getMessage());
            }
        }

        return [];
    }

    /**
     * Runs a server deletion: applications (and their previews), retained data, the database server, then the machine.
     * Each step is an operation of its own, run here in order; the first failure stops it with the record failed and
     * retryable. Retrying works from the list as it is now, so what is already gone is not done twice.
     *
     * @return array<string, mixed>
     */
    private function serverDelete(object $operation, Closure $stage, Closure $log, Closure $echo): array
    {
        $id = (int) $operation->id;
        $nodeId = (string) $operation->node_id;
        $actor = (string) $operation->actor;
        $choices = (json_decode((string) $operation->meta, true) ?: [])['choices'] ?? [];
        $slug = strstr($nodeId, '.', true) ?: $nodeId;

        if (! DB::table('nodes')->where('node_id', $nodeId)->exists()) {
            $log('server', "{$slug} is already removed");

            return [];
        }
        $under = \App\Lib\Servers\Underneath::of($nodeId, $id);
        if ($under['blockers'] !== []) {
            throw new RuntimeException(implode(' ', $under['blockers']));
        }
        try {
            self::assertChosen($under['items'], $choices);
        } catch (\InvalidArgumentException $e) {
            throw new RuntimeException($e->getMessage().' It appeared after you chose; review the list and delete again.');
        }
        // The end of an archived server's life: it is brought back under management (a record only) so its children can run.
        if (\App\Lib\Servers\ServerArchiving::isNodeArchived($nodeId)) {
            app(\App\Lib\Servers\ServerArchiving::class)->restoreServer((string) $operation->organization_id, $nodeId, \App\Lib\Audit\Actor::system($actor));
            $log('server', "{$slug} was archived: brought back under management so it can be deleted");
        }
        $run = function (int $child, string $what) use ($log, $echo): void {
            $status = $this->run($child, $echo);
            if ($status !== 'succeeded') {
                $why = (string) DB::table('operations')->where('id', $child)->value('error');
                throw new RuntimeException("{$what} did not finish ({$status}): {$why} Nothing after it was started. Fix that and retry.");
            }
            $log('step', "{$what}: done (operation #{$child})");
        };

        $stage('apps');
        foreach ($under['items'] as $item) {
            if (! in_array($item['type'], ['application', 'archived_application'], true)) {
                continue;
            }
            $siteId = (int) substr($item['key'], 4);
            $choice = $choices[$item['key']];
            $site = $this->site($siteId);
            if ($site->archived_at !== null) {
                app(\App\Lib\Sites\Archiving::class)->restore($site->organization_id, $siteId, \App\Lib\Audit\Actor::system($actor), checkHost: false);
                $log('app', "{$site->name}: brought back under management so it can be deleted");
            }
            if ($choice === 'detach') {
                $run($this->queueServerRemove($siteId, $nodeId, 'server_delete', $actor, dispatch: false), "Removing {$site->name} from {$slug}");

                continue;
            }
            foreach (DB::table('sites')->where('parent_site_id', $siteId)->get(['id', 'name']) as $preview) {
                $run($this->queuePreviewDestroy((int) $preview->id, 'server_delete', $actor, false), "Deleting preview {$preview->name}");
            }
            if ($choice === 'backup') {
                $run($this->queueSiteBackup($siteId, 'manual', 'server_delete', $actor, false), "Backing up {$site->name}'s database");
            }
            $run($this->queueAppDelete($siteId, true, 'server_delete', $actor, false), "Deleting {$site->name} and erasing its data");
        }

        $stage('retained');
        foreach ($under['items'] as $item) {
            if ($item['type'] === 'retained_data') {
                $run($this->queueArchiveDestroy((int) substr($item['key'], 8), 'server_delete', $actor, false), "Erasing retained data for {$item['name']}");
            }
        }

        $stage('databases');
        foreach ($under['items'] as $item) {
            if ($item['type'] === 'database_server') {
                $run($this->queueDatabaseDestroy((int) substr($item['key'], 8), 'server_delete', $actor, false), "Deleting database server {$item['name']}");
            }
        }

        $stage('server');
        $node = DB::table('nodes')->where('node_id', $nodeId)->first();
        $resource = DB::table('resources')->where('organization_id', $operation->organization_id)->where('type', 'server')->where('slug', $slug)->first();
        $removal = app(\App\Lib\Servers\NodeRemoval::class);
        if ($node === null) {
            $log('server', "{$slug} was removed with its database server");
        } elseif ($resource !== null && $resource->state === 'deleted') {
            $removal->removeStale($nodeId, $actor);
            $log('server', "{$slug} was already gone at the provider; its record is removed");
        } elseif ($resource !== null && $resource->external_id !== null) {
            $this->serverDestroy($operation, $stage, $log);
        } else {
            $result = $removal->remove($nodeId, $actor);
            $log('server', "{$slug} disconnected: certificate revoked".($result['uninstalled'] ? ', agent uninstalled' : '').'. The machine keeps running and billing where it is hosted.');
        }

        return [];
    }

    /**
     * Permanently removes an archived application's directory on every server
     * that still exists, its own database, and its secrets.
     *
     * @return array<string, mixed>
     */
    private function archiveDestroy(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $archive = DB::table('site_archives')->where('id', (int) ($meta['archive'] ?? 0))->first()
            ?? throw new RuntimeException('The retained data is already gone.');
        $servers = json_decode((string) $archive->servers, true) ?: [];

        $stage('files');
        foreach ($servers as $server) {
            if (! DB::table('nodes')->where('node_id', $server)->exists()) {
                $log('skip', (strstr($server, '.', true) ?: $server).' is gone; its files with it');

                continue;
            }
            if (! $this->gateway->isConnected($server)) {
                throw new RuntimeException("{$server} is offline; its copy of {$archive->name} cannot be removed yet. Try again when it reconnects.");
            }
            $result = $this->gateway->run($archive->organization_id, $server, 'apply_recipe',
                ['spec' => ['name' => 'archive-remove', 'steps' => [['id' => 'files', 'type' => 'absent', 'path' => $archive->directory]]]],
                timeoutSeconds: 300, reason: "Deleting retained data for {$archive->name}");
            if (($result['status'] ?? null) !== 'succeeded') {
                throw new RuntimeException((string) ($result['error'] ?? "Could not remove {$archive->directory} on {$server}."));
            }
            $log('files', (strstr($server, '.', true) ?: $server).": {$archive->directory} deleted");
        }

        $stage('database');
        if ($archive->database_cluster_id !== null) {
            $pseudo = (object) ['name' => $archive->name, 'organization_id' => $archive->organization_id,
                'database_cluster_id' => $archive->database_cluster_id, 'parent_site_id' => null, 'preview_database' => null];
            foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->dropDatabase($pseudo, siteOwned: true) as $line) {
                $log('database', $line);
            }
        }

        $stage('secrets');
        $this->sites->forgetArchive($archive);
        $log('record', "retained data for {$archive->name} deleted; its name is free if no old cloud resources remain");

        return [];
    }

    /**
     * Makes the login user, its password and its keys on a server match what is
     * saved. A key marked for removal goes; one asked for arrives.
     *
     * @return array<string, mixed>
     */
    private function adminAccess(object $operation, Closure $stage, Closure $log): array
    {
        $nodeId = (string) $operation->node_id;
        $node = DB::table('nodes')->where('node_id', $nodeId)->first() ?? throw new RuntimeException("No server {$nodeId}.");
        $access = app(AdminAccess::class);

        $stage('apply');
        $admin = $access->forStack($node->organization_id, $nodeId);
        $this->node = $nodeId;
        $this->applyRecipe((object) ['organization_id' => $node->organization_id, 'node_id' => $nodeId, 'name' => 'admin'], $this->stack->adminRecipe($admin), 300);
        $this->node = null;
        $access->applied($nodeId);
        $log('access', "{$admin['user']} on ".(strstr($nodeId, '.', true) ?: $nodeId).' has '.count($admin['keys']).' SSH '.(count($admin['keys']) === 1 ? 'key' : 'keys'));

        return [];
    }

    /** @return array<string, mixed> */
    private function sshHardening(object $operation, Closure $stage, Closure $log): array
    {
        $nodeId = (string) $operation->node_id;
        $node = DB::table('nodes')->where('node_id', $nodeId)->first() ?? throw new RuntimeException("No server {$nodeId}.");
        $on = (bool) (json_decode((string) $operation->meta, true)['on'] ?? false);
        // Checked again here: a key may have been taken off since the button was pressed.
        if ($on && ($why = AdminAccess::hardenBlock($nodeId)) !== null) {
            throw new RuntimeException($why);
        }

        $stage('apply');
        $this->node = $nodeId;
        $this->applyRecipe((object) ['organization_id' => $node->organization_id, 'node_id' => $nodeId, 'name' => 'ssh'], $this->stack->hardeningRecipe($on), 120);
        $this->node = null;
        DB::table('nodes')->where('node_id', $nodeId)->update(['ssh_hardened' => $on]);
        $who = (string) ($operation->actor ?? 'console');
        app(\App\Lib\Audit\AuditLog::class)->record($node->organization_id, \App\Lib\Audit\Actor::user($who, $who), $on ? 'node.ssh_hardened' : 'node.ssh_unhardened', resourceType: 'node', resourceId: $nodeId);
        $log('ssh', $on ? 'root and password login are off; only keys get in' : 'root and password login are allowed again');

        return [];
    }

    /**
     * Writes one PHP branch's saved settings to a server. FPM checks them first
     * and the old files are put back if it objects, so a bad line cannot take
     * every application on the server down at the next reload.
     *
     * @return array<string, mixed>
     */
    private function firewall(object $operation, Closure $stage, Closure $log): array
    {
        $nodeId = (string) $operation->node_id;
        $node = DB::table('nodes')->where('node_id', $nodeId)->first() ?? throw new RuntimeException("No server {$nodeId}.");
        $settings = \App\Lib\Servers\Firewall::settings($nodeId);

        // Closing a port to everything but the tailnet is only safe while the server is on it.
        if ($settings['enabled'] && ($settings['ssh_private'] || $settings['web_private']) && ! $this->tailnetOnline($node->organization_id, $nodeId)) {
            throw new RuntimeException('This server is not online in the tailnet, so SSH and the web ports were not closed to the public. Join the tailnet again, or turn tailnet only off.');
        }

        $stage('apply');
        $this->node = $nodeId;
        $this->applyRecipe((object) ['organization_id' => $node->organization_id, 'node_id' => $nodeId, 'name' => 'firewall'], \App\Lib\Servers\Firewall::recipe($settings + ['extra' => app(\App\Lib\Services\Services::class)->extraPortsOn($nodeId), 'ingress' => \App\Lib\Providers\Incus\Ingress::sourceForNode($node->organization_id, $nodeId)]), 300);
        $this->node = null;
        $log('firewall', $settings['enabled']
            ? 'on: SSH from '.($settings['ssh'] === [] ? 'anywhere' : implode(', ', $settings['ssh'])).'; web from '.($settings['web'] === [] ? 'anywhere' : implode(', ', $settings['web']))
            : 'off');

        return [];
    }

    /** @return array<string, mixed> */
    private function ingressSync(object $operation, Closure $stage, Closure $log): array
    {
        $stage('apply');
        $this->applyIngress($operation->organization_id, (string) $operation->node_id, $log);

        return [];
    }

    /** Writes the ingress's nginx with every route on its host, checked by nginx before it is reloaded. */
    private function applyIngress(string $organizationId, string $nodeId, Closure $log): void
    {
        $routes = \App\Lib\Providers\Incus\Ingress::routes($organizationId);
        $this->node = $nodeId;
        try {
            $this->applyRecipe((object) ['organization_id' => $organizationId, 'node_id' => $nodeId, 'name' => 'ingress'], \App\Lib\Providers\Incus\Ingress::recipe($routes), 600);
        } finally {
            $this->node = null;
        }
        $log('ingress', $routes === [] ? 'no domains on the host yet' : count($routes).' domain(s): '.implode(', ', array_keys($routes)));
    }

    /** @return array<string, mixed> */
    private function certificateCheck(object $operation, Closure $stage, Closure $log): array
    {
        $stage('read');
        $site = $this->site((int) $operation->site_id);
        if (! (bool) $site->tls) {
            throw new RuntimeException("{$site->name} has no certificate: HTTPS is off.");
        }
        $expires = $this->deployer->readCertificateExpiry($site);
        $log('certificate', 'ends '.$expires->utc()->format('j M Y H:i').' UTC');
        if ($operation->trigger === 'schedule') {
            return []; // The daily read is not a person's action; one audit record per site per day would bury the rest.
        }
        app(\App\Lib\Audit\AuditLog::class)->record($site->organization_id, \App\Lib\Audit\Actor::user((string) $operation->actor, (string) $operation->actor),
            'site.certificate_checked', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['expires_at' => $expires->toIso8601String()]);

        return [];
    }

    /**
     * certbot's deploy hook reloads nginx, so "reload" is part of the renew
     * stage. Certbot's own failure text (rate limits included) ends up in the
     * operation's error; nothing is retried.
     *
     * @return array<string, mixed>
     */
    private function certificateRenew(object $operation, Closure $stage, Closure $log): array
    {
        $site = $this->site((int) $operation->site_id);
        $this->assertRenewable($site);

        $stage('renew');
        $this->deployer->renewCertificate($site);
        $log('certbot', 'renewed; nginx reloaded by the deploy hook');

        $stage('expiry');
        $expires = $this->deployer->readCertificateExpiry($site);
        $log('certificate', 'now ends '.$expires->utc()->format('j M Y H:i').' UTC');

        $this->syncBalancerAndAudit($operation, $site, $stage, $log, 'site.certificate_renewed', ['expires_at' => $expires->toIso8601String()]);

        return [];
    }

    /**
     * The node is changed first and the database only after it succeeded, so
     * the page never says off while certbot would still renew.
     *
     * @return array<string, mixed>
     */
    private function certificateAutoRenew(object $operation, Closure $stage, Closure $log): array
    {
        $site = $this->site((int) $operation->site_id);
        $this->assertRenewable($site);
        $on = (bool) (json_decode((string) $operation->meta, true)['on'] ?? true);

        $stage('apply');
        $this->deployer->setCertificateAutoRenew($site, $on);
        DB::table('sites')->where('id', $site->id)->update(['cert_auto_renew' => $on, 'updated_at' => now()]);
        $log('certbot', 'automatic renewal '.($on ? 'on' : 'off').($on ? '' : '; the expiry warning still applies'));
        app(\App\Lib\Audit\AuditLog::class)->record($site->organization_id, \App\Lib\Audit\Actor::user((string) $operation->actor, (string) $operation->actor),
            'site.certificate_autorenew', resourceType: 'site', resourceId: "{$site->node_id}/{$site->name}", metadata: ['auto_renew' => $on]);

        return [];
    }

    /**
     * Applies the held certificate to every server. The held copy is deleted
     * as soon as the apply ends, success or failure; the chain and key are
     * checked again here (the form is never trusted) and never logged.
     *
     * @return array<string, mixed>
     */
    private function certificateUpload(object $operation, Closure $stage, Closure $log): array
    {
        $site = $this->site((int) $operation->site_id);
        $vault = app(\App\Lib\Vault\Vault::class);
        $name = self::heldCertificate((int) $operation->id);

        try {
            $stage('validate');
            $held = json_decode((string) $vault->get($site->organization_id, $name), true);
            if (! is_array($held) || ! isset($held['chain'], $held['key'])) {
                throw new RuntimeException('The uploaded certificate is no longer held. Upload it again.');
            }
            try {
                $bundle = \App\Lib\Sites\CertificateBundle::check((string) $held['chain'], (string) $held['key'], $site->domain);
            } catch (\InvalidArgumentException $e) {
                throw new RuntimeException($e->getMessage());
            }
            unset($held);
            $log('certificate', 'issued by '.$bundle['issuer'].'; covers '.implode(', ', $bundle['names']).'; ends '.$bundle['expires_at']->utc()->format('j M Y H:i').' UTC');

            $stage('apply');
            $this->deployer->uploadCertificate($site, $bundle, $log);
        } finally {
            $vault->forget($site->organization_id, $name);
        }

        $this->syncBalancerAndAudit($operation, $this->site($site->id), $stage, $log, 'site.certificate_uploaded',
            ['issuer' => $bundle['issuer'], 'names' => $bundle['names'], 'expires_at' => $bundle['expires_at']->toIso8601String()]);

        return [];
    }

    /** @return array<string, mixed> */
    private function certificateRevert(object $operation, Closure $stage, Closure $log): array
    {
        $site = $this->site((int) $operation->site_id);
        $this->assertCustomCertificate($site);

        $stage('revert');
        $this->deployer->revertToLetsEncrypt($site, $log);

        $site = $this->site($site->id);
        $this->syncBalancerAndAudit($operation, $site, $stage, $log, 'site.certificate_reverted',
            ['issuer' => "Let's Encrypt", 'names' => [$site->domain], 'expires_at' => $site->cert_expires_at !== null ? \Illuminate\Support\Carbon::parse($site->cert_expires_at)->toIso8601String() : null]);

        return [];
    }

    /** Asks the server itself whether Tailscale is running and connected. @return array{online: bool, address: ?string, name: ?string} */
    private function tailnetStatus(string $organizationId, string $nodeId): array
    {
        try {
            $output = (string) ($this->gateway->run($organizationId, $nodeId, 'exec', ['name' => 'sh', 'args' => ['-c', 'tailscale status --json 2>/dev/null || true']],
                reason: 'Read the tailnet status')['detail']['output'] ?? '');
        } catch (Throwable) {
            return ['online' => false, 'address' => null, 'name' => null];
        }

        return \App\Lib\Servers\Tailscale::parseStatus($output);
    }

    private function tailnetOnline(string $organizationId, string $nodeId): bool
    {
        return $this->tailnetStatus($organizationId, $nodeId)['online'];
    }

    /**
     * Installs Tailscale and joins the organisation's tailnet with its saved auth key.
     *
     * @return array<string, mixed>
     */
    private function tailscaleJoin(object $operation, Closure $stage, Closure $log): array
    {
        $nodeId = (string) $operation->node_id;
        $node = $this->node($nodeId);
        $secret = app(\App\Lib\Cloud\CredentialStore::class)->secret($node->organization_id, \App\Lib\Servers\Tailscale::PROVIDER, "Join {$nodeId} to the tailnet",
            \App\Lib\Audit\Actor::user((string) $operation->actor, (string) $operation->actor));
        $site = (object) ['organization_id' => $node->organization_id, 'node_id' => $nodeId, 'name' => 'tailscale'];

        $stage('join');
        $this->node = $nodeId;
        try {
            $this->applyRecipe($site, \App\Lib\Servers\Tailscale::joinRecipe(\App\Lib\Servers\Tailscale::hostname($nodeId), (string) ($secret['authkey'] ?? '')), 600);
        } finally {
            // The key file must not outlive the attempt.
            try {
                $this->applyRecipe($site, \App\Lib\Servers\Tailscale::cleanupRecipe(), 60);
            } catch (Throwable) {
            }
            $this->node = null;
        }

        $stage('verify');
        $status = $this->tailnetStatus($node->organization_id, $nodeId);
        if (! $status['online']) {
            throw new RuntimeException('Tailscale installed but the server did not come online in the tailnet. Check that the auth key is valid, not expired, and not used up.');
        }
        \App\Lib\Servers\Tailscale::save($nodeId, ['address' => (string) $status['address'], 'name' => (string) $status['name'], 'joined_at' => now()->toIso8601String()]);
        $log('tailnet', "joined as {$status['name']} ({$status['address']})");

        return [];
    }

    /**
     * Takes a server off the tailnet. Tailnet only is switched off first, so it is never left unreachable.
     *
     * @return array<string, mixed>
     */
    private function tailscaleLeave(object $operation, Closure $stage, Closure $log): array
    {
        $nodeId = (string) $operation->node_id;
        $node = $this->node($nodeId);
        $site = (object) ['organization_id' => $node->organization_id, 'node_id' => $nodeId, 'name' => 'tailscale'];

        $stage('firewall');
        $settings = \App\Lib\Servers\Firewall::settings($nodeId);
        if ($settings['ssh_private'] || $settings['web_private']) {
            \App\Lib\Servers\Firewall::save($nodeId, $settings['enabled'], $settings['ssh'], $settings['web']);
            if ($settings['enabled']) {
                $this->node = $nodeId;
                $this->applyRecipe($site, \App\Lib\Servers\Firewall::recipe(\App\Lib\Servers\Firewall::settings($nodeId)), 300);
                $this->node = null;
            }
            $log('firewall', 'tailnet only switched off; SSH and the web ports are open as the lists say');
        }

        $stage('leave');
        $this->node = $nodeId;
        $this->applyRecipe($site, \App\Lib\Servers\Tailscale::leaveRecipe(), 120);
        $this->node = null;
        \App\Lib\Servers\Tailscale::save($nodeId, null);
        $log('tailnet', 'left');

        return [];
    }

    private function phpSettings(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $version = (string) ($meta['version'] ?? '');
        $nodeId = (string) $operation->node_id;
        $node = DB::table('nodes')->where('node_id', $nodeId)->first() ?? throw new RuntimeException("No server {$nodeId}.");

        $stage('apply');
        $this->node = $nodeId;
        $this->applyRecipe((object) ['organization_id' => $node->organization_id, 'node_id' => $nodeId, 'name' => "php{$version}"],
            $this->stack->phpSettingsRecipe($version, PhpSettings::for($nodeId, $version)), 600);
        $this->node = null;
        $log('php', "php{$version} settings applied and PHP-FPM reloaded on ".(strstr($nodeId, '.', true) ?: $nodeId));

        return [];
    }

    /**
     * Installs an additional PHP branch on one server, in its own FPM pool.
     *
     * @return array<string, mixed>
     */
    private function installPhp(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $version = (string) ($meta['version'] ?? '');
        $nodeId = (string) $operation->node_id;
        $node = DB::table('nodes')->where('node_id', $nodeId)->first() ?? throw new RuntimeException("No server {$nodeId}.");

        $stage('install');
        $this->node = $nodeId;
        $os = ServerStack::osOf($nodeId);
        // Built from source, it takes as long as the compiler does on this server's cores.
        $this->applyRecipe((object) ['organization_id' => $node->organization_id, 'node_id' => $nodeId, 'name' => "php{$version}"],
            $this->stack->phpInstallRecipe($version, PhpSettings::for($nodeId, $version), $os), ServerStack::phpFromSource($os, $version) ? 3600 : 1800);
        DB::table('server_php_versions')->updateOrInsert(['node_id' => $nodeId, 'version' => $version],
            ['updated_at' => now(), 'created_at' => now()]);
        $this->node = null;
        $this->recordPhpPatches($node->organization_id, $nodeId);
        $log('php', "php{$version} installed and serving in its own FPM pool");

        return [];
    }

    /**
     * Applies patch updates to one PHP branch on a server. Every application
     * on that server using the branch shares the runtime, so its workers are
     * restarted onto it and listed.
     *
     * @return array<string, mixed>
     */
    private function updatePhp(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $version = (string) ($meta['version'] ?? '');
        $nodeId = (string) $operation->node_id;
        $node = DB::table('nodes')->where('node_id', $nodeId)->first() ?? throw new RuntimeException("No server {$nodeId}.");

        $stage('update');
        $this->node = $nodeId;
        $fromSource = ServerStack::phpFromSource(ServerStack::osOf($nodeId), $version);
        $reload = ['id' => "reload-php{$version}-fpm", 'type' => 'service', 'name' => "php{$version}-fpm", 'state' => 'reloaded'];
        // A branch built from source is updated by building the latest patch again; a packaged one, by its packages.
        $steps = [$this->stack->aptHealthStep(), ...($fromSource ? [...$this->stack->phpBuildSteps($version), $reload] : [
            ['id' => 'apt-update', 'type' => 'command', 'name' => 'sh', 'args' => ['-c', 'apt-get update -q'], 'description' => 'package lists refreshed'],
            ['id' => 'upgrade', 'type' => 'command', 'name' => 'sh',
                'args' => ['-c', 'DEBIAN_FRONTEND=noninteractive apt-get install -y --only-upgrade '.implode(' ', ServerStack::phpPackages($version))],
                'description' => "php{$version} packages upgraded"],
            $reload,
        ])];
        $this->applyRecipe((object) ['organization_id' => $node->organization_id, 'node_id' => $nodeId, 'name' => "php{$version}"],
            ['name' => "php-update-{$version}", 'steps' => $steps], $fromSource ? 3600 : 1800);

        $stage('workers');
        $affected = DB::table('sites')->where('organization_id', $node->organization_id)->where('php_version', $version)
            ->where(fn ($q) => $q->where('node_id', $nodeId)
                ->orWhereIn('id', DB::table('site_nodes')->where('node_id', $nodeId)->select('site_id')))
            ->orderBy('name')->get(['id', 'name', 'node_id', 'php_version']);
        foreach ($affected as $site) {
            $binary = ServerStack::phpBinary(Sites::phpVersion($site));
            $artisan = Sites::current($site).'/artisan';
            try {
                $this->gateway->run($node->organization_id, $nodeId, 'exec', ['name' => 'sh',
                    'args' => ['-c', "[ ! -f {$artisan} ] || runuser -u ".ServerStack::DEPLOY_USER." -- {$binary} {$artisan} queue:restart || true"]],
                    reason: "Restart {$site->name}'s workers onto php{$version}");
            } catch (Throwable) {
                // Best effort: the patch is applied either way.
            }
            $log('worker', "{$site->name}'s workers restart onto the updated php{$version}");
        }
        $this->node = null;
        $this->recordPhpPatches($node->organization_id, $nodeId);
        $patch = json_decode((string) DB::table('nodes')->where('node_id', $nodeId)->value('php_patches'), true)[$version] ?? null;
        if ($patch !== null) {
            $log('php', "php{$version} is now {$patch}");
        }
        $log('php', $affected === []
            ? "php{$version} updated; no application on ".$nodeId.' uses it'
            : "php{$version} updated for ".$affected->count().' application(s): '.$affected->pluck('name')->implode(', '));

        return [];
    }

    /** @return array<string, mixed> */
    private function rollback(object $operation, Closure $stage, Closure $log): array
    {
        $stage('rollback');
        $site = $this->site((int) $operation->site_id);
        try {
            $current = $this->deployer->actingAs($this->actorOf($operation))->rollback($site, $log);
        } finally {
            $this->deployer->actingAs(null);
        }
        if (! \App\Lib\Sites\Runtimes::isContainer($site)) {
            $stage('workers');
        }

        return ['release_id' => $current];
    }

    /** @return array<string, mixed> */
    private function https(object $operation, Closure $stage, Closure $log): array
    {
        $stage('certificate');
        $site = $this->deployer->secure($this->site((int) $operation->site_id));
        if ($site->load_balancer_id !== null) {
            $log('balancer', app(\App\Lib\Cloud\LoadBalancers::class)->syncCertificate($site));
        }
        $log('https', "https://{$site->domain}/ is live");

        return [];
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    private function webServer(object $operation, Closure $stage, Closure $log): array
    {
        $stage('apply');
        $this->deployer->applyWebServer($this->site((int) $operation->site_id), $log);

        return [];
    }

    private function envApply(object $operation, Closure $stage, Closure $log): array
    {
        $stage('apply');
        $this->deployer->applyVariables($this->site((int) $operation->site_id), $log);

        return [];
    }

    private function serverScript(object $operation, Closure $stage, Closure $log): array
    {
        $stage('run');
        $this->deployer->applyServerScript($this->site((int) $operation->site_id), $log);

        return [];
    }

    private function scheduler(object $operation, Closure $stage, Closure $log): array
    {
        $stage('apply');
        $this->deployer->applyScheduler($this->site((int) $operation->site_id), $log);

        return [];
    }

    private function domain(object $operation, Closure $stage, Closure $log): array
    {
        $stage('move');
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $site = $this->deployer->moveDomain($this->site((int) $operation->site_id), (string) $meta['domain']);
        if ($site->load_balancer_id !== null && (bool) $site->tls) {
            $log('balancer', app(\App\Lib\Cloud\LoadBalancers::class)->syncCertificate($site));
        }
        $log('domain', ((bool) $site->tls ? 'https' : 'http')."://{$site->domain}/ is live; deploy to cache the new APP_URL");

        return [];
    }

    /** @return array<string, mixed> */
    private function installStack(object $operation, Closure $stage, Closure $log, string $stageName = 'install'): array
    {
        $stage($stageName);
        $admin = app(AdminAccess::class)->forStack($operation->organization_id, $operation->node_id);
        // The release decides which PHP can be had, so it is read first.
        if (($os = $this->recordOsVersion($operation->organization_id, $operation->node_id)) !== null) {
            $log('os', "Ubuntu {$os}");
        }
        $php = ServerStack::defaultPhpFor($operation->node_id);
        $result = $this->gateway->run($operation->organization_id, $operation->node_id, 'apply_recipe',
            ['spec' => $this->stack->spec(PhpSettings::for($operation->node_id, $php), $admin, $os)], timeoutSeconds: 900, waitSeconds: 930, reason: $operation->title);
        foreach ($result['detail']['run']['steps'] ?? [] as $step) {
            $log((string) $step['action'], (string) $step['description']);
        }
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException((string) ($result['error'] ?? 'The stack recipe failed.'));
        }
        app(AdminAccess::class)->applied($operation->node_id);
        $this->recordPhpPatches($operation->organization_id, $operation->node_id);

        return [];
    }

    /**
     * Reads which Ubuntu release a server runs and keeps it on the node, because it decides which PHP can be had there.
     * Best effort: a server that does not answer, or runs something the stack is not built for, keeps what was recorded.
     */
    private function recordOsVersion(string $organizationId, string $nodeId): ?string
    {
        try {
            $output = (string) ($this->gateway->run($organizationId, $nodeId, 'exec', ['name' => 'sh', 'args' => ['-c', '. /etc/os-release && echo "$ID $VERSION_ID"']],
                reason: 'Read the Ubuntu release')['detail']['output'] ?? '');
        } catch (Throwable) {
            return null;
        }
        if (preg_match('/^ubuntu (\d+\.\d+)/m', $output, $m) !== 1 || ! in_array($m[1], ServerStack::SUPPORTED_OS, true)) {
            return null;
        }
        DB::table('nodes')->where('node_id', $nodeId)->update(['os_version' => $m[1]]);

        return $m[1];
    }

    /**
     * Reads the full version of each PHP branch on a server, such as 8.4.16,
     * and keeps it on the node for the Servers page. Best effort: a server
     * that does not answer keeps what was recorded before.
     */
    private function recordPhpPatches(string $organizationId, string $nodeId): void
    {
        $script = 'for v in '.implode(' ', ServerStack::PHP_VERSIONS).'; do [ -x /usr/bin/php$v ] && echo "$v $(/usr/bin/php$v -r \'echo PHP_VERSION;\')"; done; true';
        try {
            $output = (string) ($this->gateway->run($organizationId, $nodeId, 'exec', ['name' => 'sh', 'args' => ['-c', $script]],
                reason: 'Read the installed PHP versions')['detail']['output'] ?? '');
        } catch (Throwable) {
            return;
        }
        $patches = [];
        foreach (preg_split('/\R/', trim($output)) as $line) {
            if (preg_match('/^(\d+\.\d+) (\d+\.\d+\.\d+)/', $line, $m)) {
                $patches[$m[1]] = $m[2];
            }
        }
        if ($patches !== []) {
            DB::table('nodes')->where('node_id', $nodeId)->update(['php_patches' => json_encode($patches)]);
        }
    }

    /** @return array<string, mixed> */
    private function upgradeAgent(object $operation, Closure $stage, Closure $log): array
    {
        $stage('install');
        $version = $this->agentUpgrade->upgrade($this->node($operation->node_id), function (string $line) use ($stage, $log) {
            $log('agent', $line);
            $stage('reconnect');
        });
        $log('agent', "reconnected on {$version}");

        return [];
    }

    /**
     * Waits, without holding the worker, for someone to run the install
     * command and for the agent to connect, then installs the stack.
     *
     * @return array<string, mixed>
     */
    private function adopt(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        try {
            return $this->adoptInstalled($operation, $meta, $stage, $log);
        } catch (OperationWaiting $waiting) {
            throw $waiting;
        } catch (Throwable $e) {
            // A database that will never be set up says so, instead of staying "creating".
            if (isset($meta['database'])) {
                $resources = app(\App\Lib\Resources\ResourceManager::class);
                $cluster = $resources->find($operation->organization_id, 'database_cluster', (string) $meta['database']['cluster']);
                if ($cluster !== null && $cluster->state === \App\Lib\Resources\ResourceState::Provisioning) {
                    $resources->transition($cluster, \App\Lib\Resources\ResourceState::Failed, reason: 'The database server could not be set up', error: $e->getMessage(), actor: \App\Lib\Audit\Actor::system('control-plane'));
                }
            }

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function adoptInstalled(object $operation, array $meta, Closure $stage, Closure $log): array
    {
        $install = DB::table('server_installs')->where('id', (int) $meta['install_id'])->first()
            ?? throw new RuntimeException('The install link is gone.');
        $current = $this->operations->currentStage((int) $operation->id);

        if ($current === null || $current === 'install') {
            $stage('install');
            if ($install->fetched_at === null) {
                if (now()->greaterThan($install->expires_at)) {
                    throw new RuntimeException('The install command was not run within '.\App\Lib\Servers\ServerInstall::TTL_MINUTES.' minutes. Add the server again for a new one.');
                }
                throw new OperationWaiting();
            }
            $log('install', "script fetched from {$install->fetched_by}");
            $stage('enroll');
        }

        if ($this->operations->currentStage((int) $operation->id) === 'enroll') {
            if (! $this->gateway->isConnected($operation->node_id)) {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($install->fetched_at)->addMinutes(10))) {
                    throw new RuntimeException("{$operation->node_id} fetched the installer but never connected. It needs outbound access to {$this->serverName()} on ports 443 and 9443.");
                }
                throw new OperationWaiting();
            }
            $log('agent', "{$operation->node_id} connected");
        }
        if (isset($meta['database'])) {
            return $this->databaseOnOwnServer($operation, (string) $meta['database']['cluster'], $stage, $log);
        }

        return $this->installStack($operation, $stage, $log, 'stack');
    }

    /**
     * The engine, TLS and firewall on a server the person brought, in place of the web stack. The machine stays theirs: it is
     * never destroyed here, and one that cannot be set up is left as it is, with the cluster marked failed.
     *
     * @return array<string, mixed>
     */
    private function databaseOnOwnServer(object $operation, string $slug, Closure $stage, Closure $log): array
    {
        $stage('setup');
        $cluster = app(\App\Lib\Resources\ResourceManager::class)->find($operation->organization_id, 'database_cluster', $slug) ?? throw new RuntimeException('The cluster record is gone.');
        $cluster = app(\App\Lib\Cloud\DatabaseServers::class)->prepare($cluster, $log);
        $log('cluster', "online at {$cluster->attributes['host']}:{$cluster->attributes['port']}");

        return [];
    }

    /**
     * SSH once, then the same wait-for-the-agent and stack install as a server
     * the user installed by hand. Re-entered after each wait, so the login is
     * only used while the first three stages have not completed.
     *
     * @return array<string, mixed>
     */
    private function adoptSsh(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $adoption = DB::table('ssh_adoptions')->where('id', (int) $meta['adoption_id'])->first() ?? throw new RuntimeException('The request is gone.');

        if ($adoption->installed_at === null) {
            app(\App\Lib\Providers\Custom\SshAdoption::class)->connectAndInstall($adoption, $stage, $log);
            $adoption = DB::table('ssh_adoptions')->where('id', $adoption->id)->first();
            $log('install', 'the agent is installed; the login has been discarded and will not be used again');
            $stage('enroll');
        }

        if ($this->operations->currentStage((int) $operation->id) === 'enroll') {
            if (! $this->gateway->isConnected($operation->node_id)) {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($adoption->installed_at)->addMinutes(10))) {
                    throw new RuntimeException("{$operation->node_id} was installed but never connected. It needs outbound access to {$this->serverName()} on ports 443 and 9443.");
                }
                throw new OperationWaiting();
            }
            $log('agent', "{$operation->node_id} connected");
        }
        if (($meta['role'] ?? null) === \App\Lib\Providers\Incus\IncusHost::ROLE) {
            return $this->installIncusHost($operation, $stage, $log);
        }

        return $this->installStack($operation, $stage, $log, 'stack');
    }

    /**
     * Sets Incus up on a node instead of the server stack, and connects to it: the machine becomes the team's Incus host.
     *
     * @return array<string, mixed>
     */
    private function installIncusHost(object $operation, Closure $stage, Closure $log): array
    {
        $stage('incus');
        app(\App\Lib\Providers\Incus\IncusHost::class)->install($operation->organization_id, $operation->node_id, $log, (string) $operation->actor);

        return [];
    }

    /** A provider's name for a message, "DigitalOcean" for records from before providers. */
    private function providerLabel(?string $provider): string
    {
        $providers = app(\App\Lib\Providers\Providers::class);

        return $providers->has((string) ($provider ?? 'digitalocean')) ? $providers->get((string) ($provider ?? 'digitalocean'))->label() : (string) $provider;
    }

    private function serverName(): string
    {
        return (string) config('control_plane.server_name');
    }

    /**
     * Creates the cluster once, then waits for DigitalOcean to bring it
     * online, releasing the worker between checks: it takes minutes.
     *
     * @return array<string, mixed>
     */
    private function databaseCreate(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        if (($meta['provider'] ?? 'digitalocean') !== 'digitalocean') {
            return $this->databaseServerCreate($operation, $meta, $stage, $log);
        }
        $clusters = app(\App\Lib\Cloud\DatabaseClusters::class);
        $resources = app(\App\Lib\Resources\ResourceManager::class);

        if ($this->operations->currentStage((int) $operation->id) !== 'online') {
            $stage('create');
            $record = $clusters->create($operation->organization_id, (string) $meta['slug'], (string) $meta['engine'], (string) $meta['region'], (string) $meta['size']);
            $log('cluster', "{$record->externalId} requested; DigitalOcean takes a few minutes");
            $stage('online');
        }

        $record = $resources->find($operation->organization_id, 'database_cluster', (string) $meta['slug'])
            ?? throw new RuntimeException('The cluster record is gone.');
        $record = $clusters->refresh($record);
        if ($record->state !== \App\Lib\Resources\ResourceState::Active) {
            if ($record->stateEnteredAt !== null && $record->stateEnteredAt->addMinutes(30)->isPast()) {
                throw new RuntimeException("The cluster has not come online in 30 minutes. Check it in DigitalOcean; it may still be billing.");
            }
            throw new OperationWaiting();
        }
        $log('cluster', "online at {$record->attributes['host']}:{$record->attributes['port']}");

        return [];
    }

    /**
     * A database on a server of its own: the provider makes the server, its agent enrolls, then the engine is set up.
     * A server that never enrolls is destroyed so it does not keep billing; one that enrolled but could not be set up
     * is kept, marked failed, for you to look at or destroy.
     *
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    private function databaseServerCreate(object $operation, array $meta, Closure $stage, Closure $log): array
    {
        $servers = app(\App\Lib\Cloud\DatabaseServers::class);
        $resources = app(\App\Lib\Resources\ResourceManager::class);
        $org = $operation->organization_id;
        $slug = (string) $meta['slug'];
        $node = $servers->nodeId($org, $slug);
        $stageNow = $this->operations->currentStage((int) $operation->id);

        // A retry after a failed setup has no running stage, but its server is already made: carry on from there.
        $existing = $resources->find($org, 'server', \App\Lib\Cloud\DatabaseServers::serverSlug($slug));
        if ($stageNow === null && $existing !== null && $existing->state !== \App\Lib\Resources\ResourceState::Deleted) {
            $stage('enroll');
            $stageNow = 'enroll';
        }
        if (in_array($stageNow, [null, 'create'], true)) {
            $stage('create');
            $record = $servers->create($org, $slug, (string) $meta['engine'], (string) $meta['provider'], (string) $meta['region'], (string) $meta['size']);
            $made = $resources->find($org, 'server', \App\Lib\Cloud\DatabaseServers::serverSlug($slug));
            $log('server', 'created at '.($made !== null ? \App\Lib\Providers\ProviderServer::publicAddress($made->attributes) : $record->externalId));
            $stage('enroll');
            $stageNow = 'enroll';
        }
        if ($stageNow === 'enroll') {
            if (! $this->gateway->isConnected($node)) {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($operation->created_at)->addMinutes(15))) {
                    $cluster = $resources->find($org, 'database_cluster', $slug);
                    try {
                        if ($cluster !== null) {
                            $servers->destroy($cluster);
                        }
                        $log('server', 'destroyed, so it does not keep billing');
                    } catch (Throwable $e) {
                        $log('error', "could not be destroyed and may still be billed: {$e->getMessage()}");
                    }

                    throw new RuntimeException("{$node} was created but its agent never connected.");
                }
                throw new OperationWaiting();
            }
            $log('agent', "{$node} connected");
        }

        $stage('setup');
        $cluster = $resources->find($org, 'database_cluster', $slug) ?? throw new RuntimeException('The cluster record is gone.');
        try {
            $cluster = $servers->prepare($cluster, $log);
        } catch (Throwable $e) {
            if ($cluster->state === \App\Lib\Resources\ResourceState::Provisioning) {
                $resources->transition($cluster, \App\Lib\Resources\ResourceState::Failed, reason: 'The database server could not be set up', error: $e->getMessage(), actor: \App\Lib\Audit\Actor::system('control-plane'));
            }

            throw $e;
        }
        $log('cluster', "online at {$cluster->attributes['host']}:{$cluster->attributes['port']}");

        return [];
    }

    /** @return array<string, mixed> */
    private function databaseAttach(object $operation, Closure $stage, Closure $log): array
    {
        $stage('attach');
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $cluster = app(\App\Lib\Resources\ResourceManager::class)->findById((int) $meta['cluster']) ?? throw new RuntimeException('The cluster is gone.');
        foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->attach($this->site((int) $operation->site_id), $cluster) as $line) {
            $log('db', $line);
        }

        return [];
    }

    /**
     * Makes the server, then installs the service on it; each is an operation of its own, waited for. Re-entered after
     * each wait, so what is done is kept in the operation's meta.
     *
     * @return array<string, mixed>
     */
    private function serviceCreate(object $operation, Closure $stage, Closure $log): array
    {
        $id = (int) $operation->id;
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $org = (string) $operation->organization_id;
        $actor = (string) $operation->actor;
        $node = "{$meta['server']['slug']}.{$org}";

        foreach (['server', 'service'] as $step) {
            if (in_array($step, $meta['done'], true)) {
                continue;
            }
            $stage($step);
            $child = $meta['children'][$step] ?? null;
            if ($child === null) {
                $child = $step === 'server'
                    ? $this->queueProvision($org, (string) $meta['server']['slug'], ['provider' => (string) $meta['server']['provider'], 'region' => (string) $meta['server']['region'], 'size' => (string) $meta['server']['size']], 'service_create', $actor)
                    : app(\App\Lib\Services\Services::class)->install($org, $node, (string) $meta['template'], (array) $meta['inputs'], $actor, (string) $meta['role'],
                        (bool) $meta['acknowledged'], true, $meta['project'], (bool) $meta['portsApproved'], 'service_create')['operation_id'];
                $meta['children'][$step] = $child;
                $this->operations->mergeMeta($id, ['children' => $meta['children']]);
                $log($step, "operation #{$child} started");

                throw new OperationWaiting();
            }
            $result = $this->operations->find((int) $child);
            if (in_array($result->status ?? null, ['queued', 'running', 'waiting'], true)) {
                throw new OperationWaiting();
            }
            if (($result->status ?? null) !== 'succeeded') {
                throw new RuntimeException("{$step} failed (operation #{$child}): ".($result->error ?? 'no detail').($step === 'service' ? " The server {$node} was made and is still there; install the service on it again from the templates, or destroy the server." : ''));
            }
            $log($step, "operation #{$child} succeeded");
            $meta['done'][] = $step;
            $this->operations->mergeMeta($id, ['done' => $meta['done']]);
        }
        $log('done', "{$meta['name']} is running on {$node}");

        return [];
    }

    /** @return array<string, mixed> */
    private function siteServerCreate(object $operation, Closure $stage, Closure $log): array
    {
        $id = (int) $operation->id;
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $org = (string) $operation->organization_id;
        $node = "{$meta['server']['slug']}.{$org}";

        foreach (['server', 'add'] as $step) {
            if (in_array($step, $meta['done'], true)) {
                continue;
            }
            $stage($step);
            $child = $meta['children'][$step] ?? null;
            if ($child === null) {
                $child = $step === 'server'
                    ? $this->queueProvision($org, (string) $meta['server']['slug'], ['provider' => (string) $meta['server']['provider'], 'region' => (string) $meta['server']['region'], 'size' => (string) $meta['server']['size']], 'site_server_create', (string) $operation->actor)
                    : $this->queueServerAdd((int) $operation->site_id, $node, 'site_server_create', (string) $operation->actor, (string) $meta['role']);
                $meta['children'][$step] = $child;
                $this->operations->mergeMeta($id, ['children' => $meta['children']]);
                $log($step, "operation #{$child} started");

                throw new OperationWaiting();
            }
            $result = $this->operations->find((int) $child);
            if (in_array($result->status ?? null, ['queued', 'running', 'waiting'], true)) {
                throw new OperationWaiting();
            }
            if (($result->status ?? null) !== 'succeeded') {
                throw new RuntimeException("{$step} failed (operation #{$child}): ".($result->error ?? 'no detail').($step === 'add' ? " The server {$node} was made and is still there; add it to the application from its Servers tab, or destroy it." : ''));
            }
            $log($step, "operation #{$child} succeeded");
            $meta['done'][] = $step;
            $this->operations->mergeMeta($id, ['done' => $meta['done']]);
        }
        $log('done', "{$node} is a {$meta['role']} server for the application");

        return [];
    }

    private function serviceInstall(object $operation, Closure $stage, Closure $log): array
    {
        $stage('install');
        $meta = json_decode((string) $operation->meta, true) ?: [];
        app(\App\Lib\Servers\LocalServices::class)->install($operation->organization_id, (string) $operation->node_id, (string) $meta['service'], $log, $operation->title);

        return [];
    }

    private function templateServiceInstall(object $operation, Closure $stage, Closure $log): array
    {
        app(\App\Lib\Services\ServiceRunner::class)->install($operation, $stage, $log, $this->serviceRecipe($operation));

        return [];
    }

    private function templateServiceAction(object $operation, Closure $stage, Closure $log): array
    {
        app(\App\Lib\Services\ServiceRunner::class)->action($operation, $stage, $log, $this->serviceRecipe($operation));

        return [];
    }

    private function templateServiceRemove(object $operation, Closure $stage, Closure $log): array
    {
        app(\App\Lib\Services\ServiceRunner::class)->remove($operation, $stage, $log, $this->serviceRecipe($operation));

        return [];
    }

    private function templateServiceUpdate(object $operation, Closure $stage, Closure $log): array
    {
        app(\App\Lib\Services\ServiceRunner::class)->update($operation, $stage, $log, $this->serviceRecipe($operation));

        return [];
    }

    private function templateServiceBackup(object $operation, Closure $stage, Closure $log): array
    {
        app(\App\Lib\Services\ServiceRunner::class)->backup($operation, $stage, $log, $this->serviceRecipe($operation));

        return [];
    }

    private function templateServiceRestore(object $operation, Closure $stage, Closure $log): array
    {
        app(\App\Lib\Services\ServiceRunner::class)->restore($operation, $stage, $log, $this->serviceRecipe($operation));

        return [];
    }

    private function serverContainers(object $operation, Closure $stage, Closure $log): array
    {
        app(\App\Lib\Containers\Containers::class)->runPrepare($operation, $stage, $log, $this->serviceRecipe($operation));

        return [];
    }

    private function containerCleanup(object $operation, Closure $stage, Closure $log): array
    {
        app(\App\Lib\Containers\Containers::class)->runCleanup($operation, $stage, $log);

        return [];
    }

    /** What a service operation applies its recipes with: this server, through the gateway. */
    private function serviceRecipe(object $operation): Closure
    {
        $on = (object) ['organization_id' => $operation->organization_id, 'node_id' => $operation->node_id, 'name' => 'service'];

        return fn (array $spec, int $timeout) => $this->applyRecipe($on, $spec, $timeout);
    }

    private function localService(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $site = $this->site((int) $operation->site_id);
        $services = app(\App\Lib\Servers\LocalServices::class);

        $stage('install');
        $services->install($operation->organization_id, (string) $site->node_id, (string) $meta['service'], $log, $operation->title);
        $stage('attach');
        foreach ($services->attach($site, (string) $meta['service'], $log) as $line) {
            $log('db', $line);
        }

        return [];
    }

    private function databaseClone(object $operation, Closure $stage, Closure $log): array
    {
        $stage('clone');
        $meta = json_decode((string) $operation->meta, true) ?: [];
        app(\App\Lib\Sites\DatabaseClones::class)->clone($this->site((int) $operation->site_id), (string) $meta['name'], (bool) ($meta['schema_only'] ?? false), $log, (string) ($meta['conversion'] ?? ''));

        return [];
    }

    /** @return array<string, mixed> */
    private function serverAdd(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $node = (string) $meta['node'];
        $site = $this->site((int) $operation->site_id);

        $stage('admit');
        foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->admitServer($site, $node) as $line) {
            $log('db', $line);
        }
        $role = (string) ($meta['role'] ?? Sites::ROLE_WEB);
        Sites::addNode($site, $node, $role);
        if ($site->current_release === null) {
            $log('server', 'added; the first deploy sets it up');

            return [];
        }

        // A server that cannot be set up must not stay on the site, or every
        // later deploy fails on it too. The others are untouched either way.
        try {
            $result = $this->deployer->deploy($site, $stage, $meta['now'] ?? null, $log);
        } catch (Throwable $e) {
            DB::table('site_nodes')->where('site_id', $site->id)->where('node_id', $node)->delete();
            throw new RuntimeException($e->getMessage().' The server was not added.', 0, $e);
        }
        // A worker server takes no traffic, so the balancer is left as it is.
        if ($site->load_balancer_id !== null && $role !== Sites::ROLE_WORKER) {
            $log('balancer', app(\App\Lib\Cloud\LoadBalancers::class)->apply($site));
        }

        return ['release_id' => $result['release'], 'commit' => $result['commit']];
    }

    /**
     * Re-entered while DigitalOcean brings the balancer up, so each part runs
     * only from the stage the record reached: never a second balancer.
     *
     * @return array<string, mixed>
     */
    private function loadBalancer(object $operation, Closure $stage, Closure $log): array
    {
        $balancers = app(\App\Lib\Cloud\LoadBalancers::class);
        $id = (int) $operation->id;

        if ($this->operations->currentStage($id) !== 'online') {
            $stage('create');
            $record = $balancers->create($this->site((int) $operation->site_id));
            $log('balancer', "{$record->externalId} requested; DigitalOcean takes a few minutes");
            $stage('online');
        }

        $site = $this->site((int) $operation->site_id);
        $record = $balancers->refresh($site, $balancers->of($site));
        if ($record->state !== \App\Lib\Resources\ResourceState::Active) {
            $since = $this->operations->stageStartedAt($id, 'online');
            if ($since !== null && now()->greaterThan(\Illuminate\Support\Carbon::parse($since)->addMinutes(20))) {
                throw new RuntimeException('The load balancer has not come up in 20 minutes. Check it in DigitalOcean; it may still be billing.');
            }
            throw new OperationWaiting();
        }
        $log('balancer', "active at {$record->attributes['ip']}");

        // Every server now serves the app on port 80 for the balancer.
        $stage('servers');
        foreach (Sites::nodes($site) as $node) {
            $on = Sites::on($site, $node);
            $this->deployer->setup($on);
            $log('nginx', (strstr($node, '.', true) ?: $node).' serves the balancer on port 80');
        }

        $stage('certificate');
        if ((bool) $site->tls) {
            $log('balancer', $balancers->syncCertificate($site));
        } else {
            $log('balancer', 'no HTTPS yet; turning it on uploads the certificate here too');
        }
        $log('dns', "point {$site->domain} at {$record->attributes['ip']}");

        return [];
    }

    /** @return array<string, mixed> */
    private function serverRemove(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $node = (string) $meta['node'];
        $site = $this->site((int) $operation->site_id);

        $stage('remove');
        // Off the list first, so no deploy starting meanwhile reaches it.
        DB::table('site_nodes')->where('site_id', $site->id)->where('node_id', $node)->delete();
        $log('server', 'no longer deployed to');
        if ($site->load_balancer_id !== null) {
            $log('balancer', app(\App\Lib\Cloud\LoadBalancers::class)->apply($site));
        }
        $this->deployer->unserve($site, $node);
        $log('server', 'nginx, workers and schedule stopped there; its copy of the site deleted');
        foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->releaseServer($site, $node) as $line) {
            $log('firewall', $line);
        }
        if (($meta['destroy'] ?? false) === true) {
            // Its own operation, with its own checks: it is refused, and the server kept, if anything else still uses it.
            try {
                $log('server', "destroying {$node}: operation #".$this->queueServerDestroy($node, 'server_remove', (string) $operation->actor));
            } catch (\InvalidArgumentException $e) {
                $log('server', "kept, not destroyed: {$e->getMessage()}");
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function poolCreate(object $operation, Closure $stage, Closure $log): array
    {
        $stage('pool');
        $meta = json_decode((string) $operation->meta, true) ?: [];
        // New autoscaling is ours, on any provider; native pools that already exist keep their own code.
        $pool = app(\App\Lib\Scaling\ServerScaler::class)->create($this->site((int) $operation->site_id), $meta['spec']);
        $log('pool', "{$pool->slug} keeps {$meta['spec']['min']} to {$meta['spec']['max']} servers, adding one above "
            .round($meta['spec']['cpu'] * 100).'% CPU; each joins the site once it has the live release');

        return [];
    }

    /** A server the autoscaler added that did not get as far as joining counts against it, so a fault that repeats stops it instead of looping. */
    private function noteScalerFailure(object $operation, string $why): void
    {
        if (! in_array($operation->kind, ['scaler_up', 'pool_member', 'pool_join'], true) || $operation->site_id === null) {
            return;
        }
        try {
            $site = $this->site((int) $operation->site_id);
            if (\App\Lib\Scaling\ServerScaler::manages($site)) {
                app(\App\Lib\Scaling\ServerScaler::class)->recordFailure($site, $why);
            }
        } catch (Throwable) {
        }
    }

    /** @return array<string, mixed> */
    private function scalerUp(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $site = $this->site((int) $operation->site_id);
        $scaler = app(\App\Lib\Scaling\ServerScaler::class);
        $provisioner = app(\App\Lib\Cloud\ServerProvisioner::class);
        $slug = (string) $meta['slug'];
        $node = $provisioner->nodeId($site->organization_id, $slug);

        try {
            if (in_array($this->operations->currentStage((int) $operation->id), [null, 'create'], true)) {
                $stage('create');
                $pool = $scaler->of($site);
                $record = $provisioner->provision($site->organization_id, $slug, $scaler->spec($site, $pool, $slug), \App\Lib\Audit\Actor::system('autoscaler'));
                $log('server', "{$slug} is {$record->state->value} at ".\App\Lib\Providers\ProviderServer::publicAddress($record->attributes));
                $stage('enroll');
            }
            if (! $this->gateway->isConnected($node)) {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($operation->created_at)->addMinutes(15))) {
                    throw new RuntimeException("{$node} was created but its agent never connected.");
                }
                throw new OperationWaiting();
            }
        } catch (OperationWaiting $waiting) {
            throw $waiting;
        } catch (Throwable $e) {
            // A server that cannot join is not left billing.
            $made = app(\App\Lib\Resources\ResourceManager::class)->find($site->organization_id, 'server', $slug);
            if ($made !== null && $made->externalId !== null && ! in_array($made->state, [\App\Lib\Resources\ResourceState::Deleted, \App\Lib\Resources\ResourceState::Deleting], true)) {
                try {
                    $provisioner->destroy($made, \App\Lib\Audit\Actor::system('autoscaler'));
                    $log('server', "{$slug} destroyed, so it does not keep billing");
                } catch (Throwable $cleanup) {
                    $log('error', "{$slug} could not be destroyed and is still billed: {$cleanup->getMessage()}");
                }
            }

            throw $e;
        }
        $log('agent', "{$node} connected");
        $id = $this->queuePoolJoin($site->id, $node);
        $log('server', "preparing and joining it to {$site->name} is operation {$id}");

        return [];
    }

    /** @return array<string, mixed> */
    private function scalerDown(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $node = (string) $meta['node'];
        $site = $this->site((int) $operation->site_id);
        $slug = strstr($node, '.', true) ?: $node;
        $resources = app(\App\Lib\Resources\ResourceManager::class);
        $record = $resources->find($site->organization_id, 'server', $slug);
        // Only what the scaler made is ever destroyed, and never the primary.
        if ($record === null || (int) ($record->attributes['pool'] ?? 0) !== (int) $site->autoscale_pool_id || $node === $site->node_id) {
            throw new RuntimeException("{$slug} is not a server the autoscaler made; refusing to destroy it.");
        }

        if (in_array($this->operations->currentStage((int) $operation->id), [null, 'drain'], true)) {
            $stage('drain');
            if (! isset($meta['drained_at'])) {
                // Off the list first, so no deploy reaches it, then off the balancer.
                DB::table('site_nodes')->where('site_id', $site->id)->where('node_id', $node)->delete();
                $log('server', 'no longer deployed to');
                if ($site->load_balancer_id !== null) {
                    $log('balancer', app(\App\Lib\Scaling\EdgeDriver::class)->apply($site));
                }
                $this->operations->mergeMeta((int) $operation->id, ['drained_at' => now()->toIso8601String()]);
                $meta['drained_at'] = now()->toIso8601String();
            }
            if (! ($meta['stranded'] ?? false) && now()->lt(\Illuminate\Support\Carbon::parse($meta['drained_at'])->addSeconds(\App\Lib\Scaling\ServerScaler::DRAIN_SECONDS))) {
                throw new OperationWaiting();
            }
            try {
                $this->deployer->unserve($site, $node);
                $log('server', 'nginx, workers and schedule stopped there');
            } catch (Throwable $e) {
                $log('skip', "could not stop it cleanly (it is being destroyed anyway): {$e->getMessage()}");
            }
            foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->releaseServer($site, $node) as $line) {
                $log('firewall', $line);
            }
            $stage('destroy');
        }

        app(\App\Lib\Cloud\ServerProvisioner::class)->destroy($record, \App\Lib\Audit\Actor::system('autoscaler'));
        $log('server', "{$record->externalId} destroyed; billing stops");
        if (($nodeRow = DB::table('nodes')->where('node_id', $node)->first()) !== null) {
            try {
                app(\App\Lib\Servers\NodeRemoval::class)->revoke($nodeRow, 'autoscale');
                $log('server', 'its certificate is revoked');
            } catch (Throwable $e) {
                $log('server', 'the certificate revocation will apply at the next gateway sync: '.$e->getMessage());
            }
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function poolMember(object $operation, Closure $stage, Closure $log): array
    {
        // Queued the moment the member enrolls, which is a few seconds before
        // its agent connects.
        if (in_array($this->operations->currentStage((int) $operation->id), [null, 'connect'], true)) {
            $stage('connect');
            if (! $this->gateway->isConnected($operation->node_id)) {
                if (now()->greaterThan(\Illuminate\Support\Carbon::parse($operation->created_at)->addMinutes(10))) {
                    throw new RuntimeException("{$operation->node_id} enrolled but never connected.");
                }
                throw new OperationWaiting();
            }
        }
        $this->installStack($operation, $stage, $log, 'stack');
        $site = $this->site((int) $operation->site_id);
        $short = strstr($operation->node_id, '.', true) ?: $operation->node_id;
        $id = $this->operations->create($site->organization_id, 'pool_join', "Join {$short} to {$site->name}", 'autoscale', 'autoscaler',
            ['admit', 'release', 'balancer'], $site->id, $site->node_id, meta: ['node' => $operation->node_id]);
        $this->queue($id);
        $log('server', "stack ready; joining it to {$site->name} is operation {$id}");

        return [];
    }

    /**
     * Runs under the site's primary, so never alongside a deploy: the member
     * gets the release that is live, and the next deploy includes it.
     *
     * @return array<string, mixed>
     */
    private function poolJoin(object $operation, Closure $stage, Closure $log): array
    {
        $node = (string) (json_decode((string) $operation->meta, true) ?: [])['node'];
        $site = $this->site((int) $operation->site_id);
        if ($site->autoscale_pool_id === null) {
            throw new RuntimeException("{$site->name} no longer autoscales.");
        }

        // Admitted first: restarting workers onto the release reaches the cache.
        $stage('admit');
        foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->admitServer($site, $node) as $line) {
            $log('db', $line);
        }
        $stage('release');
        $this->deployer->join($site, $node, $log);
        DB::table('site_nodes')->insertOrIgnore(['site_id' => $site->id, 'node_id' => $node, 'autoscale_pool_id' => $site->autoscale_pool_id,
            'created_at' => now(), 'updated_at' => now()]);
        $stage('balancer');
        $log('balancer', app(\App\Lib\Cloud\LoadBalancers::class)->apply($site));
        if (\App\Lib\Scaling\ServerScaler::manages($site)) {
            app(\App\Lib\Scaling\ServerScaler::class)->recordJoined($site);
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function poolLeave(object $operation, Closure $stage, Closure $log): array
    {
        $stage('leave');
        $this->leave($this->site((int) $operation->site_id), (json_decode((string) $operation->meta, true) ?: [])['nodes'] ?? [], $log);

        return [];
    }

    /** @return array<string, mixed> */
    private function poolResize(object $operation, Closure $stage, Closure $log): array
    {
        $stage('resize');
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $scheduled = (bool) ($meta['scheduled'] ?? false);
        $site = $this->site((int) $operation->site_id);
        $engine = \App\Lib\Scaling\ServerScaler::manages($site) ? app(\App\Lib\Scaling\ServerScaler::class) : app(\App\Lib\Cloud\AutoscalePools::class);
        $engine->resize($site, (int) $meta['min'], (int) $meta['max'], $scheduled);
        $log('pool', "keeps {$meta['min']} to {$meta['max']} servers".($scheduled ? ', until the schedule changes it' : ''));

        return [];
    }

    /** @return array<string, mixed> */
    private function poolRemove(object $operation, Closure $stage, Closure $log): array
    {
        $site = $this->site((int) $operation->site_id);
        $stage('remove');
        $nodes = (\App\Lib\Scaling\ServerScaler::manages($site) ? app(\App\Lib\Scaling\ServerScaler::class) : app(\App\Lib\Cloud\AutoscalePools::class))->destroy($site);
        $log('pool', 'deleted, with its servers'.($nodes === [] ? '' : ': '.implode(', ', array_map(fn ($n) => strstr($n, '.', true) ?: $n, $nodes))));
        $stage('leave');
        $joined = DB::table('site_nodes')->where('site_id', $site->id)->whereNotNull('autoscale_pool_id')->pluck('node_id')->all();
        $this->leave($this->site($site->id), array_values(array_unique([...$nodes, ...$joined])), $log);

        return [];
    }

    /**
     * Servers that no longer exist come off the site: off the list, out of
     * the balancer and the firewalls, certificate revoked. Nothing is sent
     * to them; they are gone.
     *
     * @param  list<string>  $nodes
     */
    private function leave(object $site, array $nodes, Closure $log): void
    {
        DB::table('site_nodes')->where('site_id', $site->id)->whereIn('node_id', $nodes)->delete();
        if ($site->load_balancer_id !== null) {
            $log('balancer', app(\App\Lib\Cloud\LoadBalancers::class)->apply($site));
        }
        foreach ($nodes as $node) {
            foreach (app(\App\Lib\Cloud\DatabaseClusters::class)->releaseServer($site, $node) as $line) {
                $log('firewall', $line);
            }
            try {
                $nodeRow = DB::table('nodes')->where('node_id', $node)->first();
                if ($nodeRow !== null) {
                    // revoke(), not remove(): a departing pool member still
                    // carries its pool marker, which remove() refuses. That
                    // refusal used to be swallowed, leaving the certificate
                    // valid and the node row behind.
                    app(\App\Lib\Servers\NodeRemoval::class)->revoke($nodeRow, 'autoscale');
                    $log('server', (strstr($node, '.', true) ?: $node).' certificate revoked');
                }
            } catch (\InvalidArgumentException $e) {
                // Still being prepared, most likely: the next reconcile retries.
                $log('skip', $e->getMessage());
            }
            $slug = strstr($node, '.', true) ?: $node;
            $record = app(\App\Lib\Resources\ResourceManager::class)->find($site->organization_id, 'server', $slug);
            if ($record !== null && $record->state === \App\Lib\Resources\ResourceState::Active) {
                $resources = app(\App\Lib\Resources\ResourceManager::class);
                $record = $resources->transition($record, \App\Lib\Resources\ResourceState::Deleting, reason: 'Removed by its autoscale pool', actor: \App\Lib\Audit\Actor::system('autoscale'));
                $resources->transition($record, \App\Lib\Resources\ResourceState::Deleted, reason: 'Removed by its autoscale pool', actor: \App\Lib\Audit\Actor::system('autoscale'));
            }
        }
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    private function siteBackup(object $operation, Closure $stage, Closure $log): array
    {
        $stage('backup');
        $site = $this->site((int) $operation->site_id);
        $backups = app(\App\Lib\Sites\SiteBackups::class);
        $taken = $backups->take($site, (string) (json_decode((string) $operation->meta, true)['kind'] ?? 'manual'));
        $log('backup', sprintf('%s, %s KB', basename($taken['object']), number_format($taken['bytes'] / 1024, 1)));
        foreach ($backups->prune($site) as $old) {
            $log('prune', basename($old).' is older than '.\App\Lib\Sites\SiteBackups::KEEP_DAYS.' days, deleted');
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function siteRestore(object $operation, Closure $stage, Closure $log): array
    {
        $stage('backup');
        $object = (string) (json_decode((string) $operation->meta, true)['object'] ?? '');
        app(\App\Lib\Sites\SiteBackups::class)->restore($this->site((int) $operation->site_id), $object, function (string $action, string $text) use ($stage, $log) {
            if ($action === 'down') {
                $stage('restore');
            }
            $log($action, $text);
        });

        return [];
    }

    /**
     * Forks the cluster from the backup, waits for the fork (releasing the
     * worker between checks), copies the site's database from it, and
     * destroys it whether or not the copy worked.
     *
     * @return array<string, mixed>
     */
    private function siteRestoreToTime(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $site = $this->site((int) $operation->site_id);
        $clusters = app(\App\Lib\Cloud\DatabaseClusters::class);
        $resources = app(\App\Lib\Resources\ResourceManager::class);
        $backup = (string) $meta['backup'];

        if ($this->operations->currentStage((int) $operation->id) !== 'online') {
            $stage('fork');
            $source = $resources->findById((int) $site->database_cluster_id) ?? throw new RuntimeException('The site\'s cluster is gone.');
            $fork = $clusters->fork($source, $backup, (string) $meta['slug']);
            $log('cluster', "a temporary cluster from {$source->slug}'s backup of {$backup} is being made ({$fork->externalId}); this takes several minutes and is billed while it exists");
            $stage('online');
        }

        $fork = $resources->find($operation->organization_id, 'database_cluster', (string) $meta['slug']) ?? throw new RuntimeException('The copy\'s record is gone.');
        $fork = $clusters->refresh($fork);
        if ($fork->state !== \App\Lib\Resources\ResourceState::Active) {
            if ($fork->stateEnteredAt !== null && $fork->stateEnteredAt->addMinutes(60)->isPast()) {
                $clusters->destroy($fork);
                throw new RuntimeException('The copy did not come online in an hour; it has been destroyed. Nothing was changed.');
            }
            throw new OperationWaiting();
        }
        $log('cluster', "copy online at {$fork->attributes['host']}");

        try {
            $stage('restore');
            app(\App\Lib\Sites\SiteBackups::class)->restoreFromHost($site, (string) $fork->attributes['host'], (int) $fork->attributes['port'], $log);
        } finally {
            $stage('cleanup');
            try {
                $clusters->destroy($fork);
                $log('cluster', "copy {$fork->slug} destroyed");
            } catch (Throwable $e) {
                $log('error', "could not destroy the copy cm-{$fork->slug}: {$e->getMessage()}. Destroy it on the Databases page; it bills until then.");
            }
        }

        return [];
    }

    private function storageAttach(object $operation, Closure $stage, Closure $log): array
    {
        $stage('bucket');
        foreach (app(\App\Lib\Cloud\Spaces::class)->attach($this->site((int) $operation->site_id)) as $line) {
            $log('spaces', $line);
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function storageDetach(object $operation, Closure $stage, Closure $log): array
    {
        $stage('bucket');
        foreach (app(\App\Lib\Cloud\Spaces::class)->remove($this->site((int) $operation->site_id)) as $line) {
            $log('spaces', $line);
        }

        return [];
    }

    /** @return array<string, mixed> */
    private function databaseDestroy(object $operation, Closure $stage, Closure $log): array
    {
        $stage('destroy');
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $cluster = app(\App\Lib\Resources\ResourceManager::class)->findById((int) $meta['cluster']) ?? throw new RuntimeException('The cluster is gone.');
        $adopted = \App\Lib\Cloud\DatabaseClusters::isAdopted($cluster);
        $own = \App\Lib\Cloud\DatabaseServers::isOwnServer($cluster);
        app(\App\Lib\Cloud\DatabaseClusters::class)->destroy($cluster);
        $log('cluster', match (true) {
            $own => "{$cluster->slug} removed; your server and the database on it are untouched, only Unharbored's agent is gone",
            $adopted => "{$cluster->slug} forgotten; the cluster and its data are untouched at DigitalOcean",
            default => "{$cluster->slug} destroyed; its data is gone",
        });

        return [];
    }

    /** @return array<string, mixed> */
    private function databaseReissue(object $operation, Closure $stage, Closure $log): array
    {
        $stage('reissue');
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $cluster = app(\App\Lib\Resources\ResourceManager::class)->findById((int) $meta['cluster']) ?? throw new RuntimeException('The cluster is gone.');
        app(\App\Lib\Cloud\DatabaseServers::class)->reissue($cluster, $meta['address'] ?: null, $log);

        return [];
    }

    /** @return array<string, mixed> */
    private function readdress(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $to = (string) $meta['to'];

        $stage('rewrite');
        $result = $this->gateway->run($operation->organization_id, $operation->node_id, 'apply_recipe',
            ['spec' => $this->agentUpgrade->readdressRecipe((string) $meta['from'], $to)], timeoutSeconds: 60, reason: $operation->title);
        foreach ($result['detail']['run']['steps'] ?? [] as $step) {
            $log((string) $step['action'], (string) $step['description']);
        }
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException((string) ($result['error'] ?? 'The rewrite failed.'));
        }

        // Give the restart its few seconds, then wait for the session.
        $stage('reconnect');
        sleep(8);
        $deadline = time() + 90;
        while (! $this->gateway->isConnected($operation->node_id)) {
            if (time() > $deadline) {
                throw new RuntimeException("{$operation->node_id} did not reconnect. The old name still works, so check the agent's journal.");
            }
            sleep(3);
        }
        $log('agent', 'reconnected');

        // Proof from the node itself, not from the rewrite having run.
        $stage('verify');
        $state = $this->gateway->run($operation->organization_id, $operation->node_id, 'exec',
            ['name' => 'cat', 'args' => ['/var/lib/cloud-manager/node.json']], reason: $operation->title);
        $addr = json_decode((string) ($state['detail']['output'] ?? ''), true)['control_plane_addr'] ?? '?';
        if (! str_starts_with($addr, $to.':')) {
            throw new RuntimeException("The agent reconnected but still records {$addr}.");
        }
        $log('agent', "connects to {$addr}");

        return [];
    }

    /** @return array<string, mixed> */
    private function provision(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $org = $operation->organization_id;
        $id = (int) $operation->id;

        // Re-entered every few seconds while waiting for the agent, so each
        // step runs only from the stage the record says it reached: a
        // re-entry must never run preflight again or create a second Droplet.
        if ($this->operations->currentStage($id) !== 'enroll') {
            $this->createDroplet($operation, $meta, $org, $stage, $log);
        }

        // The agent enrolls from cloud-init and then holds a session; until
        // it does, nothing can be installed. Waiting releases the worker.
        if (! $this->gateway->isConnected($operation->node_id)) {
            $since = $this->operations->stageStartedAt($id, 'enroll');
            if ($since !== null && now()->greaterThan(\Illuminate\Support\Carbon::parse($since)->addMinutes(10))) {
                throw new RuntimeException("{$operation->node_id} did not connect within ten minutes. Read /var/log/cloud-init-output.log on the Droplet.");
            }
            throw new OperationWaiting();
        }
        $log('agent', "{$operation->node_id} connected");
        if (($meta['spec']['role'] ?? null) === 'ingress') {
            $stage('ingress');
            $this->applyIngress($operation->organization_id, $operation->node_id, $log);
            DB::table('nodes')->where('node_id', $operation->node_id)->update(['role' => 'ingress', 'updated_at' => now()]);
            app(\App\Lib\Providers\Incus\Ingress::class)->queueSync($operation->organization_id, 'ingress ready', 'system');

            return [];
        }
        if (($meta['spec']['role'] ?? null) === \App\Lib\Providers\Incus\IncusHost::ROLE) {
            return $this->installIncusHost($operation, $stage, $log);
        }
        if (array_key_exists('keys', $meta)) {
            \App\Lib\Servers\SshKeys::choose($org, $operation->node_id, array_map('intval', $meta['keys']));
            $log('keys', count($meta['keys']) === 0 ? 'no SSH key chosen: nobody can log in until one is added' : count($meta['keys']).' SSH key(s) chosen for the login user');
        }

        return $this->installStack($operation, $stage, $log, 'stack');
    }

    /**
     * Re-entered while the builder boots, while it powers off and while the
     * snapshot is taken, so each part runs only from the stage the record
     * reached. The builder is destroyed whether the image is made or not.
     *
     * @return array<string, mixed>
     */
    private function baseImage(object $operation, Closure $stage, Closure $log): array
    {
        $meta = json_decode((string) $operation->meta, true) ?: [];
        $id = (int) $operation->id;
        $slug = (string) $meta['slug'];
        $region = (string) $meta['spec']['region'];
        $images = app(\App\Lib\Cloud\BaseImages::class);
        $at = fn (string ...$stages): bool => in_array($this->operations->currentStage($id), [null, ...$stages], true);

        try {
            if ($at('preflight', 'create')) {
                $this->createDroplet($operation, $meta, $operation->organization_id, $stage, $log);
            }
            if ($at('preflight', 'create', 'enroll')) {
                if (! $this->gateway->isConnected($operation->node_id)) {
                    $since = $this->operations->stageStartedAt($id, 'enroll');
                    if ($since !== null && now()->greaterThan(\Illuminate\Support\Carbon::parse($since)->addMinutes(10))) {
                        throw new RuntimeException("{$operation->node_id} did not connect within ten minutes.");
                    }
                    throw new OperationWaiting();
                }
                $this->installStack($operation, $stage, $log, 'stack');
            }
            if ($at('preflight', 'create', 'enroll', 'stack')) {
                $stage('clean');
                $result = $this->gateway->run($operation->organization_id, $operation->node_id, 'apply_recipe',
                    ['spec' => \App\Lib\Cloud\BaseImages::generaliseRecipe()], timeoutSeconds: 30, reason: $operation->title);
                if (($result['status'] ?? null) !== 'succeeded') {
                    throw new RuntimeException((string) ($result['error'] ?? 'The builder could not be wiped.'));
                }
                app(\App\Lib\Servers\NodeRemoval::class)->revoke($this->node($operation->node_id), 'image builder');
                $log('image', 'agent, identity and cloud-init state removed; powering off; certificate revoked');
                $stage('snapshot');
            }

            $droplet = app(\App\Lib\Resources\ResourceManager::class)->find($operation->organization_id, 'server', $slug)
                ?? throw new RuntimeException("No droplet {$slug}.");
            if ($at('snapshot')) {
                $image = $images->snapshot($operation->organization_id, $slug, (string) $droplet->externalId, $region);
                if ($image === null) {
                    $since = $this->operations->stageStartedAt($id, 'snapshot');
                    if ($since !== null && now()->greaterThan(\Illuminate\Support\Carbon::parse($since)->addHour())) {
                        throw new RuntimeException('The snapshot did not finish within an hour.');
                    }
                    throw new OperationWaiting();
                }
                $log('image', "snapshot {$image->externalId} ready");
                $stage('publish');
            }

            $image = app(\App\Lib\Resources\ResourceManager::class)->find($operation->organization_id, 'base_image', $slug)
                ?? throw new RuntimeException("No image {$slug}.");
            foreach ($images->publish($image) as $line) {
                $log('image', $line);
            }
        } catch (OperationWaiting $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->destroyBuilder($operation->organization_id, $slug, $log);

            throw $e;
        }
        $this->destroyBuilder($operation->organization_id, $slug, $log);

        return [];
    }

    private function destroyBuilder(string $organizationId, string $slug, Closure $log): void
    {
        $droplet = app(\App\Lib\Resources\ResourceManager::class)->find($organizationId, 'server', $slug);
        if ($droplet === null || in_array($droplet->state, [\App\Lib\Resources\ResourceState::Deleted, \App\Lib\Resources\ResourceState::Deleting], true)) {
            return;
        }
        try {
            app(\App\Lib\Cloud\ServerProvisioner::class)->destroy($droplet, \App\Lib\Audit\Actor::system('control-plane'));
            $log('droplet', "builder {$droplet->externalId} destroyed");
        } catch (Throwable $e) {
            $log('error', "builder {$droplet->externalId} could not be destroyed and is still billed: {$e->getMessage()}");
        }
        if (($node = DB::table('nodes')->where('node_id', app(\App\Lib\Cloud\ServerProvisioner::class)->nodeId($organizationId, $slug))->first()) !== null) {
            app(\App\Lib\Servers\NodeRemoval::class)->revoke($node, 'image builder');
        }
    }

    /** @param array<string, mixed> $meta */
    private function createDroplet(object $operation, array $meta, string $org, Closure $stage, Closure $log): void
    {
        // Nothing is billed until every check passes.
        $stage('preflight');
        $exit = \Illuminate\Support\Facades\Artisan::call('cloud:preflight', ['organization' => $org, '--provider' => (string) ($meta['spec']['provider'] ?? 'digitalocean')]);
        foreach (preg_split('/\R/', trim(\Illuminate\Support\Facades\Artisan::output())) as $line) {
            if (trim($line) !== '') {
                $log('check', trim($line));
            }
        }
        if ($exit !== 0) {
            throw new RuntimeException('Preflight found blocking problems; nothing was created.');
        }

        $stage('create');
        $provider = (string) ($meta['spec']['provider'] ?? 'digitalocean');
        $record = app(\App\Lib\Cloud\ServerProvisioner::class)->provision($org, (string) $meta['slug'], [
            'provider' => $provider,
            'region' => (string) $meta['spec']['region'],
            'size' => (string) $meta['spec']['size'],
        ] + array_intersect_key($meta['spec'], ['role' => true]));
        $log('droplet', "{$record->externalId} is {$record->state->value}");
        $stage('enroll');
    }

    /**
     * Whether the site's uploads live in Spaces rather than on its servers.
     *
     * A saved FILESYSTEM_DISK=s3 is not evidence: it can be typed without ever
     * deploying, and no setting proves that older uploads on a member were
     * moved (audit 2026-11-08, finding 6). Only a bucket this platform made
     * for the application counts, together with the setting that points at it.
     */
    private function uploadsOnS3(object $site): bool
    {
        if ($site->bucket_id === null) {
            return false;
        }
        foreach (preg_split('/\R/', $this->sites->env($site)) ?: [] as $line) {
            if (preg_match('/^FILESYSTEM_DISK=(.*)$/', trim($line), $m)) {
                return trim($m[1], "\"'") === 's3';
            }
        }

        return false;
    }

    /** The name of an archived application whose data the server still holds, if any. */
    private function archivedOn(string $organizationId, string $nodeIdOrSlug): ?string
    {
        $slug = strstr($nodeIdOrSlug, '.', true) ?: $nodeIdOrSlug;
        foreach (DB::table('site_archives')->where('organization_id', $organizationId)->get(['name', 'node_id', 'servers']) as $archive) {
            foreach ([(string) $archive->node_id, ...(json_decode((string) $archive->servers, true) ?: [])] as $server) {
                if ((strstr((string) $server, '.', true) ?: (string) $server) === $slug) {
                    return (string) $archive->name;
                }
            }
        }

        return null;
    }

    /** The pool a Droplet belongs to, from its resource attributes, if any. */
    private static function poolOf(object $resource): ?int
    {
        $attributes = is_array($resource->attributes ?? null)
            ? $resource->attributes
            : (json_decode((string) ($resource->attributes ?? ''), true) ?: []);

        return isset($attributes['pool']) ? (int) $attributes['pool'] : null;
    }

    /** Applies one recipe to a node and fails the operation if it does not succeed. */
    private function applyRecipe(object $site, array $spec, int $timeout): void
    {
        $result = $this->gateway->run($site->organization_id, $site->node_id, 'apply_recipe', ['spec' => $spec],
            timeoutSeconds: $timeout, waitSeconds: $timeout + 30, reason: "Apply {$spec['name']}");
        if (($result['status'] ?? null) !== 'succeeded') {
            throw new RuntimeException((string) ($result['error'] ?? 'The recipe failed.'));
        }
    }

    private function queue(int $id, int $delaySeconds = 0): int
    {
        $delaySeconds > 0 ? RunOperation::dispatch($id)->delay(now()->addSeconds($delaySeconds)) : RunOperation::dispatch($id);

        return $id;
    }

    private function site(int $id): object
    {
        return DB::table('sites')->where('id', $id)->first() ?? throw new RuntimeException("No site {$id}.");
    }

    private function node(string $nodeId): object
    {
        return DB::table('nodes')->where('node_id', $nodeId)->first() ?? throw new RuntimeException("No node {$nodeId}.");
    }

    /** A node that takes the server stack: not an Incus host or its ingress, which run only what Unharbored put there. */
    private function ordinaryNode(string $nodeId): object
    {
        $node = $this->node($nodeId);
        if (\App\Lib\Providers\Incus\IncusHost::reserved($node->role ?? null)) {
            throw new \InvalidArgumentException("{$nodeId} is an Incus host or its ingress: it has no server stack, firewall rules, PHP or containers to manage.");
        }

        return $node;
    }
}
