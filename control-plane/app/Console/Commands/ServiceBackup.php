<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Services\Services;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** service:backup — back up one service's data now. Works whatever the service's backup setting says. */
final class ServiceBackup extends ServiceCommand
{
    protected $signature = 'service:backup {node} {name}
        {--as= : Act as this user (their role in the server\'s team applies)}
        {--queue : Leave it on the queue instead of running it now}';

    protected $description = "Back up a service's data (on the server, and in the backup bucket when the service is set to spaces)";

    public function handle(Services $services, OperationRunner $runner): int
    {
        try {
            [$org, $actor, $role] = $this->who((string) $this->argument('node'));
            $id = $services->backup($org, (string) $this->argument('node'), (string) $this->argument('name'), 'manual', $actor, $role, dispatch: (bool) $this->option('queue'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($id, $runner);
    }
}
