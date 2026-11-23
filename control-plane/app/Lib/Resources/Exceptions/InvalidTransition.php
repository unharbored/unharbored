<?php

declare(strict_types=1);

namespace App\Lib\Resources\Exceptions;

/**
 * A state change the graph does not allow.
 *
 * Thrown rather than tolerated. A resource that can move anywhere records
 * nothing useful about where it has been, and the history is the point.
 */
final class InvalidTransition extends ResourceException
{
}
