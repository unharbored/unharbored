<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Cloud\ProviderKeys;
use Illuminate\Console\Command;

/** provider-keys:verify — daily: asks each provider whether its key still works. */
final class VerifyProviderKeys extends Command
{
    protected $signature = 'provider-keys:verify';

    protected $description = 'Check every connected cloud and DNS provider key';

    public function handle(ProviderKeys $keys): int
    {
        $rejected = $keys->verifyAll();
        $this->line($rejected === 0 ? 'every key works' : "{$rejected} key(s) rejected");

        return self::SUCCESS;
    }
}
