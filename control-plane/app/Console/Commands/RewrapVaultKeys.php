<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Console\Command;
use Throwable;

/**
 * Re-wraps organisation data keys onto a new master key.
 *
 * Touches one row per organisation and no secret, which is the whole
 * economic argument for envelope encryption: rotating the master key is
 * O(organisations), not O(secrets).
 */
final class RewrapVaultKeys extends Command
{
    protected $signature = 'vault:rewrap {--pretend : Report what would change without writing}';

    protected $description = 'Re-wrap organisation data keys onto the current vault master key';

    public function handle(Vault $vault): int
    {
        $pending = $vault->pendingRewrapCount();

        if ($pending === 0) {
            $this->info('Nothing to do. Every data key is already on the current master key.');

            return self::SUCCESS;
        }

        $this->line(sprintf('%d data key(s) are wrapped by a previous master key.', $pending));

        if ($this->option('pretend')) {
            return self::SUCCESS;
        }

        $previousKey = (string) config('vault.previous_master_key');
        $previousId = (string) config('vault.previous_master_key_id');

        if ($previousKey === '' || $previousId === '') {
            $this->error('VAULT_PREVIOUS_MASTER_KEY and VAULT_PREVIOUS_MASTER_KEY_ID must both be set.');
            $this->line('The old key is required to unwrap what is already on disk; the new key cannot do it.');

            return self::FAILURE;
        }

        try {
            $count = $vault->rewrapDataKeys(new LocalKeyManager($previousKey, $previousId));
        } catch (Throwable $e) {
            // Never echo the exception wholesale: vault failures are close to
            // key material and this output is routinely pasted into tickets.
            $this->error('Rewrap failed: '.$e->getMessage());
            $this->line('No partial state is left behind. Fix the cause and run again.');

            return self::FAILURE;
        }

        $this->info(sprintf('Re-wrapped %d data key(s).', $count));

        $remaining = $vault->pendingRewrapCount();
        if ($remaining > 0) {
            $this->warn(sprintf('%d still pending. Run again, or check for a third master key in use.', $remaining));

            return self::FAILURE;
        }

        $this->info('Rotation complete. You can now clear the VAULT_PREVIOUS_MASTER_KEY values.');

        return self::SUCCESS;
    }
}
