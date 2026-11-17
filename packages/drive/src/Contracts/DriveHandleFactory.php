<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Illuminate\Database\Eloquent\Model;
use Packman\Attributes\Internal;
use Vaulter\Drive\Models\Drive;

#[Internal]
interface DriveHandleFactory
{
    public function forOwner(Model $owner, string $profile): DriveHandle;

    public function forDrive(Drive $drive): DriveHandle;

    public function findForOwner(Model $owner, string $profile): ?DriveHandle;

    /** The drive row by key within the current tenant, or null; visibility is the handle's operations' decision. */
    public function findById(int|string $id): ?DriveHandle;
}
