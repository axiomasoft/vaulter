<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

use Attribute;
use Packman\Attributes\Api;

#[Api]
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Icon
{
    public function __construct(
        public string $value,
    ) {}
}
