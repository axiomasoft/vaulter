<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\StorageResource\Pages;

use Filament\Resources\Pages\ViewRecord;
use Vaulter\Filament\Resources\StorageResource;

final class ViewStorage extends ViewRecord
{
    protected static string $resource = StorageResource::class;
}
