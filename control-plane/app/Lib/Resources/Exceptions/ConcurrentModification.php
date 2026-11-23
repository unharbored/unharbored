<?php

declare(strict_types=1);

namespace App\Lib\Resources\Exceptions;

/**
 * Another process moved this resource first.
 *
 * The caller should reload and decide again rather than retrying blindly:
 * the other worker may already have done the thing this one was about to do.
 */
final class ConcurrentModification extends ResourceException
{
}
