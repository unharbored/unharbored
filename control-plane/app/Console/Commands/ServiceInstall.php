<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use App\Lib\Services\Services;
use Illuminate\Auth\Access\AuthorizationException;
use InvalidArgumentException;

/** service:install — install a service from a template on a server. */
final class ServiceInstall extends ServiceCommand
{
    protected $signature = 'service:install {node : Server id, e.g. web-1.org-1} {template : Template id, e.g. rustfs} {name : What to call the service}
        {--input=* : key=value for a template input; secret inputs are asked for, never taken here}
        {--as= : Act as this user (their role in the server\'s team applies)}
        {--acknowledge : Accept the template\'s warning, if it has one}
        {--queue : Leave it on the queue instead of running it now}';

    protected $description = 'Install a service from a template on a server';

    public function handle(Services $services, OperationRunner $runner): int
    {
        try {
            [$org, $actor, $role] = $this->who((string) $this->argument('node'));
            $def = $services->definition((string) $this->argument('template'));

            $values = ['name' => (string) $this->argument('name')];
            foreach ((array) $this->option('input') as $pair) {
                [$k, $v] = array_pad(explode('=', (string) $pair, 2), 2, null);
                $type = array_column($def->inputs, 'type', 'key')[$k] ?? null;
                if ($type === 'secret') {
                    throw new InvalidArgumentException("{$k} is a secret: it is asked for below, not given as an argument (arguments show in process lists and shell history).");
                }
                if ($v === null) {
                    throw new InvalidArgumentException("--input needs key=value, got {$pair}.");
                }
                $values[(string) $k] = $v;
            }
            foreach ($def->inputs as $input) {
                if ($input['type'] === 'secret') {
                    $values[$input['key']] = (string) $this->secret($input['label'].' (hidden)');
                }
            }
            if ($def->acknowledge !== null) {
                $this->warn($def->acknowledge);
            }

            $made = $services->install($org, (string) $this->argument('node'), $def->id, $values, $actor, $role, (bool) $this->option('acknowledge'), dispatch: (bool) $this->option('queue'));
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        return $this->finish($made['operation_id'], $runner);
    }
}
