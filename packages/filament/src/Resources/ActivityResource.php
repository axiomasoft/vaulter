<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\Paginator;
use Override;
use UnitEnum;
use Vaulter\Attributes\Internal;
use Vaulter\Events\EventCatalog;
use Vaulter\Filament\Resources\ActivityResource\Pages\ListActivities;
use Vaulter\Filament\Resources\Concerns\ConfiguresNavigationGroup;
use Vaulter\Filament\Support\PanelActivity;
use Vaulter\Filament\Support\PanelRecord;
use Vaulter\Filament\Support\PanelRecords;

/**
 * The audit-feed admin surface: a READ-ONLY view of the append-only activity log — verb,
 * actor, target and time, filterable by verb. It lists and inspects, never mutates.
 *
 * Rows are the entries each drive the acting admin can open returns from its own activity
 * operations ({@see PanelActivity}): the drive's visibility projection decides which entries
 * and which fields are shown, and the panel reads no activity table itself.
 */
#[Internal]
final class ActivityResource extends Resource
{
    use ConfiguresNavigationGroup;

    protected static ?string $model = PanelRecord::class;

    protected static ?string $modelLabel = 'activity';

    protected static ?string $pluralModelLabel = 'activities';

    protected static bool $isGloballySearchable = false;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-clock';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->records(self::records(...))
            ->paginationMode(PaginationMode::Simple)
            ->columns([
                TextColumn::make('verb_label')
                    ->label('Verb')
                    ->badge()
                    ->sortable(),

                TextColumn::make('actor_type')
                    ->label('Actor')
                    ->formatStateUsing(fn (?string $state, array $record): string => $state === null ? '—' : $state.' #'.self::field($record, 'actor_id'))
                    ->toggleable(),

                TextColumn::make('target_type')
                    ->label('Target')
                    ->formatStateUsing(fn (?string $state, array $record): string => $state === null ? '—' : $state.' #'.self::field($record, 'target_id'))
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
            ->recordActions([self::viewAction()])
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
     * @param  array<string, mixed>                    $filters
     * @return Paginator<string, array<string, mixed>>
     */
    private static function records(?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage): Paginator
    {
        $verb = PanelRecords::filterValue($filters, 'verb');
        $rows = array_values(array_filter(
            PanelActivity::rows(),
            static fn (array $row): bool => $verb === null || $row['verb'] === $verb,
        ));

        return PanelRecords::paginate($rows, $sortColumn === 'verb' ? 'verb_label' : $sortColumn, $sortDirection, $page, $recordsPerPage, 'created_at');
    }

    /** The entry's details, read-only: a modal over the row, since the row is a snapshot with no model to open. */
    private static function viewAction(): Action
    {
        return Action::make('view')
            ->label('View')
            ->icon('heroicon-o-eye')
            ->modalHeading('Activity')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->fillForm(fn (array $record): array => [
                'verb' => self::field($record, 'verb_label'),
                'created_at' => self::field($record, 'created_at'),
                'drive_id' => self::field($record, 'drive_id'),
                'actor_type' => self::field($record, 'actor_type'),
                'actor_id' => self::field($record, 'actor_id'),
                'target_type' => self::field($record, 'target_type'),
                'target_id' => self::field($record, 'target_id'),
                'ip' => self::field($record, 'ip'),
                'user_agent' => self::field($record, 'user_agent'),
                'request_id' => self::field($record, 'request_id'),
                'context' => self::context($record),
            ])
            ->schema([
                TextInput::make('verb')->disabled(),
                TextInput::make('created_at')->label('When')->disabled(),
                TextInput::make('drive_id')->label('Storage')->disabled(),
                TextInput::make('actor_type')->label('Actor type')->disabled(),
                TextInput::make('actor_id')->label('Actor id')->disabled(),
                TextInput::make('target_type')->label('Target type')->disabled(),
                TextInput::make('target_id')->label('Target id')->disabled(),
                TextInput::make('ip')->disabled(),
                TextInput::make('user_agent')->disabled(),
                TextInput::make('request_id')->disabled(),
                KeyValue::make('context')->disabled(),
            ]);
    }

    /** @param array<mixed> $record */
    private static function field(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        return match (true) {
            $value instanceof \DateTimeInterface => $value->format('d.m.Y H:i:s'),
            is_scalar($value) => (string) $value,
            default => '',
        };
    }

    /**
     * @param  array<mixed>          $record
     * @return array<string, string>
     */
    private static function context(array $record): array
    {
        $context = $record['context'] ?? [];
        $pairs = [];

        foreach (is_array($context) ? $context : [] as $key => $value) {
            $pairs[(string) $key] = is_scalar($value) ? (string) $value : (json_encode($value) ?: '');
        }

        return $pairs;
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
