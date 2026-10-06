<?php

declare(strict_types=1);

namespace Vaulter\Data\Drive;

use InvalidArgumentException;

final readonly class CopyResult
{
    public function __construct(public int $addedBytes)
    {
        if ($addedBytes < 0) {
            throw new InvalidArgumentException('Added bytes cannot be negative.');
        }
    }
}
