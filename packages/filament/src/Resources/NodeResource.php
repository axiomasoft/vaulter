<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\Paginator;
use Illuminate\Validation\ValidationException;
use LogicException;
use Override;
use UnitEnum;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DriveHandle;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\NodeKind;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Support\DriveNameRules;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Filament\Resources\Concerns\ConfiguresNavigationGroup;
use Vaulter\Filament\Resources\NodeResource\Pages\ListNodes;
use Vaulter\Filament\Support\PanelDrives;
use Vaulter\Filament\Support\PanelNodes;
use Vaulter\Filament\Support\PanelRecord;
use Vaulter\Filament\Support\PanelRecords;

/**
 * The Drive-node admin surface — the heart of the panel. It lists the unified
 * folder|file|document nodes of every drive the acting admin can open and wires the row
 * actions to the Drive operations (rename/move/trash/restore/purge) through the
 * authorized handle, never a direct Eloquent write.
 *
 * Rows are Filament custom records built from the handles' public listings
 * ({@see PanelNodes}); a row action is visible only when the handle's own abilities for
 * that node allow it. The panel evaluates no grants and applies no Eloquent access
 * scope: what is listed and what is clickable are both decided by core.
 *
 * @phpstan-import-type NodeRow from PanelNodes
 */
#[Internal]
final class NodeResource extends Resource
{
    use ConfiguresNavigationGroup;

    protected static ?string $model = PanelRecord::class;

    protected static ?string $modelLabel = 'node';

    protected static bool $isGloballySearchable = false;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        // Nodes are never created/edited as a flat form: every mutation flows through
        // a Drive operation (rename/move/trash/…), exposed as row actions. The form
        // exists only so a record title can render; it is non-creatable.
        return $schema->components([
            TextInput::make('name')->disabled(),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->records(self::records(...))
            ->paginationMode(PaginationMode::Simple)
            ->columns([
                IconColumn::make('kind')
                    ->label('')
                    ->icon(fn (NodeKind $state): string => self::kindIcon($state)),

                TextColumn::make('name')
                    ->searchable()
                    ->sortable()
                    ->placeholder('(root)'),

                TextColumn::make('kind')
                    ->badge()
                    ->formatStateUsing(fn (NodeKind $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('drive_id')
                    ->label('Storage')
                    ->sortable()
                    ->toggleable(),

                TextColumn::make('parent_id')
                    ->label('Parent')
                    ->placeholder('—')
                    ->toggleable(),

                TextColumn::make('size_bytes')
                    ->label('Size')
                    ->formatStateUsing(fn (int $state): string => PanelRecords::humanBytes($state))
                    ->sortable(),

                TextColumn::make('trashed_at')
                    ->label('Trashed')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('—')
                    ->toggleable()
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->options(self::kindOptions()),

                SelectFilter::make('drive_id')
                    ->label('Storage')
                    ->options(fn (): array => self::storageOptions()),

                TernaryFilter::make('trashed_at')
                    ->label('Trashed')
                    ->placeholder('Live only')
                    ->trueLabel('Trashed only')
                    ->falseLabel('Live only'),
            ])
            ->recordActions([
                ActionGroup::make([
                    self::renameAction(),
                    self::moveAction(),
                    self::trashAction(),
                    self::restoreAction(),
                    self::forceDeleteAction(),
                ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<int, class-string>
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
            'index' => ListNodes::route('/'),
        ];
    }

    #[Override]
    public static function canCreate(): bool
    {
        // Folders/files are created through the Drive Operations, not a
        // flat resource form. The browser-style create lives on the table actions.
        return false;
    }

    /**
     * @param  array<string, mixed>                    $filters
     * @return Paginator<string, array<string, mixed>>
     */
    private static function records(?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage): Paginator
    {
        $rows = collect(PanelNodes::rows());

        $kind = PanelRecords::filterValue($filters, 'kind');
        $drive = PanelRecords::filterValue($filters, 'drive_id');
        $trashed = PanelRecords::filterValue($filters, 'trashed_at');

        $rows = $rows
            ->when($kind !== null, fn ($rows) => $rows->filter(fn (array $row): bool => $row['kind']->value === $kind))
            ->when($drive !== null, fn ($rows) => $rows->filter(fn (array $row): bool => $row['drive_id'] === $drive))
            ->filter(fn (array $row): bool => match ($trashed) {
                '1' => $row['trashed_at'] !== null,
                default => $row['trashed_at'] === null,
            })
            ->when(is_string($search) && $search !== '', fn ($rows) => $rows->filter(fn (array $row): bool => str_contains(mb_strtolower($row['name']), mb_strtolower((string) $search))));

        return PanelRecords::paginate(array_values($rows->all()), $sortColumn, $sortDirection, $page, $recordsPerPage, 'created_at');
    }

    /**
     * Rename through the authorized node operations. Gated by Ability::Edit.
     */
    private static function renameAction(): Action
    {
        return Action::make('rename')
            ->label('Rename')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (array $record): bool => $record['trashed_at'] === null && self::allows(Ability::Edit, $record))
            ->schema([
                TextInput::make('name')
                    ->rules(DriveNameRules::rules())
                    ->validationMessages(DriveNameRules::messages())
                    ->default(fn (array $record): string => self::field($record, 'name')),
            ])
            ->action(function (array $record, array $data): void {
                /** @var string $name */
                $name = $data['name'];

                try {
                    self::driveFor($record)->nodes()->rename(node: self::nodeId($record), name: $name);
                } catch (VaulterException $exception) {
                    self::notifyFailed('Rename failed', $exception->getMessage());

                    return;
                }

                self::notifyDone('Node renamed');
            });
    }

    /**
     * Move through the authorized node operations. Gated by Ability::Move on the source;
     * the destination list offers only folders the handle lets the admin create into, and
     * the operation re-checks Create on the chosen destination. null = the storage root.
     */
    private static function moveAction(): Action
    {
        return Action::make('move')
            ->label('Move')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (array $record): bool => $record['trashed_at'] === null && self::allows(Ability::Move, $record))
            ->schema([
                Select::make('parent_id')
                    ->label('Destination folder')
                    ->placeholder('(storage root)')
                    ->options(fn (array $record): array => self::folderOptions($record))
                    ->searchable(),
            ])
            ->action(function (array $record, array $data, Action $action): void {
                $destination = $data['parent_id'] ?? null;
                if ($destination === '') {
                    $destination = null;
                }
                if ($destination !== null && !is_int($destination) && !is_string($destination)) {
                    throw ValidationException::withMessages(['parent_id' => 'The destination must be a node ID.']);
                }
                try {
                    self::driveFor($record)->nodes()->move(node: self::nodeId($record), destination: $destination);
                } catch (VaulterException $exception) {
                    self::notifyFailed('Move not allowed', $exception->getMessage());
                    $action->halt();
                }

                self::notifyDone('Node moved');
            });
    }

    /**
     * Trash through the authorized node operations. Gated by Ability::Trash.
     */
    private static function trashAction(): Action
    {
        return Action::make('trash')
            ->label('Trash')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (array $record): bool => $record['trashed_at'] === null && self::allows(Ability::Trash, $record))
            ->action(function (array $record): void {
                try {
                    self::driveFor($record)->nodes()->trash(node: self::nodeId($record));
                } catch (VaulterException $exception) {
                    self::notifyFailed('Trash failed', $exception->getMessage());

                    return;
                }

                self::notifyDone('Node trashed');
            });
    }

    /**
     * Restore through the authorized trash operations. Gated by Ability::Restore; only
     * visible on an already-trashed node.
     */
    private static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (array $record): bool => $record['trashed_at'] !== null && self::allows(Ability::Restore, $record))
            ->action(function (array $record): void {
                try {
                    self::driveFor($record)->trash()->restore(node: self::nodeId($record));
                } catch (VaulterException $exception) {
                    self::notifyFailed('Restore failed', $exception->getMessage());

                    return;
                }

                self::notifyDone('Node restored');
            });
    }

    /**
     * Permanent deletion through the authorized trash operations. Gated by
     * Ability::Delete; only visible on a trashed node (purge from trash).
     */
    private static function forceDeleteAction(): Action
    {
        return Action::make('forceDelete')
            ->label('Delete permanently')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (array $record): bool => $record['trashed_at'] !== null && self::allows(Ability::Delete, $record))
            ->action(function (array $record): void {
                try {
                    self::driveFor($record)->trash()->purge(node: self::nodeId($record));
                } catch (VaulterException $exception) {
                    self::notifyFailed('Delete failed', $exception->getMessage());

                    return;
                }

                self::notifyDone('Node permanently deleted');
            });
    }

