<?php

declare(strict_types=1);

namespace Vaulter\Facades;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Vaulter\Access\PermissionDriverManager;
use Vaulter\Attributes\Api;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Profiles\ProfileRegistry;

/**
 * @method static DriveHandle             drive(Model $ownerOrDrive, ?string $profile = null)
 * @method static DriveHandle|null        findDrive(Model $owner, ?string $profile = null)
 * @method static ProfileRegistry         profiles()
 * @method static FileHandlerRegistry     handlers()
 * @method static PermissionDriverManager permissions()
 * @method static mixed                   withoutTenancy(Closure $callback)
 */
#[Api]
final class Vaulter extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'vaulter';
    }
}
