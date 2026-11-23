<?php

declare(strict_types=1);

namespace App\Lib\Providers;

/** A machine as the provider reports it. State: pending, running, failed or gone. */
final readonly class ProviderServer
{
    public function __construct(
        public string $id,
        public string $state,
        public ?string $ipv4 = null,
        public ?string $privateIpv4 = null,
        public string $name = '',
        public ?string $ipv6 = null,
    ) {}

    /**
     * The address a server answers the public on, from its recorded attributes: its IPv4, else its IPv6. A server
     * with only IPv6 (an Incus container on bare metal) is reached at that.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function publicAddress(array $attributes): ?string
    {
        return ($attributes['ipv4'] ?? null) ?: (($attributes['ipv6'] ?? null) ?: null);
    }
}
