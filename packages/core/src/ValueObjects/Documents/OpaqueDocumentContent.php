<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects\Documents;

use Vaulter\Contracts\Documents\DocumentContent;
use Vaulter\Documents\Handlers\UniverHandler;

/**
 * A document version payload the core stores VERBATIM, without imposing the TipTap
 * shape (§7 level 2). Used by non-rich-text handlers — e.g. the spreadsheet
 * {@see UniverHandler}, whose content is a Univer workbook
 * snapshot, not a `{"type":"doc"}` document. The core never interprets it; it round-
 * trips the JSON and hashes it for autosave dedup.
 */
final readonly class OpaqueDocumentContent implements DocumentContent
{
    /**
     * @param array<string, mixed> $data
     */
    private function __construct(
        public array $data,
    ) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public function contentHash(): string
    {
        return hash('sha256', (string) json_encode($this->data));
    }
}
