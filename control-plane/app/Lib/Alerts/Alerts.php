<?php

declare(strict_types=1);

namespace App\Lib\Alerts;

use App\Lib\Gateway\GatewayClient;
use App\Lib\Vault\Vault;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * What needs a person: a server offline, a disk nearly full, a CPU pinned,
 * a scheduled job that did not run, an operation that failed. Checked once a minute; each alert is told to a
 * webhook (Slack, Discord, anything that takes JSON) and by email, each if
 * set up, once when it opens and once when it clears.
 */
final class Alerts
{
    public const WEBHOOK_SECRET = 'alerts.webhook_url';

    /** Thresholds unless the organisation sets its own; 0 turns a rule off. */
    public const DEFAULTS = ['offline_minutes' => 3, 'disk_percent' => 90, 'cpu_percent' => 90, 'cpu_minutes' => 10, 'certificate_days' => 14, 'failed_operations' => true];

    /** What each threshold may be set to, 0 being off. */
    public const LIMITS = ['offline_minutes' => [0, 120], 'disk_percent' => [0, 99], 'cpu_percent' => [0, 100], 'cpu_minutes' => [2, 120], 'certificate_days' => [0, 90]];

    /** @return array{offline_minutes: int, disk_percent: int, cpu_percent: int, cpu_minutes: int, certificate_days: int, failed_operations: bool} */
    public static function settings(string $organizationId): array
    {
        $saved = json_decode((string) DB::table('organizations')->where('id', $organizationId)->value('alert_settings'), true) ?: [];

        return array_replace(self::DEFAULTS, array_intersect_key($saved, self::DEFAULTS));
    }

    /** @param  array<string, mixed>  $settings */
    public static function save(string $organizationId, array $settings): void
    {
        $clean = ['failed_operations' => (bool) ($settings['failed_operations'] ?? true)];
        foreach (self::LIMITS as $key => [$min, $max]) {
            $value = (int) ($settings[$key] ?? self::DEFAULTS[$key]);
            if ($value !== 0 && ($value < $min || $value > $max) || ($key === 'cpu_minutes' && $value === 0)) {
                throw new \InvalidArgumentException(str_replace('_', ' ', $key)." must be between {$min} and {$max}".($key !== 'cpu_minutes' ? ', or 0 for off' : '').'.');
            }
            $clean[$key] = $value;
        }
        DB::table('organizations')->where('id', $organizationId)->update(['alert_settings' => json_encode($clean)]);
    }

    public function __construct(
        private readonly GatewayClient $gateway,
        private readonly Vault $vault,
        private readonly HttpFactory $http,
    ) {}

    /**
     * One pass for one organisation.
     *
     * @return list<string> what opened or cleared
     */
    public function check(string $organizationId): array
    {
        $holding = $this->conditions($organizationId);
        $changes = [];

        $open = DB::table('alerts')->where('organization_id', $organizationId)->whereNull('resolved_at')->get()->keyBy('key');
        foreach ($holding as $key => $alert) {
            if (! $open->has($key)) {
                $this->open($organizationId, $key, $alert, resolved: false);
                $changes[] = "opened: {$alert['title']}";
            }
        }
        foreach ($open as $key => $row) {
            if (! isset($holding[$key])) {
                DB::table('alerts')->where('id', $row->id)->update(['resolved_at' => now()]);
                $this->tell($organizationId, 'resolved', "Cleared: {$row->title}", null, $row->link);
                $changes[] = "cleared: {$row->title}";
            }
        }

        foreach ($this->failedOperations($organizationId, self::settings($organizationId)['failed_operations']) as $key => $alert) {
            $this->open($organizationId, $key, $alert, resolved: true);
            $changes[] = "failed: {$alert['title']}";
        }

        return $changes;
    }

