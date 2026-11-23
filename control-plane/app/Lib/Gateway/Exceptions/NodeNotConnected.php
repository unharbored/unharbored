<?php

declare(strict_types=1);

namespace App\Lib\Gateway\Exceptions;

/**
 * The node is not currently holding a session.
 * Distinct from unknown: nothing was sent, so nothing ran, and retrying when
 * the node reconnects is safe.
 */
final class NodeNotConnected extends GatewayException
{
}
