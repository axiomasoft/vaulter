<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\StorageResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Vaulter\Filament\Resources\StorageResource;

final class ListStorages extends ListRecords
{
    protected static string $resource = StorageResource::class;
}
