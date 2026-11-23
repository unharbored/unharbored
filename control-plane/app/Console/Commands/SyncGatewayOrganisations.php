<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Enrollment\EnrollmentService;
use App\Lib\Gateway\Exceptions\GatewayUnavailable;
use App\Lib\Gateway\GatewayClient;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Tells the gateway which organisations exist and which CA identifies each.
 *
 * The gateway places a node in a tenant by the CA that signed its
 * certificate, so without this it knows nobody and refuses every connection.
 * That is the right default on a cold start, and it means this has to run
 * after the gateway restarts.
 *
 * Only certificates are sent. The CA private keys stay in the vault: the
 * gateway only ever verifies, and giving it signing power would widen a
 * gateway compromise from "can command nodes" to "can mint node identities".
 */
final class SyncGatewayOrganisations extends Command
{
    protected $signature = 'gateway:sync-organisations {--dry-run : Show what would be sent}';

    protected $description = 'Send organisation CA certificates to the session gateway';

    public function handle(GatewayClient $gateway, EnrollmentService $enrollment, \App\Lib\Gateway\OrganisationSync $sync): int
    {
        $rows = DB::table('organization_certificate_authorities')->get();

        if ($rows->isEmpty()) {
            $this->warn('No organisation has a CA yet, so the gateway would accept nobody.');
            $this->line('A CA is created on first enrollment, or by cloud:issue-token.');

            return self::FAILURE;
        }

        $payload = $sync->payload();

        foreach ($payload as $org) {
            $this->line(sprintf('  %s%s', $org['id'], $org['revoked_serials'] === [] ? '' : sprintf(' (%d revoked)', count($org['revoked_serials']))));
        }

        if ($this->option('dry-run')) {
            $this->info(sprintf('%d organisation(s) would be sent.', count($payload)));

            return self::SUCCESS;
        }

        try {
            $accepted = $gateway->syncOrganisations($payload);
        } catch (GatewayUnavailable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf('The gateway accepted %d organisation(s).', $accepted));

        return self::SUCCESS;
    }
}
