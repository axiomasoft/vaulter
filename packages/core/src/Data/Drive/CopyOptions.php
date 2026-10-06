<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use InvalidArgumentException;
use Vaulter\Enums\Drive\OnNameConflict;

final readonly class CopyOptions
{
    public function __construct(
        public ?string $name = null,
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

    public function name(?string $name): self
    {
        return new self(name: $name, onConflict: $this->onConflict);
    }

    public function onConflict(OnNameConflict $onConflict): self
    {
        return new self(name: $this->name, onConflict: $onConflict);
    }
}
