<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Containers\Containers;
use App\Lib\Operations\OperationRunner;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** server:containers-cleanup — remove dangling images and build cache on a prepared server. */
final class ServerContainersCleanup extends ServiceCommand
{
    protected $signature = 'server:containers-cleanup {node : Server id, e.g. web-1.org-1}
        {--as= : Act as this user (their role in the server\'s team applies)}
        {--queue : Leave it on the queue instead of running it now}';

    protected $description = 'Remove unused Docker layers (dangling images and build cache); never volumes or containers';

    public function handle(Containers $containers, OperationRunner $runner): int
    {
        try {
            [$org, $actor, $role] = $this->who((string) $this->argument('node'));
            $id = $containers->cleanup($org, (string) $this->argument('node'), $actor, $role, dispatch: (bool) $this->option('queue'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($id, $runner);
    }
}
