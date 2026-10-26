<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Types;

use Vaulter\Attributes\Api;

/** The client component key and its document-specific props. */
#[Api]
final readonly class DocumentViewer
{
    /** @param array<string, mixed> $props */
    public function __construct(public string $key, public array $props = []) {}

    /** @return array{key: string, props: array<string, mixed>} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'props' => $this->props];
    }
}
