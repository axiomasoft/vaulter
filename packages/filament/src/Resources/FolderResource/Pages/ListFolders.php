<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\FolderResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;
use Vaulter\Filament\Resources\FolderResource;

final class ListFolders extends ListRecords
{
    protected static string $resource = FolderResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
