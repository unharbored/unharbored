<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Auth\Roles;
use App\Lib\Operations\OperationRunner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Shared by the service:* commands. They act for the person named by --as
 * (their role in the server's team is checked, as it will be from the API), or,
 * with no --as, for the operator of this control plane, who has every role.
 * Operations run here and now, printing their stages, unless --queue says the worker should.
 */
abstract class ServiceCommand extends Command
{
    /** @return array{0: string, 1: string, 2: string} organisation id, actor, role */
    protected function who(string $nodeId): array
    {
        $organizationId = (string) DB::table('nodes')->where('node_id', $nodeId)->value('organization_id');
        if ($organizationId === '') {
            throw new InvalidArgumentException("There is no server {$nodeId}.");
        }
        $email = (string) $this->option('as');
        if ($email === '') {
            return [$organizationId, 'cli', Roles::OWNER];
        }
        $user = User::where('email', $email)->first() ?? throw new InvalidArgumentException("There is no user {$email}.");
        $role = Roles::roleOf($user, $organizationId) ?? throw new InvalidArgumentException("{$email} is not a member of this server's team.");

        return [$organizationId, $email, $role];
    }

    /** Runs the operation now, or leaves it on the queue. */
    protected function finish(int $operationId, OperationRunner $runner): int
    {
        if ($this->option('queue')) {
            $this->info("Queued operation {$operationId}. See it with: php artisan operation:show {$operationId}");

            return self::SUCCESS;
        }
        $status = $runner->run($operationId, fn (string $line) => $this->line($line));

        return $status === 'succeeded' ? self::SUCCESS : self::FAILURE;
    }
}
