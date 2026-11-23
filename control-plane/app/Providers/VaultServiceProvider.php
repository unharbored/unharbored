<?php

declare(strict_types=1);

namespace App\Providers;

use App\Lib\Vault\KeyManager;
use App\Lib\Vault\LocalKeyManager;
use App\Lib\Vault\Vault;
use Illuminate\Support\ServiceProvider;

final class VaultServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(KeyManager::class, function ($app): KeyManager {
            return new LocalKeyManager(
                (string) config('vault.master_key'),
                (string) config('vault.master_key_id'),
            );
        });

        $this->app->singleton(Vault::class, function ($app): Vault {
            return new Vault($app->make(KeyManager::class));
        });
    }
}
