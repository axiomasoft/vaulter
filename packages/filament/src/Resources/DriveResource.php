<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Table;
use Illuminate\Pagination\Paginator;
use Override;
use UnitEnum;
use Vaulter\Attributes\Internal;
use Vaulter\Filament\Resources\DriveResource\Pages\ListDrives;
use Vaulter\Filament\Support\PanelDrives;
use Vaulter\Filament\Support\PanelRecord;
use Vaulter\Filament\Support\PanelRecords;

/**
 * The drive admin surface: a read-mostly view of the ownable, polymorphic disk roots —
 * owner, profile, used/quota accounting and status. Drives are provisioned by the
 * DriveProvisioner (get-or-create on a host entity), never minted from the panel, so this
 * resource never creates/edits a row; it lists and inspects.
 *
 * Rows are the snapshots of the drives the acting admin can open ({@see PanelDrives}): the
 * drive entry point decides what is listed, and the panel reads no drive table itself.
 *
 * @phpstan-import-type DriveRow from \Vaulter\Filament\Support\PanelDriveAccess
 */
#[Internal]
final class DriveResource extends Resource
{
    protected static ?string $model = PanelRecord::class;

    protected static ?string $modelLabel = 'drive';

    protected static bool $isGloballySearchable = false;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->records(self::records(...))
            ->paginationMode(PaginationMode::Simple)
            ->columns([
                TextColumn::make('name')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('owner_type')
                    ->label('Owner')
                    ->formatStateUsing(fn (string $state, array $record): string => $state.' #'.self::field($record, 'owner_id'))
                    ->toggleable(),

                TextColumn::make('profile')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('used_bytes')
                    ->label('Used')
                    ->formatStateUsing(fn (int $state): string => PanelRecords::humanBytes($state, '0 B'))
                    ->sortable(),

                TextColumn::make('quota_bytes')
                    ->label('Quota')
                    ->placeholder('∞')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '∞' : PanelRecords::humanBytes($state, '0 B'))
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->recordActions([self::viewAction()])
            ->defaultSort('created_at', 'desc');
    }

    #[Override]
    public static function canCreate(): bool
    {
        // Drives are provisioned by the DriveProvisioner, never from the panel.
        return false;
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListDrives::route('/'),
        ];
    }

    /**
     * @return Paginator<string, array<string, mixed>>
     */
    private static function records(?string $search, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage): Paginator
    {
        $rows = array_values(array_filter(
            PanelDrives::rows(),
            static fn (array $row): bool => is_string($row['name']) && PanelRecords::contains($row['name'], $search),
        ));

        return PanelRecords::paginate($rows, $sortColumn, $sortDirection, $page, $recordsPerPage, 'created_at');
    }

    /** The drive's details, read-only: a modal over the row, since the row is a snapshot with no model to open. */
    private static function viewAction(): Action
    {
        return Action::make('view')
            ->label('View')
            ->icon('heroicon-o-eye')
            ->modalHeading('Drive')
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->fillForm(fn (array $record): array => [
                'name' => self::field($record, 'name'),
                'profile' => self::field($record, 'profile'),
                'status' => self::status($record),
                'owner_type' => self::field($record, 'owner_type'),
                'owner_id' => self::field($record, 'owner_id'),
                'used' => PanelRecords::humanBytes(self::bytes($record, 'used_bytes') ?? 0, '0 B'),
                'quota' => ($quota = self::bytes($record, 'quota_bytes')) === null ? '∞' : PanelRecords::humanBytes($quota, '0 B'),
            ])
            ->schema([
                TextInput::make('name')->disabled(),
                TextInput::make('profile')->disabled(),
                TextInput::make('status')->disabled(),
                TextInput::make('owner_type')->label('Owner type')->disabled(),
                TextInput::make('owner_id')->label('Owner id')->disabled(),
                TextInput::make('used')->disabled(),
                TextInput::make('quota')->disabled(),
            ]);
    }

    /** @param array<mixed> $record */
    private static function field(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<mixed> $record */
    private static function bytes(array $record, string $key): ?int
    {
        $value = $record[$key] ?? null;

        return is_int($value) ? $value : null;
    }

    /** @param array<mixed> $record */
    private static function status(array $record): string
    {
        $status = $record['status'] ?? null;

        return $status instanceof BackedEnum ? (string) $status->value : '';
    }
}
