<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Problems;

use Packman\Attributes\Internal;
use Vaulter\Drive\Exceptions\Files\UploadRejectedException;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Http\Problems\ProblemCodeSource;
use Vaulter\Http\Problems\ProblemSpec;
use Vaulter\Http\Problems\ReceiptPolicy;
use Vaulter\Http\Problems\Retryable;

/**
 * The problem codes of the drive package: a stable public contract, so a code is added
 * here, in lang/{en,ru}/problems.php and in the reference docs together.
 */
#[Internal]
final class DriveProblemCodes implements ProblemCodeSource
{
    public function translationNamespace(): string
    {
        return 'vaulter-drive';
    }

    public function codes(): array
    {
        return [
            'cross_drive_operation' => new ProblemSpec(409, CrossDriveOperationException::class, receipt: ReceiptPolicy::Cacheable),
            'node_move_cycle' => new ProblemSpec(409, NodeCycleException::class, receipt: ReceiptPolicy::Cacheable),
            // A lost lock race or an offset the upload moved past: the answer depends on a concurrent request, so it is never cached.
            'upload_conflict' => new ProblemSpec(409, UploadConflictException::class, Retryable::AfterRefetch),
            'upload_not_found' => new ProblemSpec(404),
            'upload_offset_mismatch' => new ProblemSpec(409, OffsetMismatchException::class, Retryable::AfterRefetch),
            'upload_rejected' => new ProblemSpec(422, UploadRejectedException::class, receipt: ReceiptPolicy::Cacheable),
        ];
    }
}
