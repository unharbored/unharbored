<?php

declare(strict_types=1);

namespace App\Lib\Gateway\Exceptions;

/**
 * The command may or may not have run.
 * The most important failure in this system to get right. It is not a failure
 * and it is not a success. The work may already have happened, so retrying
 * without an idempotency key risks doing it twice, and a release activation or
 * a migration done twice is how a deploy corrupts data while appearing to
 * recover.
 */
final class CommandOutcomeUnknown extends GatewayException
{
}
