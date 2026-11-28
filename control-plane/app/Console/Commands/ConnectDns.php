<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Dns\Spaceship;
use Illuminate\Console\Command;
use Throwable;

/**
 * Connects a domain's DNS at Spaceship, for records Unharbored makes
 * itself. The key and secret are read from prompts, never arguments, so
 * they stay out of shell history and the process table, and are stored only
 * once Spaceship has accepted them.
 */
final class ConnectDns extends Command
{
    protected $signature = 'dns:connect {organization : Organisation id} {domain : The domain at Spaceship, e.g. example.com; several separated by commas}';

    protected $description = "Connect Spaceship domains' DNS records (key with dnsrecords:read and dnsrecords:write)";

    public function handle(Spaceship $spaceship): int
    {
        $key = trim((string) $this->secret('Spaceship API key'));
        $secret = trim((string) $this->secret('Spaceship API secret'));
        if ($key === '' || $secret === '') {
            $this->error('Both the key and the secret are needed.');

            return self::FAILURE;
        }

        $domain = (string) $this->argument('domain');
        try {
            $count = $spaceship->connect((string) $this->argument('organization'), $domain, $key, $secret);
        } catch (Throwable $e) {
            $this->error($e->getMessage().' Nothing was stored.');

            return self::FAILURE;
        }
        $this->info("Connected {$domain}: Spaceship listed its {$count} records. The key is kept encrypted in the vault.");

        return self::SUCCESS;
    }
}
