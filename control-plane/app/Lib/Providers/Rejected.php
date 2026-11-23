<?php

declare(strict_types=1);

namespace App\Lib\Providers;

use App\Lib\Cloud\Exceptions\CloudException;

/** The provider refused the request. Nothing was created. */
final class Rejected extends CloudException {}
