<?php

declare(strict_types=1);

namespace App\Lib\Vault\Exceptions;

/**
 * A secret could not be decrypted.
 *
 * The most interesting cause is not corruption but context mismatch: every
 * ciphertext is bound to its organisation, name and key version through the
 * AEAD associated data, so a row moved between organisations or renamed in
 * the database will fail here rather than decrypt into the wrong tenant.
 */
final class DecryptionFailed extends VaultException
{
}
