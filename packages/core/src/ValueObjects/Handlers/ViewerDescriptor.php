<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Handlers;

/** Client-independent viewer selection for a file. */
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
