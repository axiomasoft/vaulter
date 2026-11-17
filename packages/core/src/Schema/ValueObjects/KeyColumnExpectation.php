<?php

declare(strict_types=1);

namespace Vaulter\Schema\ValueObjects;

use Packman\Attributes\Api;

#[Api]
final readonly class KeyColumnExpectation
{
    /**
     * @param 'REF'|'HK'|'STRING' $kind
     */
    public function __construct(
        public string $table,
        public string $column,
        public string $kind,
        public ?int $length = null,
    ) {}
}
