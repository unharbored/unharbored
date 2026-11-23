<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Vault\LocalKeyManager;
use Illuminate\Console\Command;

/**
 * Prints a fresh vault master key.
 *
 * Deliberately does not write to .env. Rotating a master key requires
 * re-wrapping every organisation data key first, and a command that silently
 * replaced the key would make every stored secret unreadable. See
 * Vault::rewrapDataKeys().
 */
final class GenerateVaultKey extends Command
{
    protected $signature = 'vault:generate-key';

    protected $description = 'Generate a vault master key for VAULT_MASTER_KEY';

    public function handle(): int
    {
        $this->line(LocalKeyManager::generateMasterKey());

        $this->newLine();
        $this->comment('Set this as VAULT_MASTER_KEY.');
        $this->comment('If you are replacing an existing key, run vault:rewrap afterwards or');
        $this->comment('every stored secret becomes unreadable.');

        return self::SUCCESS;
    }
}