    /**
     * Conditions holding now, keyed so the same one is not opened twice.
     *
     * @return array<string, array{severity: string, title: string, detail: string, link: string}>
     */
    private function conditions(string $organizationId): array
    {
        $rules = self::settings($organizationId);
        $out = [];
        $nodes = DB::table('nodes')->where('organization_id', $organizationId)->get();
        try {
            $connected = collect($this->gateway->connectedNodes())->pluck('node_id')->flip();
        } catch (Throwable) {
            // The gateway itself is down: one alert, not one per server.
            return ['gateway' => ['severity' => 'critical', 'title' => 'The gateway is unreachable', 'detail' => 'No server can be reached or deployed to until it is back.', 'link' => '/servers']];
        }

        foreach ($nodes as $node) {
            $name = strstr($node->node_id, '.', true) ?: $node->node_id;
            // Timed from when this check first found it gone, so a restart
            // (an agent upgrade, a gateway deploy) is not an outage.
            $since = "alerts:offline-since:{$node->node_id}";
            if ($connected->has($node->node_id)) {
                Cache::forget($since);
            } else {
                $from = \Illuminate\Support\Carbon::parse(Cache::rememberForever($since, fn () => now()->toIso8601String()));
                if ($rules['offline_minutes'] > 0 && $from->lte(now()->subMinutes($rules['offline_minutes']))) {
                    $out["offline:{$node->node_id}"] = ['severity' => 'critical', 'title' => "{$name} is offline",
                        'detail' => 'Its agent has been disconnected since '.$from->utc()->format('H:i').' UTC.', 'link' => '/servers'];
                }

                continue;
            }

            $latest = DB::table('node_metrics')->where('node_id', $node->node_id)->where('collected_at', '>=', now()->subMinutes(5))->orderByDesc('collected_at')->first();
            if ($latest !== null && $latest->disk_total > 0) {
                $used = (int) round(100 * ($latest->disk_total - $latest->disk_free) / $latest->disk_total);
                if ($rules['disk_percent'] > 0 && $used >= $rules['disk_percent']) {
                    $out["disk:{$node->node_id}"] = ['severity' => 'warning', 'title' => "{$name}'s disk is {$used}% full",
                        'detail' => number_format($latest->disk_free / 1073741824, 1).' GB left.', 'link' => '/servers'];
                }
            }
            $recent = DB::table('node_metrics')->where('node_id', $node->node_id)->where('collected_at', '>=', now()->subMinutes($rules['cpu_minutes']));
            if ($rules['cpu_percent'] > 0 && (clone $recent)->count() >= max(1, $rules['cpu_minutes'] - 2) && (float) $recent->avg('cpu') >= $rules['cpu_percent']) {
                $out["cpu:{$node->node_id}"] = ['severity' => 'warning', 'title' => "{$name}'s CPU has been above {$rules['cpu_percent']}% for {$rules['cpu_minutes']} minutes",
                    'detail' => 'Averaging '.round((float) $recent->avg('cpu')).'%.', 'link' => '/servers'];
            }
        }

        // An autoscaler that stopped itself is the most expensive thing to leave unsaid: it was about to make a server again and again.
        foreach (DB::table('resources')->where('organization_id', $organizationId)->where('type', 'autoscale_pool')->where('state', 'active')->get(['id', 'attributes']) as $pool) {
            $a = json_decode((string) $pool->attributes, true) ?: [];
            if (isset($a['paused_at'])) {
                $site = DB::table('sites')->where('autoscale_pool_id', $pool->id)->first(['id', 'name']);
                $out["autoscaler:{$pool->id}"] = ['severity' => 'critical', 'title' => 'Autoscaling'.($site ? " {$site->name}" : '').' has stopped adding servers',
                    'detail' => (string) ($a['paused_because'] ?? ''), 'link' => $site ? "/site/settings?site={$site->id}&tab=servers" : '/sites'];
            }
        }

        // A certificate that ends inside the window, or already has: certbot should have renewed it, so something is wrong.
        if ($rules['certificate_days'] > 0) {
            $limit = now()->addDays($rules['certificate_days']);
            foreach (DB::table('sites')->where('organization_id', $organizationId)->where('tls', true)->whereNotNull('cert_expires_at')->where('cert_expires_at', '<=', $limit)->get(['id', 'name', 'cert_expires_at']) as $site) {
                $status = \App\Lib\Sites\Sites::certificateStatus($site->cert_expires_at);
                $out["certificate:{$site->id}"] = ['severity' => $status['days'] < 7 ? 'critical' : 'warning',
                    'title' => $status['days'] < 0 ? "{$site->name}'s certificate has expired" : "{$site->name}'s certificate expires soon",
                    'detail' => $status['label'].'.', 'link' => "/site?site={$site->id}"];
            }
        }

        return $out + \App\Lib\Sites\Heartbeats::overdue($organizationId) + app(\App\Lib\Cloud\ProviderKeys::class)->alerts($organizationId);
    }

