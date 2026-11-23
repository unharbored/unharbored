<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Cloud\CredentialStore;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Attaches a DigitalOcean account to an organisation.
 *
 * The token is read from a prompt rather than taken as an argument, so it
 * does not land in the shell history or the process table.
 *
 * A personal access token is account-wide and cannot be scoped, which is fine
 * while the only connected account is our own and not fine for external
 * tenants. See ADR 0001 and PLAN.md on OAuth.
 */
final class ConnectDigitalOcean extends Command
{
    protected $signature = 'cloud:connect {organization : Organisation id} {--name= : Create the organisation if it does not exist}';

    protected $description = 'Store a DigitalOcean credential for an organisation';

    public function handle(CredentialStore $credentials): int
    {
        $organizationId = (string) $this->argument('organization');

        $exists = DB::table('organizations')->where('id', $organizationId)->exists();

        if (! $exists) {
            $name = $this->option('name');

            if ($name === null) {
                $this->error(sprintf('Organisation %s does not exist. Pass --name to create it.', $organizationId));

                return self::FAILURE;
            }

            DB::table('organizations')->insert([
                'id' => $organizationId,
                'name' => (string) $name,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            \App\Lib\Auth\Projects::ensureDefault($organizationId);
        }

        $token = (string) $this->secret('DigitalOcean personal access token');

        if (trim($token) === '') {
            $this->error('No token entered.');

            return self::FAILURE;
        }

        $credentials->connect($organizationId, trim($token));

        $this->info('Stored. Verifying against the DigitalOcean API.');

        if (! $credentials->verify($organizationId)) {
            $this->error('DigitalOcean rejected the token. It has been marked revoked.');

            return self::FAILURE;
        }

        $row = DB::table('cloud_credentials')->where('organization_id', $organizationId)->first();

        $this->info(sprintf(
            'Connected %s to DigitalOcean account %s.',
            $organizationId,
            $row->account_email ?? 'unknown'
        ));

        return self::SUCCESS;
    }
}
