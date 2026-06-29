<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Label
{
    public function __construct(
        public string $value,
    ) {}
}
