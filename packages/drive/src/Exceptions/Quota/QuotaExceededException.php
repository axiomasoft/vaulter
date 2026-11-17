<?php

declare(strict_types=1);

namespace Vaulter\Drive\Exceptions\Quota;

use Packman\Attributes\Api;
use Vaulter\Drive\Exceptions\QuotaExceededException as CoreQuotaExceededException;

/** The Drive boundary preserves the existing upload/copy exception type. */
#[Api]
final class QuotaExceededException extends CoreQuotaExceededException
{
    public static function forStorage(int|string $storageId, int $quotaBytes, int $usedBytes, int $incomingBytes): self
    {
        $error = parent::forStorage($storageId, $quotaBytes, $usedBytes, $incomingBytes);

        return new self($error->getMessage());
    }
}
