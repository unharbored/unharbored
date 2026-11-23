<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Services\Services;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** service:list — the services of a team, or of every team. */
final class ServiceList extends Command
{
    protected $signature = 'service:list {--org= : Only this organisation id}';

    protected $description = 'List template services and their addresses';

    public function handle(Services $services): int
    {
        $orgs = $this->option('org') ? [(string) $this->option('org')] : DB::table('services')->distinct()->pluck('organization_id')->all();
        $rows = [];
        foreach ($orgs as $org) {
            foreach ($services->list($org) as $s) {
                $rows[] = [$org, $s->node_id, $s->name, "{$s->template_id} v{$s->template_version}", $s->status,
                    implode(', ', array_map(fn ($e) => ($e->tls ? 'https://' : 'http://').$e->domain, $s->endpoints)), (string) $s->error];
            }
        }
        $this->table(['Team', 'Server', 'Name', 'Template', 'Status', 'Addresses', 'Error'], $rows);

        return self::SUCCESS;
    }
}
