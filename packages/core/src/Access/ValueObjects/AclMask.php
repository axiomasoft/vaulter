<?php

declare(strict_types=1);

namespace Vaulter\Access\ValueObjects;

use Vaulter\Enums\Access\GrantLevel;

final readonly class AclMask
{
    public function __construct(public int $allowed, public int $denied, public ?GrantLevel $level) {}

    public static function none(): self
    {
        return new self(allowed: 0, denied: 0, level: null);
    }
}
