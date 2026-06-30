<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources;

use BackedEnum;
use Filament\Infolists\Components\KeyValueEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Override;
use UnitEnum;
use Vaulter\Enums\Activity\ActivityVerb;
use Vaulter\Filament\Resources\ActivityResource\Pages\ListActivities;
use Vaulter\Filament\Resources\ActivityResource\Pages\ViewActivity;
use Vaulter\Models\Activity\Activity;
use Vaulter\Support\Config;

/**
 * The audit-feed admin surface (master plan §2.9 / §3): a READ-ONLY view of the
 * append-only v_activity log — verb, actor, target and time, filterable by verb and
 * storage. The model is append-only by construction (no updated_at, no soft-delete),
 * so this resource is non-creatable, non-editable and non-deletable: it lists and
 * inspects, never mutates.
 *
 * The model resolves through {@see Config::activityModel()} for swappability.
 */
final class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function getModel(): string
    {
        return Config::activityModel();
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')->schema([
                TextEntry::make('verb')
                    ->badge()
                    ->formatStateUsing(fn (ActivityVerb $state): string => $state->label()),
                TextEntry::make('created_at')->dateTime('d.m.Y H:i:s'),
                TextEntry::make('storage_id')->label('Storage')->placeholder('—'),
            ])->columns(3),

            Section::make('Actor')->schema([
                TextEntry::make('actor_type')->label('Type')->placeholder('—'),
                TextEntry::make('actor_id')->label('Id')->placeholder('—'),
            ])->columns(2),

            Section::make('Target')->schema([
                TextEntry::make('target_type')->label('Type')->placeholder('—'),
                TextEntry::make('target_id')->label('Id')->placeholder('—'),
            ])->columns(2),

            Section::make('Request')->schema([
                TextEntry::make('ip')->placeholder('—'),
                TextEntry::make('user_agent')->placeholder('—'),
                TextEntry::make('request_id')->placeholder('—'),
                KeyValueEntry::make('context')->columnSpanFull(),
            ])->columns(3),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('verb')
                    ->badge()
                    ->formatStateUsing(fn (ActivityVerb $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('actor_type')
                    ->label('Actor')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state === null ? '—' : $state.' #'.$record->actor_id)
                    ->toggleable(),

                TextColumn::make('target_type')
                    ->label('Target')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state === null ? '—' : $state.' #'.$record->target_id)
                    ->toggleable(),

                TextColumn::make('storage_id')
                    ->label('Storage')
                    ->placeholder('—')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label('When')
                    ->dateTime('d.m.Y H:i:s')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('verb')
                    ->options(self::verbOptions()),
            ])
            ->recordActions([])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListActivities::route('/'),
            'view' => ViewActivity::route('/{record}'),
        ];
    }

    #[Override]
    public static function canCreate(): bool
    {
        return false;
    }

    #[Override]
    public static function canEdit(Model $record): bool
    {
        return false;
    }

    #[Override]
    public static function canDelete(Model $record): bool
    {
        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function verbOptions(): array
    {
        $options = [];

        foreach (ActivityVerb::cases() as $verb) {
            $options[$verb->value] = $verb->label();
        }

        return $options;
    }
}
