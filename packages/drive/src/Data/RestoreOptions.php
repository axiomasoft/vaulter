<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Enums\OnNameConflict;

#[Api]
final readonly class RestoreOptions
{
    /**
     * @param int|null $expectedSequence the trashed node's sequence the caller last saw;
     *                                   checked before anything is restored
     */
    public function __construct(
        public NodeSnapshot|string|int|null $destination = null,
        public OnNameConflict $onConflict = OnNameConflict::Rename,
        public ?int $expectedSequence = null,
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

    public function destination(NodeSnapshot|string|int|null $destination): self
    {
        return new self(destination: $destination, onConflict: $this->onConflict, expectedSequence: $this->expectedSequence);
    }

    public function onConflict(OnNameConflict $onConflict): self
    {
        return new self(destination: $this->destination, onConflict: $onConflict, expectedSequence: $this->expectedSequence);
    }

    public function expectedSequence(?int $expectedSequence): self
    {
        return new self(destination: $this->destination, onConflict: $this->onConflict, expectedSequence: $expectedSequence);
    }
}
