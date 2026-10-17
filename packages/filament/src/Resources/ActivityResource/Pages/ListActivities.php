<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\ActivityResource\Pages;

use Vaulter\Attributes\Internal;
use Vaulter\Filament\Resources\ActivityResource;
use Vaulter\Filament\Resources\Pages\ListPanelRecords;

#[Internal]
final class ListActivities extends ListPanelRecords
{
    protected static string $resource = ActivityResource::class;
}
