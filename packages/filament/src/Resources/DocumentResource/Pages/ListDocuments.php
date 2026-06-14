<?php

declare(strict_types=1);

namespace DocVault\Filament\Resources\DocumentResource\Pages;

use DocVault\Filament\Resources\DocumentResource;
use Filament\Resources\Pages\ListRecords;

final class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;
}
