<?php

declare(strict_types=1);

namespace App\Lib\Vault\Exceptions;

/**
 * A wrapped data key could not be recovered: wrong master key, corruption, or
 * tampering. The three are deliberately not distinguished, because the
 * authentication tag cannot tell them apart and guessing would be worse than
 * silence.
 */
final class UnwrapFailed extends VaultException
{
}
