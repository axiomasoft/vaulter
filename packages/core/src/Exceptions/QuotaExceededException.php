<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

/**
 * The upload would push v_storages.used_bytes past v_storages.quota_bytes (§1.5,
 * eager quota). Checked BEFORE the blob is committed so we never store bytes we
 * must immediately reject. A null quota means unlimited and never throws.
 */
final class QuotaExceededException extends VaulterException
{
    public static function forStorage(int|string $storageId, int $quotaBytes, int $usedBytes, int $incomingBytes): self
    {
        return new self(
            "[Vaulter] Upload of {$incomingBytes} bytes exceeds the quota for storage [{$storageId}] ".
            "(used {$usedBytes} of {$quotaBytes}). ".
            'Fix: raise v_storages.quota_bytes, free space, or set quota_bytes to null for unlimited. '.
            'Docs: '.self::docs('quota-exceeded'),
        );
    }
}
