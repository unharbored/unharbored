<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Services\Services;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** service:remove — remove a service; the data is kept unless --delete-data, and --keep-data says so out loud. */
final class ServiceRemove extends ServiceCommand
{
    protected $signature = 'service:remove {node} {name}
        {--keep-data : Keep the data folder}
        {--delete-data : Delete the data folder, for good}
        {--as= : Act as this user (their role in the server\'s team applies)}
        {--queue : Leave it on the queue instead of running it now}';

    protected $description = 'Remove a service, keeping or deleting its data (you must choose)';

    public function handle(Services $services, OperationRunner $runner): int
    {
        if ($this->option('keep-data') && $this->option('delete-data')) {
            $this->error('Choose one: --keep-data or --delete-data, not both.');

            return self::FAILURE;
        }
        $deleteData = $this->option('delete-data') ? true : ($this->option('keep-data') ? false : null);
        try {
            [$org, $actor, $role] = $this->who((string) $this->argument('node'));
            $id = $services->remove($org, (string) $this->argument('node'), (string) $this->argument('name'), $deleteData, $actor, $role, dispatch: (bool) $this->option('queue'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($id, $runner);
    }
}
