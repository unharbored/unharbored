<?php

declare(strict_types=1);

namespace App\Lib\Providers;

use App\Lib\Cloud\Exceptions\CloudException;

/** No usable answer (timeout, 5xx). Something may have been created. */
final class Uncertain extends CloudException {}
