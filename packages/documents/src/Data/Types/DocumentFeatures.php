<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Types;

use Packman\Attributes\Api;

/** Intrinsic content features; collaboration depends on the active runtime. */
#[Api]
final readonly class DocumentFeatures
{
    public function __construct(
        public bool $editable = true,
        public bool $previewable = true,
        public bool $thumbnailable = false,
        public bool $downloadable = false,
    ) {}

    public static function readOnly(): self
    {
        return new self(editable: false);
    }

    /** @return array{editable: bool, previewable: bool, thumbnailable: bool, downloadable: bool} */
    public function toArray(): array
    {
        return [
            'editable' => $this->editable,
            'previewable' => $this->previewable,
            'thumbnailable' => $this->thumbnailable,
            'downloadable' => $this->downloadable,
        ];
    }
}
