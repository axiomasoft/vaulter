<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources\NodeShareResource\Pages;

use Filament\Actions\Action;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Filament\Resources\NodeShareResource;
use Vaulter\Filament\Resources\Pages\ListPanelRecords;

#[Internal]
final class ListNodeShares extends ListPanelRecords
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
                ->action(NodeShareResource::createFromData(...)),
        ];
    }
}
