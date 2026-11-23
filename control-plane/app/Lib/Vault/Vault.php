<?php

declare(strict_types=1);

namespace App\Lib\Vault;

use App\Lib\Vault\Exceptions\DecryptionFailed;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;
use Throwable;

/**
 * Stores and retrieves organisation secrets under envelope encryption.
 *
 * The highest-value asset in this system is a customer DigitalOcean OAuth
 * grant: it can create, destroy and read their entire infrastructure, and a
 * compromise is not recoverable by us. Everything here exists to make a
 * database leak insufficient on its own.
 *
 * Three layers:
 *
 *  1. Each organisation has its own data key. One tenant's ciphertext is
 *     undecryptable with another tenant's key, so a bug that crosses an
 *     organisation boundary fails closed instead of leaking.
 *  2. Data keys are stored wrapped by a master key the application does not
 *     hold directly. See KeyManager.
 *  3. Every ciphertext is bound to its organisation, name and key version
 *     through the AEAD associated data. A row moved or renamed in the
 *     database fails to decrypt rather than decrypting into the wrong place.
 */
final class Vault
{
    public function __construct(
        private readonly KeyManager $keyManager,
        private readonly ?ConnectionInterface $connection = null,
    ) {}

    private function db(): ConnectionInterface
    {
        return $this->connection ?? DB::connection();
    }

    /**
     * Store a secret, replacing any existing value under the same name.
     *
     * @param  array<string, mixed>  $metadata  Never encrypted. Must not
     *                                          contain anything sensitive.
     */
    public function put(
        string $organizationId,
        string $name,
        #[SensitiveParameter] string $plaintext,
        array $metadata = [],
    ): void {
        $key = $this->primaryDataKey($organizationId);
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);

