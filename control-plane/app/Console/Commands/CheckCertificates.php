<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Operations\OperationRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Daily: re-reads when each Let's Encrypt certificate ends, so a certificate
 * certbot renewed on its own clears the expiry alert. One certificate_check
 * per site, each started a few seconds after the last so the nodes are not
 * all asked at once. Uploaded certificates only change when someone uploads
 * one, which already reads the date, so they are left out.
 */
final class CheckCertificates extends Command
{
    protected $signature = 'certificates:check';

    protected $description = 'Re-read the expiry of every Let\'s Encrypt certificate, one queued check per site';

    private const SPACING_SECONDS = 5;

    public function handle(OperationRunner $runner): int
    {
        $failed = false;
        $queued = 0;
        foreach (DB::table('sites')->where('tls', true)->where('cert_source', 'letsencrypt')->orderBy('id')->pluck('id') as $id) {
            try {
                $runner->queueScheduledCertificateCheck((int) $id, $queued * self::SPACING_SECONDS);
                $queued++;
            } catch (\InvalidArgumentException $e) {
                $this->line("site {$id}: skipped, {$e->getMessage()}");
            } catch (Throwable $e) {
                $failed = true;
                $this->error("site {$id}: {$e->getMessage()}");
            }
        }
        $this->info("{$queued} check(s) queued.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
