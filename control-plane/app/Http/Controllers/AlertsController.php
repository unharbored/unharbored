<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Alerts\Alerts;
use App\Lib\Auth\Org;
use App\Lib\Operations\Present;
use App\Lib\Vault\Vault;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/** What needs attention, where alerts are sent, and when to raise one. */
final class AlertsController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('view');
        $org = Org::id();
        $row = fn ($a) => ['id' => $a->id, 'severity' => $a->severity, 'title' => $a->title, 'detail' => $a->detail, 'link' => $a->link,
            'opened' => Present::ago($a->opened_at), 'resolved' => $a->resolved_at !== null ? Present::ago($a->resolved_at) : null];

        return Inertia::render('Alerts', [
            'open' => fn () => DB::table('alerts')->where('organization_id', $org)->whereNull('resolved_at')->orderByDesc('id')->get()->map($row)->all(),
            'recent' => fn () => DB::table('alerts')->where('organization_id', $org)->whereNotNull('resolved_at')->orderByDesc('id')->limit(30)->get()->map($row)->all(),
            'webhookSet' => fn () => app(Vault::class)->has($org, Alerts::WEBHOOK_SECRET),
            'email' => fn () => DB::table('organizations')->where('id', $org)->value('alert_email'),
            'mailReady' => ! in_array(config('mail.default'), ['log', 'array', null], true),
            'rules' => fn () => Alerts::settings($org),
        ]);
    }

    /** The webhook URL is a credential (anyone with it can post): kept in the vault, never shown again. */
    public function saveWebhook(Request $request): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $url = trim((string) $request->input('url'));
        if ($url === '') {
            app(Vault::class)->forget(Org::id(), Alerts::WEBHOOK_SECRET);

            return back()->with('status', 'Webhook removed.');
        }
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! str_starts_with($url, 'https://')) {
            throw ValidationException::withMessages(['webhook' => 'An https:// URL, as Slack or Discord gives you for an incoming webhook.']);
        }
        app(Vault::class)->put(Org::id(), Alerts::WEBHOOK_SECRET, $url, ['host' => parse_url($url, PHP_URL_HOST)]);

        return back()->with('status', 'Webhook saved.');
    }

    public function saveEmail(Request $request): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $email = trim((string) $request->input('email'));
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages(['email' => 'That is not an email address.']);
        }
        DB::table('organizations')->where('id', Org::id())->update(['alert_email' => $email === '' ? null : $email]);

        return back()->with('status', $email === '' ? 'Email removed.' : 'Email saved.');
    }

    public function saveRules(Request $request): RedirectResponse
    {
        Gate::authorize('infrastructure');
        $data = $request->validate([
            'offline_minutes' => 'required|integer', 'disk_percent' => 'required|integer', 'cpu_percent' => 'required|integer',
            'cpu_minutes' => 'required|integer', 'certificate_days' => 'required|integer', 'failed_operations' => 'required|boolean',
        ]);
        try {
            Alerts::save(Org::id(), $data);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::withMessages(['rules' => $e->getMessage()]);
        }

        return back()->with('status', 'Thresholds saved. The next check, within a minute, uses them.');
    }

    public function test(): RedirectResponse
    {
        Gate::authorize('infrastructure');
        app(Alerts::class)->tell(Org::id(), 'resolved', 'Test alert from Unharbored', 'If you can read this, alerts reach you here.', '/alerts');

        return back()->with('status', 'Test sent to every channel that is set up.');
    }
}