        try {
            $ciphertext = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
                $plaintext,
                $this->associatedData($organizationId, $name, $key['version']),
                $nonce,
                $key['material'],
            );
        } finally {
            sodium_memzero($key['material']);
        }

        $existing = $this->db()->table('secrets')
            ->where('organization_id', $organizationId)
            ->where('name', $name)
            ->first();

        $row = [
            'organization_id' => $organizationId,
            'name' => $name,
            'key_version' => $key['version'],
            'nonce' => $nonce,
            'ciphertext' => $ciphertext,
            'metadata' => $metadata === [] ? null : json_encode($metadata, JSON_THROW_ON_ERROR),
            'updated_at' => now(),
        ];

        if ($existing !== null) {
            $row['rotated_at'] = now();
            $this->db()->table('secrets')->where('id', $existing->id)->update($row);

            return;
        }

        $row['created_at'] = now();
        $this->db()->table('secrets')->insert($row);
    }

    /**
     * Retrieve a secret, or null if it does not exist.
     *
     * @throws DecryptionFailed If the stored value cannot be recovered.
     */
    public function get(string $organizationId, string $name): ?string
    {
        $row = $this->db()->table('secrets')
            ->where('organization_id', $organizationId)
            ->where('name', $name)
            ->first();

        if ($row === null) {
            return null;
        }

        $key = $this->dataKey($organizationId, (int) $row->key_version);

        try {
            $plaintext = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
                $this->asBinary($row->ciphertext),
                $this->associatedData($organizationId, $name, (int) $row->key_version),
                $this->asBinary($row->nonce),
                $key['material'],
            );
        } finally {
            sodium_memzero($key['material']);
        }

        if ($plaintext === false) {
            // Either the ciphertext is corrupt or its context no longer
            // matches. The latter means a row was moved between
            // organisations or renamed, which is the failure mode this
            // design exists to produce.
            throw new DecryptionFailed(sprintf(
                'Could not decrypt secret %s for organisation %s.',
                $name,
                $organizationId
            ));
        }

        return $plaintext;
    }

    public function has(string $organizationId, string $name): bool
    {
        return $this->db()->table('secrets')
            ->where('organization_id', $organizationId)
            ->where('name', $name)
            ->exists();
    }

    /**
     * Remove a secret. Returns true if something was deleted.
     */
    public function forget(string $organizationId, string $name): bool
    {
        return $this->db()->table('secrets')
            ->where('organization_id', $organizationId)
            ->where('name', $name)
            ->delete() > 0;
    }

    /**
     * @return array<int, string> Secret names held for an organisation.
     */
    public function names(string $organizationId): array
    {
        return $this->db()->table('secrets')
            ->where('organization_id', $organizationId)
            ->orderBy('name')
            ->pluck('name')
            ->all();
    }

    /**
     * Rotate an organisation's data key and re-encrypt everything under it.
     *
     * Use when a data key is suspected compromised. A master key rotation is
     * a different and much cheaper operation; see rewrapDataKeys().
     *
     * @return int Number of secrets re-encrypted.
     */
    public function rotateDataKey(string $organizationId): int
    {
        return $this->db()->transaction(function () use ($organizationId): int {
            // Read every secret under the current key before switching, since
            // after the switch the old version is no longer primary.
            $rows = $this->db()->table('secrets')
                ->where('organization_id', $organizationId)
                ->get();

            $plaintexts = [];
            foreach ($rows as $row) {
                $plaintexts[$row->name] = $this->get($organizationId, $row->name);
            }

            $this->db()->table('organization_data_keys')
                ->where('organization_id', $organizationId)
                ->update(['is_primary' => false]);

            $this->createDataKey($organizationId);

            foreach ($plaintexts as $name => $plaintext) {
                if ($plaintext === null) {
                    continue;
                }
                $this->put($organizationId, (string) $name, $plaintext);
                sodium_memzero($plaintext);
            }

            return count($plaintexts);
        });
    }

    /**
     * Re-wrap every data key from a previous master key onto the current one.
     *
     * This is what a master key rotation costs: one row per organisation, no
     * secret touched, and no plaintext produced beyond the data keys
     * themselves. The split between data keys and the master key exists
     * specifically so this is cheap enough to do routinely.
     *
     * The previous KeyManager must be passed in. Unwrapping with the current
     * one cannot work, because the rows on disk were wrapped by the key being
     * retired, and an implementation that tries makes rotation impossible.
     *
     * Rows already on the current master key are skipped, so this is safe to
     * re-run after a partial failure.
     *
     * @return int Number of data keys re-wrapped.
     */
    public function rewrapDataKeys(KeyManager $previous): int
    {
        $currentId = $this->keyManager->masterKeyId();

        if ($previous->masterKeyId() === $currentId) {
            throw new \InvalidArgumentException(
                'The previous master key id matches the current one. A rotation must change '
                .'VAULT_MASTER_KEY_ID as well as the key, or rotated rows cannot be told apart '
                .'from un-rotated ones.'
            );
        }

        $rows = $this->db()->table('organization_data_keys')
            ->where('master_key_id', $previous->masterKeyId())
            ->get();

        $count = 0;
        foreach ($rows as $row) {
            $material = $previous->unwrap($row->wrapped_key);

            try {
                $this->db()->table('organization_data_keys')
                    ->where('id', $row->id)
                    ->update([
                        'wrapped_key' => $this->keyManager->wrap($material),
                        'master_key_id' => $currentId,
                        'updated_at' => now(),
                    ]);
            } finally {
                sodium_memzero($material);
            }

            $count++;
        }

        return $count;
    }

    /**
     * How many data keys are still wrapped by something other than the
     * current master key. Zero means a rotation is complete.
     */
    public function pendingRewrapCount(): int
    {
        return $this->db()->table('organization_data_keys')
            ->where('master_key_id', '!=', $this->keyManager->masterKeyId())
            ->count();
    }

    /**
     * Fetch the organisation's write key, creating one on first use.
     *
     * @return array{version: int, material: string}
     */
    private function primaryDataKey(string $organizationId): array
    {
        $row = $this->db()->table('organization_data_keys')
            ->where('organization_id', $organizationId)
            ->where('is_primary', true)
            ->first();

        if ($row === null) {
            return $this->createDataKey($organizationId);
        }

        return [
            'version' => (int) $row->version,
            'material' => $this->keyManager->unwrap($row->wrapped_key),
        ];
    }

    /**
     * Fetch a specific key version for reading older ciphertexts.
     *
     * @return array{version: int, material: string}
     */
    private function dataKey(string $organizationId, int $version): array
    {
        $row = $this->db()->table('organization_data_keys')
            ->where('organization_id', $organizationId)
            ->where('version', $version)
            ->first();

        if ($row === null) {
            throw new DecryptionFailed(sprintf(
                'No data key version %d exists for organisation %s.',
                $version,
                $organizationId
            ));
        }

        return [
            'version' => (int) $row->version,
            'material' => $this->keyManager->unwrap($row->wrapped_key),
        ];
    }

    /**
     * @return array{version: int, material: string}
     */
    private function createDataKey(string $organizationId): array
    {
        $material = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_KEYBYTES);

        $version = (int) $this->db()->table('organization_data_keys')
            ->where('organization_id', $organizationId)
            ->max('version') + 1;

        try {
            $this->db()->table('organization_data_keys')->insert([
                'organization_id' => $organizationId,
                'version' => $version,
                'wrapped_key' => $this->keyManager->wrap($material),
                'master_key_id' => $this->keyManager->masterKeyId(),
                'is_primary' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } catch (Throwable $e) {
            sodium_memzero($material);

            throw $e;
        }

        return ['version' => $version, 'material' => $material];
    }

    /**
     * Context bound into every ciphertext.
     *
     * Including the organisation, name and key version here is what makes a
     * ciphertext non-portable. Without it, moving a row between tenants in
     * the database would hand one customer another customer's credentials,
     * and nothing in the crypto would object.
     */
    private function associatedData(string $organizationId, string $name, int $version): string
    {
        return implode('|', ['v1', $organizationId, $name, (string) $version]);
    }

    /**
     * Normalise a binary column, which some drivers hand back as a stream.
     */
    private function asBinary(mixed $value): string
    {
        if (is_resource($value)) {
            return (string) stream_get_contents($value);
        }

        return (string) $value;
    }
}
