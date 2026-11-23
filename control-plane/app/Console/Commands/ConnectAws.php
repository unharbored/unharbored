<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Providers\Providers;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Connects AWS, or any service that speaks its API at a URL (LocalStack and
 * the like), to an organisation. The secret key is read from a prompt so it
 * stays out of shell history and the process table.
 */
final class ConnectAws extends Command
{
    protected $signature = 'cloud:connect-aws {organization : Organisation id}
                            {--key= : Access key id}
                            {--region=us-east-1 : Default region}
                            {--endpoint= : Service URL for an AWS-compatible service, e.g. http://localhost:4566}
                            {--ami= : Image to use instead of the current Ubuntu 24.04}';

    protected $description = 'Store an AWS (or AWS-compatible) credential for an organisation';

    public function handle(Providers $providers): int
    {
        $key = (string) ($this->option('key') ?: $this->ask('Access key id'));
        $secret = (string) $this->secret('Secret access key');
        if (trim($key) === '' || trim($secret) === '') {
            $this->error('A key id and a secret are both needed.');

            return self::FAILURE;
        }

        try {
            $who = $providers->aws()->connect((string) $this->argument('organization'), [
                'access_key_id' => $key, 'secret_access_key' => $secret, 'region' => (string) $this->option('region'),
                'endpoint' => (string) $this->option('endpoint'), 'ami' => (string) $this->option('ami'),
            ]);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Connected as '.$who['Arn'].'.');

        return self::SUCCESS;
    }
}
