<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

use Attribute;

/**
 * Not part of the supported surface: a type that no host should name, or a member of an `#[Api]` type that the
 * host must not call (a technical constructor, a hook for the package's own code). It may change in any release.
 */
#[Api]
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Internal
{
    public function __construct(
        public ?string $reason = null,
    ) {}
}
