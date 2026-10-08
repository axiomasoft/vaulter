<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use InvalidArgumentException;
use Vaulter\Data\Snapshots\NodeSnapshot;
use Vaulter\Enums\Drive\OnNameConflict;

final readonly class RestoreOptions
{
    public function __construct(
        public NodeSnapshot|string|int|null $destination = null,
        public OnNameConflict $onConflict = OnNameConflict::Rename,
    ) {
        if ($onConflict === OnNameConflict::AddRevision) {
            throw new InvalidArgumentException('AddRevision is only valid for uploads.');
        }
    }

    public static function make(): self
    {
        return new self;
    }

    public function destination(NodeSnapshot|string|int|null $destination): self
    {
        return new self(destination: $destination, onConflict: $this->onConflict);
    }

    public function onConflict(OnNameConflict $onConflict): self
    {
        return new self(destination: $this->destination, onConflict: $onConflict);
    }
}
