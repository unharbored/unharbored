<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Containers\Containers;
use App\Lib\Operations\OperationRunner;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** server:containers — prepare a server for containers (Docker Engine). */
final class ServerContainers extends ServiceCommand
{
    protected $signature = 'server:containers {node : Server id, e.g. web-1.org-1}
        {--acknowledge-small : Accept Docker\'s memory use (about 100 MB) on a server with under 1.5 GB}
        {--as= : Act as this user (their role in the server\'s team applies)}
        {--queue : Leave it on the queue instead of running it now}';

    protected $description = 'Prepare a server for containers: install and configure Docker Engine';

    public function handle(Containers $containers, OperationRunner $runner): int
    {
        try {
            [$org, $actor, $role] = $this->who((string) $this->argument('node'));
            $id = $containers->prepare($org, (string) $this->argument('node'), $actor, $role, (bool) $this->option('acknowledge-small'), dispatch: (bool) $this->option('queue'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($id, $runner);
    }
}
