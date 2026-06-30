<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\ActivityResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Vaulter\Filament\Resources\ActivityResource;

final class ListActivities extends ListRecords
{
    protected static string $resource = ActivityResource::class;
}
