<?php

declare(strict_types=1);

namespace Vaulter\Attributes;

use Attribute;

/**
 * A supported type or member for the code that uses Vaulter (D27). Everything an `#[Api]` type shows publicly and
 * protectedly — except what is marked {@see Internal} — is frozen with the type; parameter names count, because
 * named arguments are API.
 */
#[Api]
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD | Attribute::TARGET_PROPERTY | Attribute::TARGET_CLASS_CONSTANT)]
final readonly class Api
{
    public function __construct(
        /** The release that first promised it. */
        public string $since = '1.0',
    ) {}
}
