<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Agent\AgentRelease;
use App\Lib\Cloud\CredentialStore;
use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Gateway\GatewayClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Checks everything a Droplet will need, before one is created.
 *
 * A Droplet that boots and never appears is the expensive failure here: it
 * costs money, gives no error, and the thing that would have reported the
 * problem is the thing that failed to start. Almost every cause is visible
 * from here beforehand.
 *
 * Each check says what breaks if it fails, because "FAIL: public_url" is not
 * actionable and "the Droplet will download the agent from localhost, which
 * is itself" is.
 */
final class Preflight extends Command
{
    protected $signature = 'cloud:preflight
                            {organization : Organisation to check}
                            {--provider=digitalocean : Provider the server will be created on}
                            {--skip-reachability : Do not make outbound calls to the public URL}';

    protected $description = 'Check that a Droplet would succeed, before creating one';

    private int $failures = 0;

    private int $warnings = 0;

    /**
     * Nothing is injected.
     *
     * A preflight check that cannot run when configuration is wrong is
     * useless, and configuration being wrong is exactly what it is for.
     * Injecting services here would make the container resolve the vault
     * before the first check runs, so a missing master key produced a stack
     * trace instead of the line saying the master key is missing.
     *
     * Every check resolves what it needs itself, and survives not getting it.
     */
    public function handle(): int
    {
        $organizationId = (string) $this->argument('organization');

        $this->line('Checking what a Droplet in '.$organizationId.' would need.');
        $this->newLine();

        // Each check is guarded individually. One that blows up, usually
        // because the thing it inspects is not configured, must report that
        // and let the rest run. A preflight that stops at the first problem
        // makes you fix them one round trip at a time.
        $checks = [
            'database' => fn () => $this->checkDatabase(),
            'organisation' => fn () => $this->checkOrganisation($organizationId),
            'vault master key' => fn () => $this->checkVault(),
            'provider credential' => fn () => $this->option('provider') === 'digitalocean' ? $this->checkCredential($organizationId) : $this->checkProvider($organizationId, (string) $this->option('provider')),
            'organisation CA' => fn () => $this->checkCertificateAuthority($organizationId),
            'agent builds' => fn () => $this->checkAgentBuilds(),
            'public url' => fn () => $this->checkPublicUrl(),
            'gateway' => fn () => $this->checkGateway(),
            'gateway organisations' => fn () => $this->checkGatewayKnowsOrganisation($organizationId),
        ];

        if (! $this->option('skip-reachability')) {
            $checks['reachable'] = fn () => $this->checkReachability();
        }

        foreach ($checks as $name => $check) {
            try {
                $check();
            } catch (Throwable $e) {
                $this->blocker($name, 'Check could not run: '.$e->getMessage());
            }
        }

        $this->newLine();

        if ($this->failures > 0) {
            $this->error(sprintf('%d blocking problem(s). A Droplet would not come up.', $this->failures));

            return self::FAILURE;
        }

        if ($this->warnings > 0) {
            $this->warn(sprintf('%d warning(s). A Droplet would probably come up.', $this->warnings));

            return self::SUCCESS;
        }

        $this->info('Everything a Droplet needs is in place.');

        return self::SUCCESS;
    }

    private function pass(string $check, string $detail = ''): void
    {
        $this->line(sprintf('  <info>ok</info>    %-28s %s', $check, $detail));
    }

    private function blocker(string $check, string $consequence): void
    {
        $this->failures++;
        $this->line(sprintf('  <error>FAIL</error>  %-28s %s', $check, $consequence));
    }

    private function caution(string $check, string $detail): void
    {
        $this->warnings++;
        $this->line(sprintf('  <comment>warn</comment>  %-28s %s', $check, $detail));
    }

