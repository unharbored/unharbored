<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        \App\Lib\Auth\Roles::register();

        // Enrollment is the only unauthenticated route, and the only one that
        // does public key cryptography on request. The limit bounds the cost
        // of someone pointing a load generator at it, not token guessing:
        // 256 bits of entropy already handles that.
        RateLimiter::for('enrollment', function (Request $request): Limit {
            return Limit::perMinute((int) config('control_plane.enrollment_attempts_per_minute'))
                ->by($request->ip() ?? 'unknown');
        });
    }
}
