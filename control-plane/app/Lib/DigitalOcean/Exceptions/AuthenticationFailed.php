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
}
