<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\FolderResource\Pages;

use Filament\Resources\Pages\CreateRecord;
use Vaulter\Filament\Resources\FolderResource;

final class CreateFolder extends CreateRecord
{
    protected static string $resource = FolderResource::class;
}
