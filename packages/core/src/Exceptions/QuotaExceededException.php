<?php

declare(strict_types=1);

namespace Vaulter\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    /**
     * Surface a quota overflow as 413 Payload Too Large — the upload simply does
     * not fit, which is exactly what 413 means.
     */
    public function render(Request $request): JsonResponse
    {
        return new JsonResponse(['message' => $this->getMessage()], 413);
    }
}
