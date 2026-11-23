<?php

declare(strict_types=1);

namespace App\Lib\Vault\Exceptions;

use RuntimeException;

/**
 * Base for vault failures.
 *
 * Vault exceptions must never carry plaintext, key material, or ciphertext in
 * their message. They are logged, and a log line is a place secrets go to be
 * read later by someone who should not have them.
 */
class VaultException extends RuntimeException
{
}
