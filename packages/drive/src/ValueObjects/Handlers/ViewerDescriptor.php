<?php

declare(strict_types=1);

namespace Vaulter\Drive\ValueObjects\Handlers;

use Vaulter\Attributes\Api;

/** Client-independent viewer selection for a file. */
#[Api]
final readonly class ViewerDescriptor
{
    /** @param array<string, mixed> $props */
    public function __construct(
        public string $key,
        public array $props = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['key' => $this->key, 'props' => $this->props];
    }
}
