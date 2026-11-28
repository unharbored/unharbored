<?php

declare(strict_types=1);

namespace App\Lib\Sites;

/** Something tried to change an archived application's server. An InvalidArgumentException so the places that already turn those into a message do. */
final class SiteArchived extends \InvalidArgumentException
{
    public function __construct(string $name)
    {
        parent::__construct("{$name} is archived: Unharbored no longer manages it. Restore it first to change anything.");
    }
}