    /**
     * Operations that failed since the last look. The first look starts
     * from now rather than reporting every failure in history.
     *
     * @return array<string, array{severity: string, title: string, detail: string, link: string}>
     */
    private function failedOperations(string $organizationId, bool $tell): array
    {
        $seen = (int) DB::table('organizations')->where('id', $organizationId)->value('alerts_seen_operation');
        $latest = (int) DB::table('operations')->where('organization_id', $organizationId)->max('id');
        if ($seen === 0) {
            DB::table('organizations')->where('id', $organizationId)->update(['alerts_seen_operation' => $latest]);

            return [];
        }
        $out = [];
        $finished = DB::table('operations')->where('organization_id', $organizationId)->where('id', '>', $seen)->orderBy('id')->get(['id', 'title', 'status', 'error', 'updated_at']);
        $through = $seen;
        foreach ($finished as $op) {
            // Not finished: look again next time, from here. But one that has not moved for an hour is stuck, not slow
            // (an agent that died mid-command), and must not hide every failure after it.
            if (in_array($op->status, ['queued', 'running', 'waiting'], true) && Carbon::parse($op->updated_at)->gt(now()->subHour())) {
                break;
            }
            if (in_array($op->status, ['queued', 'running', 'waiting'], true)) {
                $through = $op->id;

                continue;
            }
            $through = $op->id;
            if ($tell && $op->status === 'failed') {
                $out["operation:{$op->id}"] = ['severity' => 'critical', 'title' => "Failed: {$op->title}",
                    'detail' => mb_substr((string) $op->error, 0, 500), 'link' => "/operation?operation={$op->id}"];
            }
        }
        DB::table('organizations')->where('id', $organizationId)->update(['alerts_seen_operation' => $through]);

        return $out;
    }

    /** @param  array{severity: string, title: string, detail: string, link: string}  $alert */
    private function open(string $organizationId, string $key, array $alert, bool $resolved): void
    {
        DB::table('alerts')->insert([
            'organization_id' => $organizationId, 'key' => $key, 'severity' => $alert['severity'], 'title' => $alert['title'],
            'detail' => $alert['detail'], 'link' => $alert['link'], 'opened_at' => now(), 'resolved_at' => $resolved ? now() : null,
        ]);
        $this->tell($organizationId, $alert['severity'], $alert['title'], $alert['detail'], $alert['link']);
    }

    /**
     * To every channel set up. A channel that fails is logged and skipped:
     * an alert that cannot be sent still shows in the console.
     */
    public function tell(string $organizationId, string $severity, string $title, ?string $detail, ?string $link): void
    {
        $url = $link !== null ? rtrim((string) config('app.url'), '/').$link : null;
        $text = ($severity === 'resolved' ? '✓ ' : ($severity === 'critical' ? '✗ ' : '! '))."{$title}".($detail ? "\n{$detail}" : '').($url ? "\n{$url}" : '');

        $webhook = $this->vault->get($organizationId, self::WEBHOOK_SECRET);
        if ($webhook !== null) {
            try {
                // text for Slack and most others, content for Discord.
                $this->http->timeout(10)->post($webhook, ['text' => $text, 'content' => $text])->throw();
            } catch (Throwable $e) {
                Log::warning('Alert webhook failed', ['error' => $e->getMessage()]);
            }
        }

        $email = DB::table('organizations')->where('id', $organizationId)->value('alert_email');
        if ($email !== null && ! in_array(config('mail.default'), ['log', 'array', null], true)) {
            try {
                Mail::raw($text, fn ($m) => $m->to($email)->subject('[Unharbored] '.$title));
            } catch (Throwable $e) {
                Log::warning('Alert email failed', ['error' => $e->getMessage()]);
            }
        }
    }
}
