<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\NodeShareResource\Pages;

use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;
use Override;
use Vaulter\Attributes\Internal;
use Vaulter\Filament\Resources\NodeShareResource;

#[Internal]
final class ListNodeShares extends ListRecords
{
    protected static string $resource = NodeShareResource::class;

    /**
     * The "Create share link" header action: collects the form, authorizes Share on
     * the chosen node, mints through the core CreateShareLinkAction and surfaces the
     * raw token exactly once (NodeShareResource::createFromData).
     *
     * @return array<int, Action>
     */
    #[Override]
    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label('Create share link')
                ->icon('heroicon-o-plus')
                ->schema(NodeShareResource::shareFormComponents())
                ->action(function (array $data): void {
                    NodeShareResource::createFromData($data);
                }),
        ];
    }
}
