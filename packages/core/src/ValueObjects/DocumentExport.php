<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects;

/**
 * The rendered result of exporting a document node (§7 / M4a) — the payload the
 * export controller serialises. Immutable.
 */
final readonly class DocumentExport
{
    public function __construct(
        public string $format,
        public string $content,
        public string $mimeType,
        public string $filename,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'format' => $this->format,
            'content' => $this->content,
            'mime_type' => $this->mimeType,
            'filename' => $this->filename,
        ];
    }
}
