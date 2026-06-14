<?php

declare(strict_types=1);

namespace DocVault\Filament\Resources\FolderResource\Pages;

use DocVault\Filament\Resources\FolderResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateFolder extends CreateRecord
{
    protected static string $resource = FolderResource::class;
}
