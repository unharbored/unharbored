<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Cloud\AutoscalePools;
use App\Lib\Operations\OperationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Run every minute, for two things DigitalOcean will not do for us.
 *
 * It removes pool members on its own schedule and tells nobody: a member it
 * has removed comes off its site before the next deploy tries to reach it.
 *
 * And it scales on load only. A site that knows when its load comes can
 * keep more servers ready for those hours: when the minimum the schedule
 * wants differs from the one DigitalOcean has, the pool is resized.
 */
final class ReconcilePools extends Command
{
    protected $signature = 'sites:reconcile-pools';

    protected $description = 'Take servers an autoscale pool has removed off their sites, and apply scheduled minimums';

    public function handle(AutoscalePools $pools, OperationRunner $runner): int
    {
        $failed = false;
        foreach (DB::table('sites')->whereNull('archived_at')->whereNotNull('autoscale_pool_id')->get() as $site) {
            // Ours, not a provider's pool: the scaler watches its own servers.
            if (\App\Lib\Scaling\ServerScaler::manages($site)) {
                continue;
            }
            try {
                $this->schedule($site, $pools, $runner);
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$site->name}: {$e->getMessage()}");
            }
            try {
                $gone = $pools->departed($site);
                if ($gone !== [] && ! $this->busy($site->id, ['pool_leave', 'pool_remove'])) {
                    $id = $runner->queuePoolLeave($site->id, $gone, 'autoscale', 'digitalocean');
                    $this->line("{$site->name}: ".implode(', ', $gone)." leaving, operation {$id}");
                }
                foreach ($pools->retryStranded($site) as $node) {
                    $this->line("{$site->name}: {$node} joining again");
                }
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$site->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function schedule(object $site, AutoscalePools $pools, OperationRunner $runner): void
    {
        $attributes = $pools->of($site)->attributes;
        $want = AutoscalePools::minAt($attributes, now());
        if ($want === AutoscalePools::liveMin($attributes) || $this->busy($site->id, ['pool_create', 'pool_resize', 'pool_remove'])) {
            return;
        }
        $id = $runner->queuePoolResize($site->id, $want, (int) $attributes['max'], 'schedule', 'schedule', scheduled: true);
        $this->line("{$site->name}: at least {$want} servers, operation {$id}");
    }

    /** @param list<string> $kinds */
    private function busy(int $siteId, array $kinds): bool
    {
        return DB::table('operations')->where('site_id', $siteId)->whereIn('kind', $kinds)
            ->whereIn('status', ['queued', 'running', 'waiting'])->exists();
    }
}
