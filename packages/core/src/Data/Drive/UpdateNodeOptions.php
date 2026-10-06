<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use InvalidArgumentException;

final readonly class UpdateNodeOptions
{
    public function __construct(
        public ?bool $pinned = null,
        public ?int $position = null,
        public ?int $expectedSequence = null,
    ) {
        if (PHP_INT_SIZE !== 8 || ($expectedSequence !== null && $expectedSequence < 0)) {
            throw new InvalidArgumentException('Expected sequence must be a nonnegative signed 64-bit integer.');
        }
    }

    public static function make(): self
    {
        return new self;
    }

    public function pinned(?bool $pinned): self
    {
        return new self(pinned: $pinned, position: $this->position, expectedSequence: $this->expectedSequence);
    }

    public function position(?int $position): self
    {
        return new self(pinned: $this->pinned, position: $position, expectedSequence: $this->expectedSequence);
    }

    public function expectedSequence(?int $expectedSequence): self
    {
        return new self(pinned: $this->pinned, position: $this->position, expectedSequence: $expectedSequence);
    }
}
