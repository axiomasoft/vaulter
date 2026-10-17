<?php

declare(strict_types=1);

namespace Vaulter\Drive\Data;

use InvalidArgumentException;
use Vaulter\Attributes\Api;

#[Api]
final readonly class CopyResult
{
    public function __construct(public int $addedBytes)
    {
        if ($addedBytes < 0) {
            throw new InvalidArgumentException('Added bytes cannot be negative.');
        }
    }
}
