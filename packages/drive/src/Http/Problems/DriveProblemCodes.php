<?php

declare(strict_types=1);

namespace Vaulter\Drive\Http\Problems;

use Vaulter\Drive\Exceptions\Files\UploadRejectedException;
use Vaulter\Drive\Exceptions\Nodes\CrossDriveOperationException;
use Vaulter\Drive\Exceptions\Nodes\NodeCycleException;
use Vaulter\Drive\Exceptions\Uploads\OffsetMismatchException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Http\Problems\ProblemCodeSource;

/**
 * The problem codes of the drive package: a stable public contract, so a code is added
 * here, in lang/{en,ru}/problems.php and in the reference docs together.
 */
final class DriveProblemCodes implements ProblemCodeSource
{
    public function package(): string
    {
        return 'drive';
    }

    public function translationNamespace(): string
    {
        return 'vaulter-drive';
    }

    public function codes(): array
    {
        return [
            'cross_drive_operation' => ['status' => 409, 'exception' => CrossDriveOperationException::class],
            'node_move_cycle' => ['status' => 409, 'exception' => NodeCycleException::class],
            'upload_conflict' => ['status' => 409, 'exception' => UploadConflictException::class],
            'upload_not_found' => ['status' => 404, 'exception' => null],
            'upload_offset_mismatch' => ['status' => 409, 'exception' => OffsetMismatchException::class],
            'upload_rejected' => ['status' => 422, 'exception' => UploadRejectedException::class],
        ];
    }
}
