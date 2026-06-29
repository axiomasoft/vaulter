<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources;

use BackedEnum;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;
use UnitEnum;
use Vaulter\Enums\PermissionLevel;
use Vaulter\Filament\Resources\ShareResource\Pages\ListShares;
use Vaulter\Models\DocumentShare;
use Vaulter\Support\Config;

final class ShareResource extends Resource
{
    protected static ?string $model = DocumentShare::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-link';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    protected static ?string $recordTitleAttribute = 'token';

    #[Override]
    public static function getModel(): string
    {
        return Config::shareModel();
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        // Share links are created through the API; the panel only lists/revokes them.
        return $schema->components([
            TextInput::make('token')->disabled(),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('document_id')
                    ->label('Document')
                    ->sortable(),

                TextColumn::make('permission')
                    ->badge()
                    ->formatStateUsing(fn (PermissionLevel $state): string => $state->value),

                TextColumn::make('token')
                    ->limit(16)
                    ->toggleable(),

                TextColumn::make('expires_at')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->actions([
                DeleteAction::make()->label('Revoke'),
            ])
            ->defaultSort('created_at', 'desc');
    }

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListShares::route('/'),
        ];
    }
}
