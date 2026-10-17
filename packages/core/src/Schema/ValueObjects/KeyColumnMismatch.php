<?php

declare(strict_types=1);

namespace Vaulter\Schema\ValueObjects;

use Vaulter\Attributes\Internal;

#[Internal]
final readonly class KeyColumnMismatch
{
    /**
     * @param 'missing'|'type'|'length' $reason
     */
    public function __construct(
        public KeyColumnExpectation $expectation,
        public string $reason,
        public ?string $actual = null,
    ) {}
}
