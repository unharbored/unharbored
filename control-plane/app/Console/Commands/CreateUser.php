<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Auth\Instance;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * user:create — the one account that signs in to the console.
 *
 * Run it in a real terminal: the password is read with hidden input. Running
 * it again for the same email replaces the password. Two-factor is off for
 * now, so no secret is issued.
 */
final class CreateUser extends Command
{
    protected $signature = 'user:create {email} {--name=}';

    protected $description = 'Create or reset the console login';

    public function handle(): int
    {
        $email = strtolower((string) $this->argument('email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Not an email address.');

            return self::FAILURE;
        }

        $password = (string) $this->secret('Password (at least 12 characters)');
        if (strlen($password) < 12) {
            $this->error('Use at least 12 characters.');

            return self::FAILURE;
        }
        if ($password !== (string) $this->secret('Again')) {
            $this->error('Those did not match.');

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => (string) ($this->option('name') ?: ($user->name ?? strstr($email, '@', true))),
            'password' => $password,
        ])->save();

        // Someone has to set the install up: the first person created is its administrator.
        if (! Instance::anyAdmin()) {
            Instance::setAdmin($user, true);
            $this->info('This is the first login, so it is the instance administrator.');
        }

        $this->info("Console login ready for {$email}.");

        return self::SUCCESS;
    }
}
