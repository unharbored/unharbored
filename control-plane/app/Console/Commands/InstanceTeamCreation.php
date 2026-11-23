<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Auth\Instance;
use Illuminate\Console\Command;

/** instance:team-creation — who may create a team on this install. */
final class InstanceTeamCreation extends Command
{
    protected $signature = 'instance:team-creation {who? : "anyone" or "admin"; leave out to show the current setting}';

    protected $description = 'Show or set who may create teams: anyone signed in, or only instance administrators';

    public function handle(): int
    {
        $who = $this->argument('who');
        if ($who === null) {
            $this->line('Team creation: '.Instance::teamCreation());

            return self::SUCCESS;
        }

        try {
            Instance::setTeamCreation((string) $who);
        } catch (\InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }
        $this->info(Instance::teamCreation() === Instance::ANYONE ? 'Anyone signed in may now create a team.' : 'Only instance administrators may now create a team.');

        return self::SUCCESS;
    }
}
