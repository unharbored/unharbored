<?php

declare(strict_types=1);

namespace App\Lib\Cloud\Exceptions;

/**
 * The credential exists but is no longer usable.
 *
 * Usually because the customer revoked our access, which they are entitled to
 * do at any moment. Resources provisioned under it still exist and still cost
 * money, so this is a situation to resolve rather than to clean up quietly.
 */
final class CredentialRevoked extends CloudException
{
}
