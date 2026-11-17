<?php

declare(strict_types=1);

namespace Vaulter\Drive\Facades;

use Closure;
use Illuminate\Contracts\Auth\Access\Authorizable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Api;
use Vaulter\Data\Page;
use Vaulter\Drive\Access\PermissionDriverManager;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Contracts\Handlers\FileHandlerRegistry;
use Vaulter\Drive\Data\ListOptions;
use Vaulter\Drive\Data\Snapshots\DriveSnapshot;
use Vaulter\Drive\Profiles\ProfileRegistry;
use Vaulter\Tenancy\Tenancy;

/**
 * @method static DriveHandle             drive(Model $ownerOrDrive, ?string $profile = null)
 * @method static DriveHandle|null        findDrive(Model $owner, ?string $profile = null)
 * @method static DriveHandle|null        findDriveById(string|int $id)
 * @method static Page<DriveSnapshot>     drives(Authorizable $actor, ?ListOptions $options = null)
 * @method static ProfileRegistry         profiles()
 * @method static FileHandlerRegistry     handlers()
 * @method static PermissionDriverManager permissions()
 */
#[Api]
final class Vaulter extends Facade
{
    /**
     * @template T
     *
     * @param  Closure(): T $callback
     * @return T
     */
    public static function withoutTenancy(Closure $callback): mixed
    {
        return Tenancy::without($callback);
    }

    protected static function getFacadeAccessor(): string
    {
        return 'vaulter';
    }
}
