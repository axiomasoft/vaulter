<?php

declare(strict_types=1);

namespace Vaulter\DocDrive\ValueObjects;

use Vaulter\Contracts\Documents\DocumentContent;
use Vaulter\DocDrive\Exceptions\InvalidTipTapContentException;

/**
 * Immutable wrapper around a validated TipTap JSON document
 * ({"type":"doc","content":[...]}). The rich-text {@see DocumentContent}.
 */
final readonly class TipTapContent implements DocumentContent
{
    /**
     * @param array<string, mixed> $doc
     */
    private function __construct(
        public array $doc,
    ) {}

    /**
     * @param array<string, mixed> $doc
     */
    public static function fromArray(array $doc): self
    {
        if (($doc['type'] ?? null) !== 'doc') {
            throw InvalidTipTapContentException::missingDocNode();
        }

        return new self($doc);
    }

    public static function empty(): self
    {
        return new self(['type' => 'doc', 'content' => []]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->doc;
    }

    public function contentHash(): string
    {
        return hash('sha256', (string) json_encode($this->doc));
    }

    public function sizeInBytes(): int
    {
        return mb_strlen((string) json_encode($this->doc), '8bit');
    }

    public function sizeInKb(): float
    {
        return $this->sizeInBytes() / 1024;
    }

    public function ensureWithin(int $maxKb): self
    {
        $size = $this->sizeInKb();

        if ($maxKb > 0 && $size > $maxKb) {
            throw InvalidTipTapContentException::tooLarge($size, $maxKb);
        }

        return $this;
    }
}
