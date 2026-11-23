<?php

declare(strict_types=1);

namespace App\Lib\Providers;

/**
 * A provider that can read its current regions and server sizes with prices from its own API, so the lists
 * are not a copy of what it sold when we wrote them. The built-in list stays as the fallback for when the
 * provider cannot be asked (see CatalogCache).
 */
interface LiveCatalog
{
    /**
     * @throws \Throwable when the provider cannot be asked or answers with nothing usable
     */
    public function fetchCatalog(string $organizationId): Catalog;
}
