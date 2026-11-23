<?php

declare(strict_types=1);

namespace App\Lib\Cloud\Exceptions;

use Throwable;

/**
 * A provisioning attempt failed.
 *
 * Carries whether the resource may nonetheless exist at the provider. That
 * distinction is the whole game: a failure where we know nothing was created
 * is cheap, and a failure where something may be running and billing is the
 * expensive one PLAN.md calls the worst bug class in the product.
 */
final class ProvisioningFailed extends CloudException
{
    public function __construct(
        string $message,
        public readonly bool $mayHaveCreatedResource = false,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
