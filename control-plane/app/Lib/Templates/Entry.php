<?php

declare(strict_types=1);

namespace App\Lib\Templates;

/**
 * One template the catalogue can offer. Built-in and local files and cached
 * files carry the full Definition; an entry from a remote index carries only
 * the index summary until Sources::definition() fetches the file.
 */
final class Entry
{
    public function __construct(
        public readonly string $id,
        public readonly int $version,
        public readonly string $name,
        public readonly string $category,
        public readonly string $publisher,
        public readonly string $source,      // builtin, the folder, or the index URL
        public readonly string $tier,        // official | community | custom
        public readonly ?string $label,      // null for official; "Community", "Custom, not reviewed"
        public readonly ?Definition $definition = null,
        public readonly ?string $file = null,    // remote file URL
        public readonly ?string $sha256 = null,  // the index's hash of that file
    ) {}
}
