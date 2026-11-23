<?php

declare(strict_types=1);

namespace App\Lib\Cloud\Exceptions;

/**
 * No usable credential for this organisation.
 *
 * Also covers the worse case of a credential row pointing at a vault entry
 * that does not exist, which looks connected and is not.
 */
final class CredentialNotFound extends CloudException
{
}
