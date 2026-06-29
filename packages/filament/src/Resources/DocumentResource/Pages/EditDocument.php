<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\DocumentResource\Pages;

use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;
use Override;
use Vaulter\Filament\Resources\DocumentResource;

final class EditDocument extends EditRecord
{
    protected static string $resource = DocumentResource::class;

    /**
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
            RestoreAction::make(),
            ForceDeleteAction::make(),
        ];
    }
}
