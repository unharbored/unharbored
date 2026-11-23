<?php

declare(strict_types=1);

namespace App\Lib\Providers;

/**
 * For a provider with a LiveCatalog: catalog() is the last list fetched from the provider, else the built-in
 * one. It never makes a request itself, so it is cheap wherever it is called.
 */
trait HasCachedCatalog
{
    abstract protected function fallbackCatalog(): Catalog;

    public function catalog(): Catalog
    {
        return app(CatalogCache::class)->get($this->id()) ?? $this->fallbackCatalog();
    }

    /**
     * A fetched list as a Catalog: sizes cheapest first (the first is the default size), regions as given, and
     * the built-in list's default region and image kept unless the region has gone.
     *
     * @param  array<string, string>  $regions
     * @param  array<string, array{label: string, vcpu: int, memory_gb: int|float, price: int|float}>  $sizes
     */
    protected function liveCatalog(array $regions, array $sizes): Catalog
    {
        if ($regions === [] || $sizes === []) {
            throw new \RuntimeException($this->label().' returned no regions or sizes.');
        }
        uasort($sizes, fn ($a, $b) => [$a['price'], $a['memory_gb']] <=> [$b['price'], $b['memory_gb']]);
        $fallback = $this->fallbackCatalog();

        return new Catalog($regions, $sizes, array_key_exists($fallback->defaultRegion, $regions) ? $fallback->defaultRegion : (string) array_key_first($regions), $fallback->defaultImage);
    }

    /** "2 vCPU · 4 GB". */
    protected function sizeLabel(int $vcpu, int|float $memoryGb): string
    {
        return "{$vcpu} vCPU · ".rtrim(rtrim(number_format($memoryGb, 1), '0'), '.').' GB';
    }
}
