<?php

declare(strict_types=1);

namespace App\Lib\Templates;

use RuntimeException;

/** A remote template fetch that was refused or failed (the message says why). */
final class FetchFailed extends RuntimeException {}
