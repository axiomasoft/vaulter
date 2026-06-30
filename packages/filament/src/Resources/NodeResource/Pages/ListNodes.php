<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\NodeResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Vaulter\Filament\Resources\NodeResource;

final class ListNodes extends ListRecords
{
    protected static string $resource = NodeResource::class;
}
