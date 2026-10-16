<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\DriveResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Vaulter\Attributes\Internal;
use Vaulter\Filament\Resources\DriveResource;

#[Internal]
final class ListDrives extends ListRecords
{
    protected static string $resource = DriveResource::class;
}
