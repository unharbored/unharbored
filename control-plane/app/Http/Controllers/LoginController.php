<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Lib\Audit\Actor;
use App\Lib\Auth\Org;
use App\Lib\Audit\AuditLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Password sign-in. One account, rate limited, and an unknown email costs
 * the same time as a known one.
 *
 * Two-factor was here and is switched off for now; App\Lib\Auth\Totp and the
 * users table's two_factor columns are kept so it can come back.
 */
final class LoginController
{
    private const ATTEMPTS_PER_MINUTE = 5;

    /** A bcrypt hash of a random string nobody knows, for timing only. */
    private const TIMING_DUMMY = '$2y$12$X3tAOAlajPjcxxbcMOychu1R8NfBr3GjQYyKr.NwwJElt0/6TozSG';

    public function __construct(private readonly AuditLog $audit) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Auth/Login', ['host' => $request->getHost()]);
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email|max:255', 'password' => 'required|string|max:1000']);
        $key = 'login:'.strtolower($data['email']).'|'.$request->ip();

        if (RateLimiter::tooManyAttempts($key, self::ATTEMPTS_PER_MINUTE)) {
            return back()->withErrors(['email' => 'Too many attempts. Try again in a minute.'])->onlyInput('email');
        }

        $user = User::where('email', $data['email'])->first();

        // Hash a dummy when the user is unknown, so timing does not reveal
        // which emails exist.
        $valid = $user !== null
            ? Hash::check($data['password'], $user->password)
            : Hash::check($data['password'], self::TIMING_DUMMY) && false;

        if (! $valid) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['email' => 'Those details are not right.'])->onlyInput('email');
        }

        RateLimiter::clear($key);
        Auth::login($user);
        $request->session()->regenerate();

        $this->audit->record(Org::id(), Actor::user((string) $user->id, $user->email), 'auth.signed_in', ipAddress: $request->ip(), userAgent: (string) $request->userAgent());

        return redirect()->intended(route('sites'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
