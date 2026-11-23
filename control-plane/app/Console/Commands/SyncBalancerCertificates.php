<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Cloud\LoadBalancers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * certbot renews each certificate on the site's primary server; this puts the
 * renewed one on the site's load balancer. Run daily: certificates renew 30
 * days before expiry, so a few missed runs cost nothing.
 */
final class SyncBalancerCertificates extends Command
{
    protected $signature = 'sites:sync-balancer-certificates';

    protected $description = 'Upload renewed certificates to the load balancers in front of sites';

    public function handle(LoadBalancers $balancers): int
    {
        $failed = false;
        foreach (DB::table('sites')->whereNotNull('load_balancer_id')->where('tls', true)->get() as $site) {
            try {
                $this->line("{$site->name}: ".$balancers->syncCertificate($site));
            } catch (Throwable $e) {
                $failed = true;
                $this->error("{$site->name}: {$e->getMessage()}");
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
