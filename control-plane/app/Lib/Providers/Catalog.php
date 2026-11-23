<?php

declare(strict_types=1);

namespace App\Lib\Providers;

/**
 * Regions and four size tiers with an approximate monthly price in dollars.
 * Prices are estimates and are labelled so wherever they are shown.
 */
final readonly class Catalog
{
    /**
     * @param  array<string, string>  $regions  slug => label
     * @param  array<string, array{label: string, vcpu: int, memory_gb: int|float, price: int|float}>  $sizes  slug => spec
     */
    public function __construct(public array $regions, public array $sizes, public string $defaultRegion, public string $defaultImage) {}

    public function hasRegion(string $slug): bool
    {
        return isset($this->regions[$slug]);
    }

    public function hasSize(string $slug): bool
    {
        return isset($this->sizes[$slug]);
    }

    /**
     * How a size reads in a list: its label and approximate monthly price, or the label alone when it costs nothing
     * (a server on hardware the team already pays for).
     *
     * @param  array{label: string, price: int|float}  $size
     */
    public static function sizeLabel(array $size): string
    {
        return $size['price'] > 0 ? "{$size['label']} · ~\${$size['price']}/mo" : $size['label'];
    }
}
