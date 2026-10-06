<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use InvalidArgumentException;

final readonly class RevisionOptions
{
    public function __construct(
        public ?int $expectedSequence = null,
        public ?string $declaredMimeType = null,
    ) {
        if (PHP_INT_SIZE !== 8 || ($expectedSequence !== null && $expectedSequence < 0)) {
            throw new InvalidArgumentException('Expected sequence must be a nonnegative signed 64-bit integer.');
        }
    }

    public static function make(): self
    {
        return new self;
    }

    public function expectedSequence(?int $expectedSequence): self
    {
        return new self(expectedSequence: $expectedSequence, declaredMimeType: $this->declaredMimeType);
    }

    public function declaredMimeType(?string $declaredMimeType): self
    {
        return new self(expectedSequence: $this->expectedSequence, declaredMimeType: $declaredMimeType);
    }
}
