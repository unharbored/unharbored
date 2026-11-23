<?php

declare(strict_types=1);

namespace App\Lib\Pki\Exceptions;

/**
 * A certificate signing request is malformed or its signature does not match
 * the key it carries. The latter means proof of possession failed, which is
 * the whole reason the signature is checked.
 */
final class InvalidCsr extends PkiException
{
}
