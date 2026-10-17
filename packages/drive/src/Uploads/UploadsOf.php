<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use LogicException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle as Contract;
use Vaulter\Drive\DriveHandle;
use Vaulter\Drive\Operations\UploadOperations;

/**
 * The upload operations of a drive handle the upload protocols got from the HTTP layer: the drive
 * module's own handle, typed; no extension lookup.
 */
#[Internal]
final class UploadsOf
{
    public static function handle(Contract $drive): UploadOperations
    {
        if (!$drive instanceof DriveHandle) {
            throw new LogicException('Upload operations need the drive module\'s own handle.');
        }

        return $drive->uploads();
    }
}
