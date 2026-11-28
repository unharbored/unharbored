<?php

namespace App\Providers;

use App\Lib\Auth\Roles;
use App\Lib\Saas\SaasServiceProvider;
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
        // The private SaaS layer, when this copy has it (the public export does not).
        if (class_exists(SaasServiceProvider::class)) {
            $this->app->register(SaasServiceProvider::class);
        }
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Roles::register();

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
