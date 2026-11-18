<?php

declare(strict_types=1);

namespace Vaulter\Filament\Support;

use Illuminate\Support\Facades\Facade;
use Packman\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;

/**
 * The container bridge for Filament's static resources; dependencies belong to the service.
 *
 * @method static list<DriveHandle>          handles()
 * @method static DriveHandle|null           handle(string $driveId)
 * @method static list<string>               ids()
 * @method static list<array<string, mixed>> rows()
 */
#[Internal]
final class PanelDrives extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return PanelDriveAccess::class;
    }
}
