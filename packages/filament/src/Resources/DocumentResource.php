<?php

declare(strict_types=1);

namespace DocVault\Filament\Resources;

use BackedEnum;
use DocVault\Enums\DocumentType;
use DocVault\Filament\Resources\DocumentResource\Pages\EditDocument;
use DocVault\Filament\Resources\DocumentResource\Pages\ListDocuments;
use DocVault\Models\Document;
use DocVault\Support\Config;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\Page;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;
use UnitEnum;

final class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-document-text';

    protected static string|UnitEnum|null $navigationGroup = 'DocVault';

    protected static ?string $recordTitleAttribute = 'title';

    #[Override]
    public static function getModel(): string
    {
        return Config::documentModel();
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('title')
                ->required()
                ->maxLength(255)
                ->columnSpanFull(),

            Select::make('type')
                ->options(self::typeOptions())
                ->required(),

            TextInput::make('workspace_type')
                ->required()
                ->maxLength(255),

            TextInput::make('workspace_id')
                ->required(),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('type')
                    ->badge()
                    ->sortable(),

                TextColumn::make('workspace_type')
                    ->label('Workspace')
                    ->formatStateUsing(fn (string $state, Document $record): string => $state.' #'.$record->workspace_id)
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                TrashedFilter::make(),
            ])
            ->actions([
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<int, class-string<Page>>
     */
    #[Override]
    public static function getRelations(): array
    {
        return [];
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'edit' => EditDocument::route('/{record}/edit'),
        ];
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    /**
     * @return array<string, string>
     */
    private static function typeOptions(): array
    {
        $options = [];
        foreach (DocumentType::cases() as $type) {
            $options[$type->value] = $type->label();
        }

        return $options;
    }
}
