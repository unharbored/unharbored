<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Services\Services;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** service:action — start, stop or restart a service. */
final class ServiceAction extends ServiceCommand
{
    protected $signature = 'service:action {node} {name} {action : start, stop or restart}
        {--as= : Act as this user (their role in the server\'s team applies)}
        {--queue : Leave it on the queue instead of running it now}';

    protected $description = 'Start, stop or restart a service';

    public function handle(Services $services, OperationRunner $runner): int
    {
        try {
            [$org, $actor, $role] = $this->who((string) $this->argument('node'));
            $id = $services->action($org, (string) $this->argument('node'), (string) $this->argument('name'), (string) $this->argument('action'), $actor, $role, dispatch: (bool) $this->option('queue'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($id, $runner);
    }
}
