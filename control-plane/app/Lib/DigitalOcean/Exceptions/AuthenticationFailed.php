<?php

declare(strict_types=1);

namespace App\Lib\DigitalOcean\Exceptions;

/**
 * DigitalOcean rejected our credentials.
 *
 * Usually means the customer revoked the OAuth grant, which is a thing they
 * are entitled to do at any moment and which the platform must survive
 * without losing track of what it already provisioned.
 */
final class AuthenticationFailed extends DigitalOceanException
{
    /** $status is DigitalOcean's: 401 when it does not know the token, 403 when it does but the token may not do this. */
    public function __construct(string $message = '', public readonly int $status = 401)
    {
        parent::__construct($message);
    }

    public function missingPermission(): bool
    {
        return $this->status === 403;
    }
}
