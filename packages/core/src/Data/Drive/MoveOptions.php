<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use InvalidArgumentException;
use Vaulter\Enums\Drive\OnNameConflict;

final readonly class MoveOptions
{
    public function __construct(
        public ?int $expectedSequence = null,
        public OnNameConflict $onConflict = OnNameConflict::Fail,
    ) {
        if ($onConflict === OnNameConflict::AddRevision) {
            throw new InvalidArgumentException('AddRevision is only valid for uploads.');
        }
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
        return new self(expectedSequence: $expectedSequence, onConflict: $this->onConflict);
    }

    public function onConflict(OnNameConflict $onConflict): self
    {
        return new self(expectedSequence: $this->expectedSequence, onConflict: $onConflict);
    }
}
