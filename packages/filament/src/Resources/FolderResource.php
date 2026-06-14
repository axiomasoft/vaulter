<?php

declare(strict_types=1);

namespace DocVault\Filament\Resources;

use BackedEnum;
use DocVault\Filament\Resources\FolderResource\Pages\CreateFolder;
use DocVault\Filament\Resources\FolderResource\Pages\EditFolder;
use DocVault\Filament\Resources\FolderResource\Pages\ListFolders;
use DocVault\Models\DocumentFolder;
use DocVault\Support\Config;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Override;
use UnitEnum;

final class FolderResource extends Resource
{
    protected static ?string $model = DocumentFolder::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-folder';

    protected static string|UnitEnum|null $navigationGroup = 'DocVault';

    protected static ?string $recordTitleAttribute = 'name';

    #[Override]
    public static function getModel(): string
    {
        return Config::folderModel();
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            TextInput::make('workspace_type')
                ->required()
                ->maxLength(255),

            TextInput::make('workspace_id')
                ->required(),

            TextInput::make('parent_id')
                ->numeric()
                ->nullable(),

            TextInput::make('order')
                ->integer()
                ->default(0),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('workspace_type')
                    ->label('Workspace')
                    ->formatStateUsing(fn (string $state, DocumentFolder $record): string => $state.' #'.$record->workspace_id)
                    ->toggleable(),

                TextColumn::make('parent_id')
                    ->label('Parent')
                    ->placeholder('—'),

                TextColumn::make('order')
                    ->sortable(),
            ])
            ->actions([
                DeleteAction::make(),
            ])
            ->defaultSort('order');
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListFolders::route('/'),
            'create' => CreateFolder::route('/create'),
            'edit' => EditFolder::route('/{record}/edit'),
        ];
    }
}
