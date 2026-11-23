<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Loads an install's own code from the `custom/` folder, which is not in git,
 * so `git pull` never conflicts with it.
 *
 *   custom/Providers/*.php      service providers (namespace Custom\Providers), registered
 *   custom/routes/web.php       web routes, with the web middleware
 *   custom/routes/api.php       API routes, under /api, with the api middleware
 *   custom/migrations/          migrations
 *
 * Other classes in `custom/` use the `Custom\` namespace, which composer.json
 * autoloads from the same folder. A missing folder or file is skipped.
 * See docs/self-hosting.md, "Customising".
 */
final class CustomServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach (glob($this->path('Providers/*.php')) ?: [] as $file) {
            require_once $file;
            $class = 'Custom\\Providers\\'.basename($file, '.php');
            if (is_subclass_of($class, ServiceProvider::class)) {
                $this->app->register($class);
            }
        }
    }

    public function boot(): void
    {
        if (is_dir($this->path('migrations'))) {
            $this->loadMigrationsFrom($this->path('migrations'));
        }

        if ($this->app->routesAreCached()) {
            return;
        }
        if (is_file($this->path('routes/web.php'))) {
            Route::middleware('web')->group($this->path('routes/web.php'));
        }
        if (is_file($this->path('routes/api.php'))) {
            Route::middleware('api')->prefix('api')->group($this->path('routes/api.php'));
        }
    }

    private function path(string $relative): string
    {
        return rtrim((string) config('control_plane.custom_path', base_path('custom')), '/').'/'.$relative;
    }
}
