<?php

declare(strict_types=1);

namespace App\Lib\Agent\Exceptions;

use RuntimeException;

/**
 * No agent build is available for a platform.
 *
 * Raised rather than returning an empty checksum. Generating cloud-init with
 * no checksum would produce nodes that run whatever they were handed, which
 * is the one thing the checksum exists to prevent.
 */
final class AgentBinaryMissing extends RuntimeException
{
}
