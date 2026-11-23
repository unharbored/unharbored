<?php

declare(strict_types=1);

namespace App\Lib\Providers;

interface ServerDriver
{
    /**
     * Create a machine; returns the provider's id. Idempotent on the spec's key.
     *
     * @throws Rejected   The provider said no; nothing was created.
     * @throws Uncertain  No usable answer; a machine may exist.
     */
    public function create(ServerSpec $spec): string;

    /** Null when the provider has no such machine. */
    public function find(string $externalId): ?ProviderServer;

    /** Idempotent: a machine already gone is success. */
    public function destroy(string $externalId): void;

    /**
     * Every machine carrying the tag, for the reconciler's orphan check.
     *
     * @return list<ProviderServer>
     */
    public function listTagged(string $tag): array;
}