    /**
     * Build a service, reporting a blocker rather than throwing if it cannot
     * be built. Usually that means configuration is missing, which is the
     * thing this command exists to tell you about.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T|null
     */
    private function resolve(string $class, string $check): ?object
    {
        try {
            return app($class);
        } catch (Throwable $e) {
            $this->blocker($check, 'Could not be configured: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Everything else reads the database, so a failure here explains the rest
     * rather than leaving nine checks to fail individually.
     */
    private function checkDatabase(): void
    {
        try {
            DB::connection()->getPdo();
            DB::table('organizations')->limit(1)->get();
        } catch (Throwable $e) {
            $this->blocker('database', 'Not reachable or not migrated. Run php artisan migrate. ('.$e->getMessage().')');

            return;
        }

        $this->pass('database', (string) config('database.default'));
    }

    private function checkOrganisation(string $organizationId): void
    {
        if (DB::table('organizations')->where('id', $organizationId)->exists()) {
            $this->pass('organisation', $organizationId);

            return;
        }

        $this->blocker('organisation', sprintf(
            '%s does not exist. Create it with: cloud:connect %s --name "..."',
            $organizationId,
            $organizationId
        ));
    }

    private function checkCredential(string $organizationId): void
    {
        $credentials = $this->resolve(CredentialStore::class, 'digitalocean credential');

        if ($credentials === null) {
            return;
        }

        if (! $credentials->has($organizationId)) {
            $this->blocker('digitalocean credential', 'Not connected or revoked. Nothing can be provisioned.');

            return;
        }

        try {
            if (! $credentials->verify($organizationId)) {
                $this->blocker('digitalocean credential', 'DigitalOcean rejected the token. It is now marked revoked.');

                return;
            }
        } catch (Throwable $e) {
            $this->blocker('digitalocean credential', 'Could not verify: '.$e->getMessage());

            return;
        }

        $row = DB::table('cloud_credentials')->where('organization_id', $organizationId)->first();

        $this->pass('digitalocean credential', (string) ($row->account_email ?? 'verified'));
    }

    /** Any other api provider: its credential is connected and the provider accepts it. */
    private function checkProvider(string $organizationId, string $provider): void
    {
        $registry = $this->resolve(\App\Lib\Providers\Providers::class, "{$provider} credential");
        if ($registry === null) {
            return;
        }
        if (! $registry->has($provider) || $registry->get($provider)->method() !== 'api') {
            $this->blocker("{$provider} credential", 'Not a provider that creates servers.');

            return;
        }
        if (! app(CredentialStore::class)->has($organizationId, $provider)) {
            $this->blocker("{$provider} credential", 'Not connected or revoked. Nothing can be provisioned.');

            return;
        }
        try {
            $who = $registry->get($provider)->verify($organizationId);
        } catch (Throwable $e) {
            $this->blocker("{$provider} credential", 'The provider did not accept it: '.$e->getMessage());

            return;
        }
        $this->pass("{$provider} credential", (string) ($who['Arn'] ?? 'verified'));
    }

    private function checkVault(): void
    {
        if ((string) config('vault.master_key') === '') {
            $this->blocker('vault master key', 'Not set. The CA private key cannot be stored, so enrollment fails.');

            return;
        }

        $this->pass('vault master key', 'set');
    }

    private function checkCertificateAuthority(string $organizationId): void
    {
        $enrollment = $this->resolve(EnrollmentService::class, 'organisation CA');

        if ($enrollment === null) {
            return;
        }

        try {
            $ca = $enrollment->certificateAuthorityFor($organizationId);
            $this->pass('organisation CA', 'present');

            // The gateway certificate must be issued by this CA, or agents
            // will refuse the connection after enrolling successfully.
            if (! DB::table('organization_certificate_authorities')->where('organization_id', $organizationId)->exists()) {
                $this->caution('organisation CA', 'Just created. Run gateway:sync-organisations.');
            }

            unset($ca);
        } catch (Throwable $e) {
            $this->blocker('organisation CA', 'Could not load or create: '.$e->getMessage());
        }
    }

    private function checkAgentBuilds(): void
    {
        $release = $this->resolve(AgentRelease::class, 'agent builds');

        if ($release === null) {
            return;
        }

        $available = $release->available();

        if ($available === []) {
            $this->blocker('agent builds', 'None present. Run scripts/build-agent.sh. Nodes have nothing to download.');

            return;
        }

        foreach ($available as $platform) {
            $this->pass('agent build', sprintf(
                '%s  %s  %d bytes',
                $platform,
                substr($release->sha256($platform), 0, 16).'...',
                $release->size($platform),
            ));
        }

        if (! in_array('linux-amd64', $available, true)) {
            $this->caution('agent builds', 'No linux-amd64 build; most Droplet sizes need it.');
        }
    }

    private function checkPublicUrl(): void
    {
        $url = (string) config('control_plane.public_url');

        if ($url === '') {
            $this->blocker('public url', 'Not set. cloud-init would point the Droplet at the certificate name, which may not resolve.');

            return;
        }

        if (! str_starts_with($url, 'https://')) {
            $this->blocker('public url', 'Not https. The agent pins the CA and will refuse a plain connection.');

            return;
        }

        // The most common mistake, and it produces a Droplet that downloads
        // the agent from itself.
        foreach (['localhost', '127.0.0.1', '0.0.0.0', '::1'] as $local) {
            if (str_contains($url, $local)) {
                $this->blocker('public url', sprintf(
                    '%s points at %s. A Droplet would resolve that to itself and never reach you.',
                    $url,
                    $local
                ));

                return;
            }
        }

        $this->pass('public url', $url);
    }

    private function checkGateway(): void
    {
        $gateway = $this->resolve(GatewayClient::class, 'gateway');

        if ($gateway === null) {
            return;
        }

        try {
            $health = $gateway->health();
        } catch (Throwable $e) {
            $this->blocker('gateway', 'Not reachable: '.$e->getMessage());

            return;
        }

        if (! $health['reachable']) {
            $this->blocker('gateway', 'Not reachable. A node would enroll and then have nothing to connect to.');

            return;
        }

        $body = $health['body'];

        $this->pass('gateway', sprintf(
            'version %s, %d organisation(s), %d node(s)',
            $body['version'] ?? '?',
            $body['organisations'] ?? 0,
            $body['connected_nodes'] ?? 0,
        ));

        $sessionAddress = (string) config('control_plane.session_address');

        foreach (['localhost', '127.0.0.1', '0.0.0.0'] as $local) {
            if (str_contains($sessionAddress, $local)) {
                $this->blocker('gateway address', sprintf(
                    'Agents are told to connect to %s, which a Droplet resolves to itself.',
                    $sessionAddress
                ));

                return;
            }
        }

        $this->pass('gateway address', $sessionAddress);
    }

    private function checkGatewayKnowsOrganisation(string $organizationId): void
    {
        $gateway = $this->resolve(GatewayClient::class, 'gateway organisations');

        if ($gateway === null) {
            return;
        }

        try {
            $health = $gateway->health();
        } catch (Throwable) {
            return;
        }

        if (($health['body']['organisations'] ?? 0) === 0) {
            $this->blocker('gateway organisations', 'The gateway knows none, so it refuses every node. Run gateway:sync-organisations.');
        }
    }

    private function checkReachability(): void
    {
        $url = (string) config('control_plane.public_url');

        if ($url === '') {
            return;
        }

        // Reaching it from here is weaker evidence than reaching it from a
        // Droplet, but it catches the obvious cases: nothing listening, a
        // certificate from the wrong CA, a tunnel that has expired.
        foreach (['/api/agent/linux-amd64/sha256', '/up'] as $path) {
            try {
                $response = Http::timeout(10)->get(rtrim($url, '/').$path);

                if ($response->successful()) {
                    $this->pass('reachable', $path);
                } else {
                    $this->caution('reachable', sprintf('%s returned %d', $path, $response->status()));
                }
            } catch (Throwable $e) {
                $this->caution('reachable', sprintf(
                    '%s could not be fetched from here (%s). A Droplet may still reach it.',
                    $path,
                    $e->getMessage()
                ));
            }
        }
    }
}
