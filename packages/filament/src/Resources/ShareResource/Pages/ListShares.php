<?php

declare(strict_types=1);

namespace DocVault\Filament\Resources\ShareResource\Pages;

use DocVault\Filament\Resources\ShareResource;
use Filament\Resources\Pages\ListRecords;

final class ListShares extends ListRecords
{
    protected static string $resource = ShareResource::class;
}
