<?php

declare(strict_types=1);

namespace DocVault\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Icon
{
    public function __construct(
        public string $value,
    ) {}
}
