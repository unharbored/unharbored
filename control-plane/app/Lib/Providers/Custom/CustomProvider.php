<?php

declare(strict_types=1);

namespace App\Lib\Providers\Custom;

use App\Lib\Audit\Actor;
use App\Lib\Providers\Catalog;
use App\Lib\Providers\Provider;
use App\Lib\Providers\ServerDriver;
use LogicException;

/**
 * A machine the user provides. No cloud API: we log in once over SSH, install
 * the agent, and keep nothing. No catalog, no estimate, nothing to create or
 * destroy.
 */
final class CustomProvider implements Provider
{
    public function id(): string
    {
        return 'custom';
    }

    public function label(): string
    {
        return 'Your own server';
    }

    public function method(): string
    {
        return 'ssh';
    }

    public function supplies(): array
    {
        return [];
    }

    public function catalog(): Catalog
    {
        return new Catalog([], [], '', '');
    }

    public function servers(string $organizationId, ?Actor $actor = null, string $reason = '', string $region = ''): ServerDriver
    {
        throw new LogicException('Custom servers are machines you provide, so Unharbored cannot create or destroy them.');
    }

    public function verify(string $organizationId, ?Actor $actor = null): array
    {
        return [];
    }
}
