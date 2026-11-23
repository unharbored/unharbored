<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Audit\AuditLog;
use App\Lib\Auth\Invitations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

/** Accepting an invitation: choose a name and password, and you are in. */
final class InviteController
{
    public function __construct(private readonly Invitations $invitations, private readonly AuditLog $audit) {}

    public function show(Request $request, string $token): Response
    {
        return $this->page($request, $token, $this->invitations->find($token));
    }

    /** @param  object|null  $invitation  the pending invitation, or null when the link is not valid */
    private function page(Request $request, string $token, ?object $invitation): Response
    {
        return Inertia::render('Auth/Invite', [
            'invitation' => $invitation === null ? null : ['email' => $invitation->email, 'invited_by' => $invitation->invited_by],
            'token' => $token,
            'host' => $request->getHost(),
        ]);
    }

    public function accept(Request $request, string $token): RedirectResponse|Response
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'password' => 'required|string|min:12|max:1000|confirmed',
        ]);

        $user = $this->invitations->accept($token, $data['name'], $data['password']);
        if ($user === null) {
            return $this->page($request, $token, null);
        }

        Auth::login($user);
        $request->session()->regenerate();
        $this->audit->record(Org::id(), Actor::user((string) $user->id, $user->email), 'user.joined', ipAddress: $request->ip(), userAgent: (string) $request->userAgent());

        return redirect()->route('sites');
    }
}
