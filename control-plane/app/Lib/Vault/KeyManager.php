<?php

declare(strict_types=1);

namespace App\Lib\Vault;

/**
 * Wraps and unwraps organisation data keys.
 *
 * This is the boundary between us and whatever holds the master key. The
 * vault never sees the master key itself; it hands over a data key and gets
 * back an opaque wrapped blob.
 *
 * That indirection is the whole point. PLAN.md requires the master key to
 * live in a dedicated KMS rather than in application configuration, and the
 * only way to make that migration cheap later is to never let the rest of the
 * codebase know where the key is.
 */
interface KeyManager
{
    /**
     * Encrypt a data key for storage.
     *
     * @param  string  $dataKey  Raw key material, 32 bytes.
     * @return string Opaque wrapped key, safe to persist.
     */
    public function wrap(string $dataKey): string;

    /**
     * Decrypt a stored data key.
     *
     * @param  string  $wrappedKey  A blob previously returned by wrap().
     * @return string Raw key material, 32 bytes.
     *
     * @throws Exceptions\VaultException If the blob is corrupt, was produced
     *                                   by a different master key, or has
     *                                   been tampered with.
     */
    public function unwrap(string $wrappedKey): string;

    /**
     * Identifies the master key currently in use.
     *
     * Recorded alongside every wrapped key so a master key rotation can find
     * the rows that still need re-wrapping.
     */
    public function masterKeyId(): string;
}
