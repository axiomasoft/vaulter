<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\ShareResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Vaulter\Filament\Resources\ShareResource;

final class ListShares extends ListRecords
{
    protected static string $resource = ShareResource::class;
}