    /**
     * Whether the handle's abilities for the row allow the action. A row without an
     * evaluated ability map (no acting user) advertises nothing.
     *
     * @param array<mixed> $record
     */
    private static function allows(Ability $ability, array $record): bool
    {
        $abilities = $record['abilities'] ?? [];

        return is_array($abilities) && ($abilities[$ability->value] ?? false) === true;
    }

    /** @param array<mixed> $record */
    private static function nodeId(array $record): string
    {
        $id = $record['id'] ?? null;

        return is_string($id) ? $id : throw new LogicException('A node row carries its id.');
    }

    /** @param array<mixed> $record */
    private static function field(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        return is_string($value) ? $value : '';
    }

    private static function notifyDone(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }

    private static function notifyFailed(string $title, string $body): void
    {
        Notification::make()->title($title)->body($body)->danger()->send();
    }

    /**
     * The selectable destination folders within the node's storage, offered by the
     * handle only where the admin may create. Root is the placeholder (null parent_id).
     *
     * @param  array<mixed>              $record
     * @return array<int|string, string>
     */
    private static function folderOptions(array $record): array
    {
        return PanelNodes::destinations(driveId: (string) $record['drive_id'], exceptId: self::nodeId($record));
    }

    /**
     * The authorized handle of the row's drive. A drive the admin cannot open (or no
     * admin at all) answers like a hidden node, the same 404 the HTTP surface gives.
     *
     * @param array<mixed> $record
     */
    private static function driveFor(array $record): DriveHandle
    {
        return PanelDrives::handle((string) $record['drive_id']) ?? throw NodeNotFoundException::forId(self::nodeId($record));
    }

    /** @return array<int|string, string> */
    private static function storageOptions(): array
    {
        return PanelNodes::driveOptions();
    }

    /**
     * @return array<string, string>
     */
    private static function kindOptions(): array
    {
        $options = [];

        foreach (NodeKind::cases() as $kind) {
            $options[$kind->value] = $kind->label();
        }

        return $options;
    }

    private static function kindIcon(NodeKind $kind): string
    {
        return match ($kind) {
            NodeKind::Folder => 'heroicon-o-folder',
            NodeKind::File => 'heroicon-o-document',
            NodeKind::Document => 'heroicon-o-document-text',
            NodeKind::Shortcut => 'heroicon-o-arrow-top-right-on-square',
        };
    }
}
