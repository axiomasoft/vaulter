<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Illuminate\Database\Eloquent\Model;
use Vaulter\Attributes\Internal;
use Vaulter\Models\Drive\Drive;

#[Internal]
interface DriveHandleFactory
{
    public function forOwner(Model $owner, string $profile): DriveHandle;

    public function forDrive(Drive $drive): DriveHandle;

    public function findForOwner(Model $owner, string $profile): ?DriveHandle;
}
