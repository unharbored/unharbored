<?php

declare(strict_types=1);

namespace App\Lib\DigitalOcean\Exceptions;

use RuntimeException;

/**
 * Base for DigitalOcean API failures.
 *
 * No subclass may carry the account token in its message. These exceptions
 * are logged, reported, and pasted into tickets.
 */
class DigitalOceanException extends RuntimeException
{
}
