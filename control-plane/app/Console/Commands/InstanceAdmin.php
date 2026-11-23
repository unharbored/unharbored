<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Auth\Instance;
use App\Models\User;
use Illuminate\Console\Command;

/** instance:admin — who administers this install (setup, and who may create teams). Not a way into any team. */
final class InstanceAdmin extends Command
{
    protected $signature = 'instance:admin {email : The person} {--remove : Take the role away}';

    protected $description = 'Make someone an instance administrator, or remove the role';

    public function handle(): int
    {
        $user = User::where('email', strtolower((string) $this->argument('email')))->first();
        if ($user === null) {
            $this->error('No such user. Create them with user:create first.');

            return self::FAILURE;
        }

        if ($this->option('remove')) {
            Instance::setAdmin($user, false);
            if (! Instance::anyAdmin()) {
                $this->warn('There is no instance administrator now. Run instance:admin again to name one.');
            }
            $this->info("{$user->email} is no longer an instance administrator.");

            return self::SUCCESS;
        }

        Instance::setAdmin($user, true);
        $this->info("{$user->email} is an instance administrator. This opens no team: they join teams as anyone does.");

        return self::SUCCESS;
    }
}
