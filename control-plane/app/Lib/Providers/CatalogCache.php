<?php

declare(strict_types=1);

namespace App\Lib\Providers;

use Illuminate\Support\Facades\Cache;

/**
 * The regions and sizes each provider currently offers, read from its API and kept for a day. A copy that
 * outlives it by a month stands in when the provider cannot be asked, and below that is the built-in list, so
 * a provider being down never empties a form. Catalogs are the same for every team, so one copy serves all.
 */
final class CatalogCache
{
    private const FRESH = 86400;

    private const STALE = 2592000;

    /** After a failed read, how long before asking again, so a provider that is down does not slow every page. */
    private const BACKOFF = 600;

    /** The newest list we hold, fresh or stale; null when none was ever fetched. */
    public function get(string $providerId): ?Catalog
    {
        $held = Cache::get("provider-catalog:fresh:{$providerId}") ?? Cache::get("provider-catalog:stale:{$providerId}");

        return is_array($held) ? new Catalog($held['regions'], $held['sizes'], $held['region'], $held['image']) : null;
    }

    /** Fetches the list if the fresh copy has run out (and the last try was not just now). True when a new one was kept. */
    public function ensure(Provider $provider, string $organizationId): bool
    {
        if (! $provider instanceof LiveCatalog || Cache::has("provider-catalog:fresh:{$provider->id()}") || Cache::has("provider-catalog:failed:{$provider->id()}")) {
            return false;
        }

        return $this->refresh($provider, $organizationId);
    }

    /** Fetches the list now. False, with the old copy left alone, when the provider cannot be asked. */
    public function refresh(Provider $provider, string $organizationId): bool
    {
        if (! $provider instanceof LiveCatalog) {
            return false;
        }
        try {
            $catalog = $provider->fetchCatalog($organizationId);
        } catch (\Throwable) {
            Cache::put("provider-catalog:failed:{$provider->id()}", true, self::BACKOFF);

            return false;
        }
        $held = ['regions' => $catalog->regions, 'sizes' => $catalog->sizes, 'region' => $catalog->defaultRegion, 'image' => $catalog->defaultImage];
        Cache::put("provider-catalog:fresh:{$provider->id()}", $held, self::FRESH);
        Cache::put("provider-catalog:stale:{$provider->id()}", $held, self::STALE);
        Cache::forget("provider-catalog:failed:{$provider->id()}");

        return true;
    }
}
