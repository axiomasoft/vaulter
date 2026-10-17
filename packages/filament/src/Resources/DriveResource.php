<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources;

use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Override;
use UnitEnum;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Models\Drive;
use Vaulter\Filament\Resources\DriveResource\Pages\ListDrives;
use Vaulter\Filament\Resources\DriveResource\Pages\ViewDrive;
use Vaulter\Filament\Support\PanelDrives;

/**
 * The drive admin surface: a read-mostly view of
 * the ownable, polymorphic disk roots — owner, disk, used/quota accounting and
 * status. Drives are provisioned by the DriveProvisioner (get-or-create on a
 * host entity), never minted from the panel, so this resource never creates/edits a
 * row; it lists and inspects.
 *
 * The model resolves through {@see app(VaulterConfig::class)->model('drive')} so a host that swaps the
 * Drive model still gets governed here.
 */
#[Internal]
final class DriveResource extends Resource
{
    protected static ?string $model = Drive::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-circle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    protected static ?string $recordTitleAttribute = 'name';

    #[Override]
    public static function getModel(): string
    {
        return app(VaulterConfig::class)->model('drive');
    }

    /**
     * Access-aware listing: the rows are the drives whose handle opens for the acting
     * admin ({@see PanelDrives}); no actor or no openable drive means an empty table.
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->whereKey(PanelDrives::ids());
    }

    #[Override]
    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Drive')->schema([
                TextEntry::make('name'),
                TextEntry::make('profile')->badge(),
                TextEntry::make('disk'),
                TextEntry::make('storage_type')->label('Type'),
                TextEntry::make('status')->badge(),
            ])->columns(2),

            Section::make('Owner')->schema([
                TextEntry::make('owner_type')->label('Type'),
                TextEntry::make('owner_id')->label('Id'),
            ])->columns(2),

            Section::make('Usage')->schema([
                TextEntry::make('used_bytes')
                    ->label('Used')
                    ->formatStateUsing(fn (int $state): string => self::humanBytes($state)),
                TextEntry::make('quota_bytes')
                    ->label('Quota')
                    ->placeholder('Unlimited')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '∞' : self::humanBytes($state)),
            ])->columns(2),
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

                TextColumn::make('owner_type')
                    ->label('Owner')
                    ->formatStateUsing(fn (string $state, Drive $record): string => $state.' #'.$record->owner_id)
                    ->toggleable(),

                TextColumn::make('disk')
                    ->badge()
                    ->toggleable(),

                TextColumn::make('used_bytes')
                    ->label('Used')
                    ->formatStateUsing(fn (int $state): string => self::humanBytes($state))
                    ->sortable(),

                TextColumn::make('quota_bytes')
                    ->label('Quota')
                    ->placeholder('∞')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '∞' : self::humanBytes($state))
                    ->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
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
            'view' => ViewDrive::route('/{record}'),
        ];
    }

    /**
     * A compact, dependency-free human byte size for the usage columns.
     */
    private static function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = (int) min(floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf('%s %s', rtrim(rtrim(number_format($value, 2), '0'), '.'), $units[$power]);
    }
}
