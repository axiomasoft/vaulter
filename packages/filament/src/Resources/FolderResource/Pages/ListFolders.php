<?php

declare(strict_types=1);

namespace DocVault\Filament\Resources\FolderResource\Pages;

use DocVault\Filament\Resources\FolderResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Override;

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
