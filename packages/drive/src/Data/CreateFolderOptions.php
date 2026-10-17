<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use InvalidArgumentException;
use Vaulter\Attributes\Api;
use Vaulter\Drive\Enums\OnNameConflict;

#[Api]
final readonly class CreateFolderOptions
{
    public function __construct(
        public OnNameConflict $onConflict = OnNameConflict::Fail,
    ) {
        if ($onConflict === OnNameConflict::AddRevision) {
            throw new InvalidArgumentException('AddRevision is only valid for uploads.');
        }
    }

    public static function make(): self
    {
        return new self;
    }

    public function onConflict(OnNameConflict $onConflict): self
    {
        return new self(onConflict: $onConflict);
    }
}
