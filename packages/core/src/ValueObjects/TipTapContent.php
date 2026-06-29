<?php

declare(strict_types=1);

namespace Vaulter\ValueObjects;

use Vaulter\Exceptions\InvalidTipTapContentException;

/**
 * Immutable wrapper around a validated TipTap JSON document
 * ({"type":"doc","content":[...]}).
 */
final readonly class TipTapContent
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

    public function sizeInKb(): float
    {
        return mb_strlen((string) json_encode($this->doc), '8bit') / 1024;
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
