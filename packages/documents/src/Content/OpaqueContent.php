<?php

declare(strict_types=1);

namespace Vaulter\Documents\Content;

use Vaulter\Documents\Contracts\DocumentContent;

/**
 * JSON content stored and returned verbatim, without imposing a shape: the content of
 * a spreadsheet workbook, of any type decoded by {@see OpaqueJsonCodec}, and of a
 * document whose type is not installed (read-only then). Nothing interprets it.
 */
final readonly class OpaqueContent implements DocumentContent
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
        return ContentHasher::hash($this->data);
    }

    public function sizeInBytes(): int
    {
        return ContentHasher::size($this->data);
    }
}
