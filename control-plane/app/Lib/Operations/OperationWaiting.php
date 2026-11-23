<?php

declare(strict_types=1);

namespace App\Lib\Operations;

use RuntimeException;

/**
 * Thrown by an operation that is waiting on something outside the platform,
 * such as a person running an install command. The job goes back on the
 * queue and checks again shortly, rather than holding the only worker while
 * deploys queue up behind it.
 */
final class OperationWaiting extends RuntimeException {}
