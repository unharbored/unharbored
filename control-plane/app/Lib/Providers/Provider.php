<?php

declare(strict_types=1);

namespace App\Lib\Providers;

/**
 * A place servers come from. What a provider can supply is data, not a
 * provider name checked in code: the wizard, the API and the estimates read
 * supplies() and catalog().
 */
interface Provider
{
    /** 'digitalocean' | 'aws' | 'custom' */
    public function id(): string;

    public function label(): string;

    /** 'api' (we create the machine) or 'ssh' (we adopt one that exists). */
    public function method(): string;

    /**
     * What the provider supplies natively: 'servers', 'edge', 'object_storage',
     * 'private_network', 'database:pg', 'database:mysql', 'database:valkey'.
     *
     * @return list<string>
     */
    public function supplies(): array;

    public function catalog(): Catalog;

    /** The driver that creates and destroys machines. Api providers only. Region matters where an API is per region (AWS). */
    public function servers(string $organizationId, ?\App\Lib\Audit\Actor $actor = null, string $reason = '', string $region = ''): ServerDriver;

    /**
     * Ask the provider whether the stored credential works. Throws if not.
     *
     * @return array{Arn?: string}  what the credential belongs to, for display
     */
    public function verify(string $organizationId, ?\App\Lib\Audit\Actor $actor = null): array;
}
