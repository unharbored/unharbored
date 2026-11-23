<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** node:role — mark the server previews go to (preview), or clear it (none). */
final class NodeRole extends Command
{
    protected $signature = 'node:role {node : e.g. preview-1.org-1} {role : preview or none}';

    protected $description = "Set a server's role";

    public function handle(): int
    {
        $role = (string) $this->argument('role');
        if (! in_array($role, ['preview', 'none'], true)) {
            $this->error('Role is preview or none.');

            return self::FAILURE;
        }
        $updated = DB::table('nodes')->where('node_id', $this->argument('node'))->update(['role' => $role === 'none' ? null : $role, 'updated_at' => now()]);
        $this->line($updated ? 'done' : 'no such node');

        return $updated ? self::SUCCESS : self::FAILURE;
    }
}
