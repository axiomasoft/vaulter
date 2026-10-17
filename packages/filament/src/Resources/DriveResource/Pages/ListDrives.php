<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\DriveResource\Pages;

use Vaulter\Attributes\Internal;
use Vaulter\Filament\Resources\DriveResource;
use Vaulter\Filament\Resources\Pages\ListPanelRecords;

#[Internal]
final class ListDrives extends ListPanelRecords
{
    protected static string $resource = DriveResource::class;
}
