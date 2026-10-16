<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\DriveResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Vaulter\Attributes\Internal;
use Vaulter\Filament\Resources\DriveResource;

#[Internal]
final class ViewDrive extends ViewRecord
{
    protected static string $resource = DriveResource::class;
}
