<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\NodeResource\Pages;

use Vaulter\Attributes\Internal;
use Vaulter\Filament\Resources\NodeResource;
use Vaulter\Filament\Resources\Pages\ListPanelRecords;

#[Internal]
final class ListNodes extends ListPanelRecords
{
    protected static string $resource = NodeResource::class;
}
