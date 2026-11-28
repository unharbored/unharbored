<?php

declare(strict_types=1);

namespace App\Lib\Sites;

use Cron\CronExpression;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Did it run?" for the Laravel scheduler and for scheduled jobs. A monitored
 * one pings a URL after each successful run; if the ping is late by more than
 * the grace, Alerts says so.
 */
final class Heartbeats
{
    /** Minutes a run may be late, or take, before it is called missed. */
    public const GRACE_MINUTES = 5;

    /** Starts monitoring the scheduler (null) or a job; a second call keeps the first token. */
    public static function enable(int $siteId, ?int $jobId): void
    {
        if (self::find($siteId, $jobId) !== null) {
            return;
        }
        $token = bin2hex(random_bytes(20));
        DB::table('heartbeats')->insert([
            'site_id' => $siteId, 'scheduled_job_id' => $jobId, 'token_hash' => hash('sha256', $token),
            'token' => Crypt::encryptString($token), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public static function disable(int $siteId, ?int $jobId): void
    {
        DB::table('heartbeats')->where('site_id', $siteId)->where('scheduled_job_id', $jobId)->delete();
    }

    public static function find(int $siteId, ?int $jobId): ?object
    {
        return DB::table('heartbeats')->where('site_id', $siteId)->where('scheduled_job_id', $jobId)->first();
    }

    /** The ping URL of each monitored one on a site, keyed by job id (0 for the scheduler). @return array<int, string> */
    public static function urls(int $siteId): array
    {
        $out = [];
        foreach (DB::table('heartbeats')->where('site_id', $siteId)->get() as $h) {
            $out[(int) $h->scheduled_job_id] = rtrim((string) config('app.url'), '/').'/heartbeat/'.Crypt::decryptString($h->token);
        }

        return $out;
    }

    /** Records a ping. False for a token nobody holds. */
    public static function ping(string $token): bool
    {
        return DB::table('heartbeats')->where('token_hash', hash('sha256', $token))->update(['last_ping_at' => now()]) > 0;
    }

    /**
     * What has missed its run in an organisation: it was due more than the
     * grace ago, and nothing has pinged since. Only what
     * should be running counts: a deployed application, with the scheduler on
     * or the job not paused.
     *
     * @return array<string, array{severity: string, title: string, detail: string, link: string}>
     */
    public static function overdue(string $organizationId): array
    {
        $now = Carbon::now('UTC')->toDateTimeImmutable();
        $out = [];
        $rows = DB::table('heartbeats as h')->join('sites as s', 's.id', '=', 'h.site_id')->leftJoin('scheduled_jobs as j', 'j.id', '=', 'h.scheduled_job_id')
            ->where('s.organization_id', $organizationId)->whereNull('s.archived_at')->whereNotNull('s.current_release')
            ->select('h.id', 'h.last_ping_at', 'h.created_at', 'h.scheduled_job_id', 's.id as site_id', 's.name as site', 's.scheduler', 'j.name as job', 'j.cron', 'j.paused')
            ->get();

        foreach ($rows as $h) {
            if ($h->scheduled_job_id === null ? ! (bool) $h->scheduler : (bool) $h->paused) {
                continue;
            }
            // The last run that was due at least the grace ago: if nothing has reported since, it was missed.
            try {
                $due = (new CronExpression($h->scheduled_job_id === null ? '* * * * *' : (string) $h->cron))
                    ->getPreviousRunDate($now->modify('-'.self::GRACE_MINUTES.' minutes'), 0, true, 'UTC');
            } catch (Throwable) {
                continue;
            }
            $heard = Carbon::parse($h->last_ping_at ?? $h->created_at);
            if ($heard->getTimestamp() >= $due->getTimestamp()) {
                continue;
            }
            $what = $h->scheduled_job_id === null ? 'The Laravel scheduler' : "The job {$h->job}";
            $out["heartbeat:{$h->id}"] = ['severity' => 'warning', 'title' => "{$what} of {$h->site} did not run",
                'detail' => 'It was due at '.$due->format('H:i').' UTC and has not reported since '.($h->last_ping_at === null ? 'monitoring began.' : $heard->utc()->format('D H:i').' UTC.'),
                'link' => "/site/settings?site={$h->site_id}&tab=scheduler"];
        }

        return $out;
    }
}
