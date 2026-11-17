<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data\Files;

use Packman\Attributes\Api;

#[Api]
final readonly class UploadFacts
{
    public function __construct(
        public string $name,
        public string $extension,
        public int $size,
        public string $sha256,
        public ?string $detectedMimeType,
        public ?string $declaredMimeType,
        public string $stagedPath,
    ) {}
}
