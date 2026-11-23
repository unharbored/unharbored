<?php

declare(strict_types=1);

namespace Tests\Unit\Vault;

use App\Lib\Vault\Exceptions\MasterKeyUnavailable;
use App\Lib\Vault\Exceptions\UnwrapFailed;
use App\Lib\Vault\LocalKeyManager;
use PHPUnit\Framework\TestCase;

final class LocalKeyManagerTest extends TestCase
{
    private function manager(string $id = 'local'): LocalKeyManager
    {
        return new LocalKeyManager(LocalKeyManager::generateMasterKey(), $id);
    }

    public function test_wrap_and_unwrap_round_trip(): void
    {
        $manager = $this->manager();
        $dataKey = random_bytes(32);

        $wrapped = $manager->wrap($dataKey);

        $this->assertNotSame($dataKey, $wrapped);
        $this->assertSame($dataKey, $manager->unwrap($wrapped));
    }

    /**
     * The wrapped blob must not contain the key it wraps. Obvious, and
     * exactly the kind of thing that silently breaks when someone swaps the
     * encryption for an encoding.
     */
    public function test_wrapped_blob_does_not_contain_the_key(): void
    {
        $manager = $this->manager();
        $dataKey = random_bytes(32);

        $wrapped = $manager->wrap($dataKey);

        $this->assertStringNotContainsString($dataKey, $wrapped);
        $this->assertStringNotContainsString(base64_encode($dataKey), $wrapped);
    }

    public function test_wrapping_is_non_deterministic(): void
    {
        $manager = $this->manager();
        $dataKey = random_bytes(32);

        // A fresh nonce per call means the same key never produces the same
        // blob, so an observer cannot tell that two organisations share a key.
        $this->assertNotSame($manager->wrap($dataKey), $manager->wrap($dataKey));
    }

    public function test_a_different_master_key_cannot_unwrap(): void
    {
        $dataKey = random_bytes(32);
        $wrapped = $this->manager()->wrap($dataKey);

        $this->expectException(UnwrapFailed::class);
        $this->manager()->unwrap($wrapped);
    }

    /**
     * The master key id is authenticated but not encrypted, so a blob cannot
     * be replayed against a different master key identity.
     */
    public function test_master_key_id_is_authenticated(): void
    {
        $masterKey = LocalKeyManager::generateMasterKey();
        $dataKey = random_bytes(32);

        $wrapped = (new LocalKeyManager($masterKey, 'kms-key-a'))->wrap($dataKey);

        $this->expectException(UnwrapFailed::class);
        (new LocalKeyManager($masterKey, 'kms-key-b'))->unwrap($wrapped);
    }

    public function test_tampered_blob_is_rejected(): void
    {
        $manager = $this->manager();
        $wrapped = $manager->wrap(random_bytes(32));

        // Flip a bit in the ciphertext segment.
        $parts = explode('.', $wrapped);
        $raw = base64_decode($parts[2], true);
        $raw[0] = chr(ord($raw[0]) ^ 0xFF);
        $parts[2] = base64_encode($raw);

        $this->expectException(UnwrapFailed::class);
        $manager->unwrap(implode('.', $parts));
    }

    public function test_malformed_blobs_are_rejected(): void
    {
        $manager = $this->manager();

        foreach (['', 'nonsense', 'lkm1.only-two', 'xxx1.aaa.bbb', 'lkm1.!!!.!!!'] as $blob) {
            try {
                $manager->unwrap($blob);
                $this->fail(sprintf('Expected %s to be rejected.', var_export($blob, true)));
            } catch (UnwrapFailed) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /**
     * A missing or wrong-sized master key is a configuration failure that
     * must stop the process, not degrade into storing anything weakly.
     */
    public function test_bad_master_keys_are_refused_at_construction(): void
    {
        foreach (['', 'not base64', base64_encode(random_bytes(16)), base64_encode(random_bytes(64))] as $key) {
            try {
                new LocalKeyManager($key);
                $this->fail(sprintf('Expected %s to be refused.', var_export(substr($key, 0, 12), true)));
            } catch (MasterKeyUnavailable) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_generated_master_key_is_the_right_size(): void
    {
        $decoded = base64_decode(LocalKeyManager::generateMasterKey(), true);

        $this->assertIsString($decoded);
        $this->assertSame(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES, strlen($decoded));
    }

    public function test_generated_master_keys_are_unique(): void
    {
        $keys = [];
        for ($i = 0; $i < 100; $i++) {
            $keys[] = LocalKeyManager::generateMasterKey();
        }

        $this->assertCount(100, array_unique($keys));
    }
}
