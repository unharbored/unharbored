<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Cloud\BaseImages;
use App\Lib\Operations\OperationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Run daily. Rebuilds the base image of any region with an autoscale pool
 * once it is a month old, so new members do not boot a month of security
 * updates behind. Regions without a pool get no image: nothing would use it.
 */
final class RefreshBaseImages extends Command
{
    protected $signature = 'images:refresh';

    protected $description = 'Rebuild stale base images for regions with autoscale pools';

    public function handle(BaseImages $images, OperationRunner $runner): int
    {
        $wanted = [];
        foreach (DB::table('resources')->where('type', 'autoscale_pool')->where('state', 'active')->get(['organization_id', 'attributes']) as $pool) {
            $region = json_decode((string) $pool->attributes, true)['region'] ?? null;
            if ($region !== null) {
                $wanted["{$pool->organization_id}|{$region}"] = [$pool->organization_id, $region];
            }
        }
        foreach ($wanted as [$org, $region]) {
            $building = DB::table('operations')->where('organization_id', $org)->where('kind', 'base_image')
                ->whereIn('status', ['queued', 'running', 'waiting'])->exists();
            if ($building || ! $images->stale($org, $region)) {
                continue;
            }
            $id = $runner->queueBaseImage($org, $region, 'schedule', 'schedule');
            $this->line("{$org} {$region}: building a base image, operation {$id}");
        }

        return self::SUCCESS;
    }
}
