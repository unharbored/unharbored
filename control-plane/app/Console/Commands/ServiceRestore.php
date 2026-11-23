<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Services\Services;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** service:restore — put a backup back, after a safety backup of what is there. {backup} is its id or its name. */
final class ServiceRestore extends ServiceCommand
{
    protected $signature = 'service:restore {node} {name} {backup : The backup id or name (shown on the service page and by the API)}
        {--confirm= : The service name again, to confirm that its data is replaced}
        {--as= : Act as this user (their role in the server\'s team applies)}
        {--queue : Leave it on the queue instead of running it now}';

    protected $description = "Restore a service's data from one of its backups";

    public function handle(Services $services, OperationRunner $runner): int
    {
        try {
            [$org, $actor, $role] = $this->who((string) $this->argument('node'));
            $service = $services->find($org, (string) $this->argument('node'), (string) $this->argument('name'));
            $ref = (string) $this->argument('backup');
            $backupId = (int) DB::table('service_backups')->where('service_id', $service->id)->where(fn ($q) => $q->where('id', ctype_digit($ref) ? (int) $ref : 0)->orWhere('name', $ref))->value('id');
            $id = $services->restore($org, (string) $this->argument('node'), (string) $this->argument('name'), $backupId, (string) $this->option('confirm'), $actor, $role, dispatch: (bool) $this->option('queue'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($id, $runner);
    }
}
