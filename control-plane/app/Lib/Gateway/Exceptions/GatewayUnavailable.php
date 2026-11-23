<?php

declare(strict_types=1);

namespace App\Lib\Gateway\Exceptions;

/**
 * The gateway itself could not be reached or refused the request.
 * A node that cannot be commanded is not necessarily broken. It keeps serving
 * traffic; it is merely unmanaged until the gateway returns.
 */
final class GatewayUnavailable extends GatewayException
{
}
