<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Providers\Custom\SshAdoption;
use Illuminate\Console\Command;

final class SweepSshAdoptions extends Command
{
    protected $signature = 'ssh-adopt:sweep';

    protected $description = 'Delete SSH logins held for connecting a server that are older than an hour';

    public function handle(SshAdoption $adoptions): int
    {
        $n = $adoptions->sweep();
        $this->info("{$n} login(s) deleted.");

        return self::SUCCESS;
    }
}
