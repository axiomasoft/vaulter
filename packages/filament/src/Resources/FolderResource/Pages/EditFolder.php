<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\FolderResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Override;
use Vaulter\Filament\Resources\FolderResource;

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
