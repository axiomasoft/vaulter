<?php

declare(strict_types=1);

namespace DocVault\Attributes;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS_CONSTANT)]
final readonly class TiptapExtensions
{
    /**
     * @param list<string> $names
     */
    public function __construct(public array $names) {}
}
