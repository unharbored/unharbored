<?php

declare(strict_types=1);

namespace App\Lib\Vault\Exceptions;

/**
 * The master key is missing or malformed, so nothing can be wrapped or
 * unwrapped. This is a configuration failure and should stop the process
 * rather than degrade to storing anything in the clear.
 */
final class MasterKeyUnavailable extends VaultException
{
}
