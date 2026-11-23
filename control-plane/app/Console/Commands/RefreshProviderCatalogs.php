<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Lib\Providers\CatalogCache;
use App\Lib\Providers\LiveCatalog;
use App\Lib\Providers\Providers;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Run daily. Reads each connected provider's current regions and server sizes with prices, so the lists in the
 * console and the API follow what the provider sells. Catalogs are the same for every team, so one team's
 * credential is enough for each provider; a provider that cannot be asked keeps the last list it gave.
 */
final class RefreshProviderCatalogs extends Command
{
    protected $signature = 'providers:refresh-catalogs';

    protected $description = "Refresh each connected provider's regions and server sizes";

    public function handle(Providers $providers, CatalogCache $cache): int
    {
        foreach ($providers->all() as $provider) {
            if (! $provider instanceof LiveCatalog) {
                continue;
            }
            $org = DB::table('cloud_credentials')->where('provider', $provider->id())->whereNull('revoked_at')->orderBy('id')->value('organization_id');
            if ($org === null) {
                continue;
            }
            $this->line($provider->id().': '.($cache->refresh($provider, (string) $org) ? 'refreshed' : 'could not be read, keeping the last list'));
        }

        return self::SUCCESS;
    }
}
