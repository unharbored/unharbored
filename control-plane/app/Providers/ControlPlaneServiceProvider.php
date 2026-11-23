<?php

declare(strict_types=1);

namespace App\Providers;

use App\Lib\Agent\AgentRelease;
use App\Lib\Audit\AuditLog;
use App\Lib\Cloud\CloudInit;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Cloud\ServerProvisioner;
use App\Lib\Providers\Providers;
use App\Lib\Cloud\Reconciler;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Gateway\GatewayClient;
use App\Lib\Resources\ResourceManager;
use App\Lib\Servers\AgentUpgrade;
use App\Lib\Vault\Vault;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the control plane services explicitly.
 *
 * These bindings are not decoration. Laravel's container falls back to a
 * parameter's default value when it cannot or does not resolve it, so a
 * constructor written as `?AuditLog $audit = null` silently resolves to null.
 * That is exactly what happened here: the container-built EnrollmentService
 * had no audit log, so enrollment went unrecorded in production while every
 * test passed, because the tests constructed the service by hand and supplied
 * one.
 *
 * The audit log is now a required dependency everywhere it matters, which
 * makes an un-audited instance impossible to construct rather than merely
 * unlikely. These bindings make the wiring visible in one place.
 */
final class ControlPlaneServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(AuditLog::class, fn (): AuditLog => new AuditLog());

        $this->app->singleton(ResourceManager::class, fn (Application $app): ResourceManager => new ResourceManager(
            $app->make(AuditLog::class),
        ));

        $this->app->singleton(EnrollmentService::class, fn (Application $app): EnrollmentService => new EnrollmentService(
            $app->make(Vault::class),
            $app->make(AuditLog::class),
        ));

        $this->app->singleton(CredentialStore::class, fn (Application $app): CredentialStore => new CredentialStore(
            vault: $app->make(Vault::class),
            http: $app->make(HttpFactory::class),
            cache: $app->make(Cache::class),
            audit: $app->make(AuditLog::class),
        ));

        $this->app->singleton(AgentRelease::class, fn (): AgentRelease => new AgentRelease(
            directory: (string) config('control_plane.agent_directory'),
            version: (string) config('control_plane.agent_version'),
        ));

        $this->app->singleton(CloudInit::class, function (Application $app): CloudInit {
            // The public URL is what a Droplet can actually reach. Falling
            // back to the certificate name keeps local tests working, where
            // the two are the same.
            $base = (string) config('control_plane.public_url')
                ?: sprintf('https://%s', config('control_plane.server_name'));

            return new CloudInit(
                release: $app->make(AgentRelease::class),
                baseUrl: $base,
                serverName: (string) config('control_plane.server_name'),
            );
        });

        $this->app->bind(\App\Lib\Providers\Custom\RemoteShell::class, \App\Lib\Providers\Custom\PhpseclibShell::class);

        $this->app->bind(\App\Lib\Scaling\EdgeDriver::class, \App\Lib\Cloud\LoadBalancers::class);

        $this->app->singleton(Providers::class, fn (Application $app): Providers => new Providers(
            $app->make(CredentialStore::class),
            $app->make(HttpFactory::class),
        ));

        $this->app->singleton(ServerProvisioner::class, fn (Application $app): ServerProvisioner => new ServerProvisioner(
            providers: $app->make(Providers::class),
            resources: $app->make(ResourceManager::class),
            enrollment: $app->make(EnrollmentService::class),
            cloudInit: $app->make(CloudInit::class),
        ));

        $this->app->singleton(GatewayClient::class, fn (Application $app): GatewayClient => new GatewayClient(
            baseUrl: (string) config('control_plane.gateway_url'),
            token: (string) config('control_plane.gateway_token'),
            http: $app->make(HttpFactory::class),
            audit: $app->make(AuditLog::class),
        ));

        // Same URL and the same binaries as cloud-init, so an upgraded node
        // and a freshly booted one run the identical agent.
        $this->app->singleton(AgentUpgrade::class, fn (Application $app): AgentUpgrade => new AgentUpgrade(
            release: $app->make(AgentRelease::class),
            gateway: $app->make(GatewayClient::class),
            publicUrl: (string) config('control_plane.public_url')
                ?: sprintf('https://%s', config('control_plane.server_name')),
        ));

        $this->app->singleton(Reconciler::class, fn (Application $app): Reconciler => new Reconciler(
            $app->make(CredentialStore::class),
            $app->make(ResourceManager::class),
            $app->make(AuditLog::class),
        ));
    }
}
