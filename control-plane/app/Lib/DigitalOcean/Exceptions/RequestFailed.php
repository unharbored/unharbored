<?php

declare(strict_types=1);

namespace App\Lib\DigitalOcean\Exceptions;

use Throwable;

/**
 * A request failed in a way retrying will not fix, or retries ran out.
 */
final class RequestFailed extends DigitalOceanException
{
    public function __construct(
        string $message,
        public readonly ?int $status = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
