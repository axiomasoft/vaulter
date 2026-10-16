<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\NodeGrantResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Override;
use Vaulter\Attributes\Internal;
use Vaulter\Filament\Resources\NodeGrantResource;

#[Internal]
final class ListNodeGrants extends ListRecords
{
    protected static string $resource = NodeGrantResource::class;

    /**
     * The "Create grant" header action: it collects the grant form, authorizes
     * Share on the chosen node and persists through the core GrantNodeAccessAction
     * (NodeGrantResource::grantFromData) — never a direct Eloquent write.
     *
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create grant')
                ->icon('heroicon-o-plus')
                ->schema(NodeGrantResource::grantFormComponents())
                ->action(function (array $data): void {
                    NodeGrantResource::grantFromData($data);
                }),
        ];
    }
}
