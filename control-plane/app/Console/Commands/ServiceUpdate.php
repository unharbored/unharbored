<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Services\Services;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** service:update — update a service to its template's newer version: snapshot, fetch, switch, health; rolled back if unhealthy. */
final class ServiceUpdate extends ServiceCommand
{
    protected $signature = 'service:update {node} {name}
        {--input=* : A value for an input the new version adds, key=value}
        {--confirm : Confirm a change of download host or image repository}
        {--approve-ports : Approve the firewall ports the new version asks for}
        {--acknowledge : Accept the acknowledgement the new version asks for}
        {--check : Only say what an update would change}
        {--as= : Act as this user (their role in the server\'s team applies)}
        {--queue : Leave it on the queue instead of running it now}';

    protected $description = 'Update a service to the newer version of its template';

    public function handle(Services $services, OperationRunner $runner): int
    {
        try {
            [$org, $actor, $role] = $this->who((string) $this->argument('node'));
            if ($this->option('check')) {
                $u = $services->updateAvailable($services->find($org, (string) $this->argument('node'), (string) $this->argument('name')));
                $this->line($u === null ? 'Up to date.' : "Version {$u['version']} is available (installed {$u['from']})".($u['allowed'] ? '' : ", but: {$u['refused']}"));
                foreach ($u['changes'] ?? [] as $c) {
                    $this->line("  - {$c}");
                }
                $u !== null && $u['confirm'] && $this->warn('This needs --confirm.');

                return self::SUCCESS;
            }
            $inputs = [];
            foreach ((array) $this->option('input') as $pair) {
                [$k, $v] = array_pad(explode('=', (string) $pair, 2), 2, '');
                $inputs[$k] = $v;
            }
            $id = $services->update($org, (string) $this->argument('node'), (string) $this->argument('name'), $inputs, $actor, $role, (bool) $this->option('confirm'),
                (bool) $this->option('approve-ports'), (bool) $this->option('acknowledge'), dispatch: (bool) $this->option('queue'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($id, $runner);
    }
}
