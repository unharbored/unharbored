<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Providers\Providers;
use Illuminate\Console\Command;
use InvalidArgumentException;

/** Connects a Hetzner Cloud project (its API token, read and write) to an organisation. The token is read from a prompt. */
final class ConnectHetzner extends Command
{
    protected $signature = 'cloud:connect-hetzner {organization : Organisation id}';

    protected $description = 'Store a Hetzner Cloud API token for an organisation';

    public function handle(Providers $providers): int
    {
        $token = (string) $this->secret('Hetzner Cloud API token');
        try {
            $providers->hetzner()->connect((string) $this->argument('organization'), $token);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info('Connected.');

        return self::SUCCESS;
    }
}
