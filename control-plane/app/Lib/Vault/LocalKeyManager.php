<?php

declare(strict_types=1);

namespace App\Lib\Vault;

use App\Lib\Vault\Exceptions\MasterKeyUnavailable;
use App\Lib\Vault\Exceptions\UnwrapFailed;
use SensitiveParameter;

/**
 * A KeyManager backed by a master key in application configuration.
 *
 * This is for development and testing. In production the master key belongs
 * in a KMS, which is what makes the key a thing an attacker must call out to
 * rather than a thing they find in an environment variable alongside the
 * database they just read.
 *
 * It is written to be correct rather than to be a placeholder: the wrapping
 * is real authenticated encryption, so moving to a KMS is a change of
 * implementation and not a change of threat model for the data at rest.
 */
final class LocalKeyManager implements KeyManager
{
    /**
     * Version prefix on wrapped blobs, so the format can change later without
     * having to guess what an old row contains.
     */
    private const FORMAT = 'lkm1';

    /** @var non-empty-string */
    private readonly string $masterKey;

    public function __construct(
        #[SensitiveParameter] string $masterKey,
        private readonly string $masterKeyId = 'local',
    ) {
        if ($masterKey === '') {
            throw new MasterKeyUnavailable(
                'No vault master key is configured. Set VAULT_MASTER_KEY to a base64 32-byte value; '
                .'php artisan vault:generate-key will produce one.'
            );
        }

        $decoded = base64_decode($masterKey, true);

        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES) {
            throw new MasterKeyUnavailable(sprintf(
                'The vault master key must be base64 of exactly %d bytes.',
                SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES
            ));
        }

        $this->masterKey = $decoded;
    }

    public function wrap(#[SensitiveParameter] string $dataKey): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $dataKey,
            // The master key id is authenticated but not encrypted, so a blob
            // cannot be replayed against a different master key without the
            // tag failing.
            $this->masterKeyId,
            $nonce,
            $this->masterKey,
        );

        return self::FORMAT.'.'.base64_encode($nonce).'.'.base64_encode($ciphertext);
    }

    public function unwrap(#[SensitiveParameter] string $wrappedKey): string
    {
        $parts = explode('.', $wrappedKey);

        if (count($parts) !== 3 || $parts[0] !== self::FORMAT) {
            throw new UnwrapFailed('Wrapped key is not in a recognised format.');
        }

        $nonce = base64_decode($parts[1], true);
        $ciphertext = base64_decode($parts[2], true);

        if ($nonce === false || $ciphertext === false
            || strlen($nonce) !== SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES) {
            throw new UnwrapFailed('Wrapped key is malformed.');
        }

        $dataKey = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $ciphertext,
            $this->masterKeyId,
            $nonce,
            $this->masterKey,
        );

        if ($dataKey === false) {
            // Wrong master key, a corrupt row, or tampering. We cannot tell
            // which, and saying so would be a guess.
            throw new UnwrapFailed(
                'Could not unwrap the data key. The master key may have changed, '
                .'or the stored key may be corrupt.'
            );
        }

        return $dataKey;
    }

    public function masterKeyId(): string
    {
        return $this->masterKeyId;
    }

    /**
     * Generate a master key suitable for configuration.
     */
    public static function generateMasterKey(): string
    {
        return base64_encode(
            random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES)
        );
    }
}
