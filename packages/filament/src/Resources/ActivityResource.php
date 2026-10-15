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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Override;
use UnitEnum;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Drive\ListOptions;
use Vaulter\Events\EventCatalog;
use Vaulter\Events\EventTypeContract;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Filament\Resources\ActivityResource\Pages\ListActivities;
use Vaulter\Filament\Resources\ActivityResource\Pages\ViewActivity;
use Vaulter\Filament\Support\PanelActor;
use Vaulter\Models\Activity\Activity;
use Vaulter\Operations\ActivityOperations;
use Vaulter\Operations\DriveContext;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\ValueObjects\Access\Actor;

/**
 * The audit-feed admin surface: a READ-ONLY view of the
 * append-only v_activity log — verb, actor, target and time, filterable by verb and
 * storage. The model is append-only by construction (no updated_at, no soft-delete),
 * so this resource is non-creatable, non-editable and non-deletable: it lists and
 * inspects, never mutates.
 *
 * The model resolves through {@see app(VaulterConfig::class)->model('activity')} for swappability.
 */
#[Internal]
final class ActivityResource extends Resource
{
    protected static ?string $model = Activity::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function getModel(): string
    {
        return app(VaulterConfig::class)->model('activity');
    }

    /**
     * Access-aware listing: narrow to activity rows whose target is a
     * node in the acting admin's grant-reachable candidate set. Every recorded verb
     * targets a node (drive.created targets its root folder — see
     * ActivityRecorder), so `target_type = <node model>` + `target_id IN
     * (candidates)` covers the feed; there is no target-less row a candidate-based
     * narrowing would need to special-case. Empty candidate set (no actor, or an
     * actor with zero grants) ⇒ empty table.
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = app(TenancyBoundary::class)->constrain(parent::getEloquentQuery());

        $actor = PanelActor::current();
        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }
        $ids = [];
        $driveClass = app(VaulterConfig::class)->model('drive');
        foreach (app(TenancyBoundary::class)->constrain($driveClass::query())->lazyById(100) as $drive) {
            $operations = app()->makeWith(ActivityOperations::class, ['context' => DriveContext::for(drive: $drive, actor: Actor::user($actor), correlationId: (string) Str::ulid())]);
            try {
                $cursor = null;
                do {
                    $page = $operations->forDrive(new ListOptions(perPage: 200, cursor: $cursor));
                    foreach ($page as $row) {
                        $ids[] = $row->id;
                    }
                    $cursor = $page->nextCursor;
                } while ($cursor !== null);
            } catch (VaulterException) {
                // Drives without root View contribute no activity.
            }
        }

        return $query->whereKey($ids);
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Event')->schema([
                TextEntry::make('verb')
                    ->badge()
                    ->formatStateUsing(fn (EventTypeContract $state): string => $state->label()),
                TextEntry::make('created_at')->dateTime('d.m.Y H:i:s'),
                TextEntry::make('drive_id')->label('Storage')->placeholder('—'),
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
                    ->formatStateUsing(fn (EventTypeContract $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('actor_type')
                    ->label('Actor')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state === null ? '—' : $state.' #'.$record->actor_id)
                    ->toggleable(),

                TextColumn::make('target_type')
                    ->label('Target')
                    ->formatStateUsing(fn (?string $state, Activity $record): string => $state === null ? '—' : $state.' #'.$record->target_id)
                    ->toggleable(),

                TextColumn::make('drive_id')
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

        foreach (app(EventCatalog::class)->all() as $verb) {
            $options[$verb->value] = $verb->label();
        }

        return $options;
    }
}
