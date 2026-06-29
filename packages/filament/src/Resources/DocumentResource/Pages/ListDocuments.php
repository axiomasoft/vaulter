<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\DocumentResource\Pages;

use Filament\Resources\Pages\ListRecords;
use Vaulter\Filament\Resources\DocumentResource;

final class ListDocuments extends ListRecords
{
    protected static string $resource = DocumentResource::class;
}
