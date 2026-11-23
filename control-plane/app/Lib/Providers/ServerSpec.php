<?php

declare(strict_types=1);

namespace App\Lib\Providers;

/** What to create. Tags are provider-neutral strings; the driver maps them. */
final readonly class ServerSpec
{
    /**
     * @param  list<string>  $tags
     * @param  array<string, mixed>  $extra  vpc_uuid, ssh_keys and other provider-specific hints
     */
    public function __construct(
        public string $name,
        public string $region,
        public string $size,
        public string $image,
        public string $userData,
        public string $idempotencyKey,
        public array $tags = [],
        public array $extra = [],
    ) {}
}
