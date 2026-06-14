<?php

declare(strict_types=1);

namespace DocVault\Filament\Resources\FolderResource\Pages;

use DocVault\Filament\Resources\FolderResource;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;

final class EditFolder extends EditRecord
{
    protected static string $resource = FolderResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
