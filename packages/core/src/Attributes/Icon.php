<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

use Attribute;

#[Api]
#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Icon
{
    public function __construct(
        public string $value,
    ) {}
}
