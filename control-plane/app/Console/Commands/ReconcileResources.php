<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Cloud\Reconciler;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Compares what DigitalOcean has against what we think it has.
 *
 * Intended to run on a schedule. The finding that matters is an untracked
 * resource: the customer is being billed for something we cannot see or
 * manage, and nothing else in the system would ever notice.
 *
 * Reports only. Automatically destroying something that looked untracked is
 * how a customer's production database disappears because a migration
 * half-ran.
 */
final class ReconcileResources extends Command
{
    protected $signature = 'cloud:reconcile
                            {organization? : Limit to one organisation}
                            {--stale-minutes=30 : How long a transient state may persist before it counts as stuck}';

    protected $description = 'Compare tracked resources against DigitalOcean and report drift';

    public function handle(Reconciler $reconciler): int
    {
        $organizations = $this->argument('organization') !== null
            ? [(string) $this->argument('organization')]
            : DB::table('organizations')->pluck('id')->all();

        if ($organizations === []) {
            $this->info('No organisations to reconcile.');

            return self::SUCCESS;
        }

        $staleMinutes = (int) $this->option('stale-minutes');
        $problems = 0;
        $inconclusive = 0;

        foreach ($organizations as $organizationId) {
            $report = $reconciler->reconcile($organizationId, staleAfterMinutes: $staleMinutes);

            $this->line($report->summary());

            if (! $report->checkedProvider) {
                $inconclusive++;
            }

            foreach ($report->untracked as $row) {
                $this->error(sprintf(
                    '  UNTRACKED  droplet %s (%s) in %s. Billing, and we cannot manage it.',
                    $row['external_id'],
                    $row['name'] ?? 'unnamed',
                    $row['region'] ?? 'unknown region'
                ));
                $problems++;
            }

            foreach ($report->stuck as $resource) {
                $this->warn(sprintf(
                    '  STUCK      %s %s has been %s since %s',
                    $resource->type,
                    $resource->slug,
                    $resource->state->value,
                    $resource->stateEnteredAt?->toIso8601String() ?? 'unknown'
                ));
                $problems++;
            }

            foreach ($report->missing as $resource) {
                $this->warn(sprintf(
                    '  MISSING    %s %s (droplet %s) is tracked but absent at DigitalOcean',
                    $resource->type,
                    $resource->slug,
                    $resource->externalId ?? 'no id'
                ));
                $problems++;
            }
        }

        if ($inconclusive > 0) {
            // Exit non-zero. A pass that could not reach DigitalOcean found
            // no untracked resources, and treating that as success would make
            // a monitoring check green while it was blind.
            $this->newLine();
            $this->error(sprintf('%d organisation(s) could not be checked against DigitalOcean.', $inconclusive));

            return self::FAILURE;
        }

        if ($problems > 0) {
            $this->newLine();
            $this->error(sprintf('%d finding(s). Nothing was changed.', $problems));

            return self::FAILURE;
        }

        $this->info('Everything is consistent.');

        return self::SUCCESS;
    }
}
