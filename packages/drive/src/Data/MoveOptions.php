<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Enums\OnNameConflict;

#[Api]
final readonly class MoveOptions
{
    public function __construct(
        public ?int $expectedSequence = null,
        public OnNameConflict $onConflict = OnNameConflict::Fail,
        public ?int $expectedDestinationSequence = null,
    ) {
        if ($onConflict === OnNameConflict::AddRevision) {
            throw new InvalidArgumentException('AddRevision is only valid for uploads.');
        }
        if (PHP_INT_SIZE !== 8 || ($expectedSequence !== null && $expectedSequence < 0) || ($expectedDestinationSequence !== null && $expectedDestinationSequence < 0)) {
            throw new InvalidArgumentException('Expected sequence must be a nonnegative signed 64-bit integer.');
        }
    }

    public static function make(): self
    {
        return new self;
    }

    public function expectedSequence(?int $expectedSequence): self
    {
        return new self(expectedSequence: $expectedSequence, onConflict: $this->onConflict, expectedDestinationSequence: $this->expectedDestinationSequence);
    }

    public function onConflict(OnNameConflict $onConflict): self
    {
        return new self(expectedSequence: $this->expectedSequence, onConflict: $onConflict, expectedDestinationSequence: $this->expectedDestinationSequence);
    }

    /** The sequence the destination folder must still have (CC3): a move into a folder that changed since the caller read it is refused. */
    public function expectedDestinationSequence(?int $expectedDestinationSequence): self
    {
        return new self(expectedSequence: $this->expectedSequence, onConflict: $this->onConflict, expectedDestinationSequence: $expectedDestinationSequence);
    }
}
