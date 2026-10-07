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
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;
use Override;
use UnitEnum;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Contracts\Drive\DriveHandle;
use Vaulter\Drive\Queries\NodeBrowserQuery;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Access\PermissionDeniedException;
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Resources\NodeResource\Pages\ListNodes;
use Vaulter\Filament\Support\PanelActor;
use Vaulter\Models\Drive\Node;

/**
 * The Drive-node admin surface (master plan §2.2 / §3) — the heart of the panel. It
 * lists the unified folder|file|document nodes with kind/name/size/parent columns,
 * filters by kind / storage / trashed state, and wires the row actions to the canonical
 * Drive Operations (rename/move/trash/restore/purge) through the authorized handle —
 * NEVER a direct Eloquent write.
 *
 * Table candidates use the grant-authoritative SQL prefilter, then ListNodes applies
 * exact batch View evaluation before display. Simple pagination avoids leaking totals.
 *
 * Per-ROW ACTIONS remain EXACTLY gated: each row action is gated by the acting admin's
 * {@see Ability} on that node through the AccessResolver (Edit→rename, Move→move,
 * Trash→trash, Restore→restore, Delete→forceDelete), with the full deny-veto applied,
 * so the panel never bypasses the ACL even though the table lists the candidate set.
 * The model resolves through {@see app(VaulterConfig::class)->model('node')} so a swapped Node is still
 * governed here.
 */
final class NodeResource extends Resource
{
    protected static ?string $model = Node::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-rectangle-stack';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    protected static ?string $recordTitleAttribute = 'name';

    #[Override]
    public static function getModel(): string
    {
        return app(VaulterConfig::class)->model('node');
    }

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
                    ->formatStateUsing(fn (int $state): string => self::humanBytes($state))
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
                    ->falseLabel('Live only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('trashed_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('trashed_at'),
                        blank: fn (Builder $query): Builder => $query->whereNull('trashed_at'),
                    ),
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

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        // Trashed nodes carry trashed_at (a soft-trash, not the Eloquent soft-delete);
        // surface both so the Trashed filter can flip between them, but keep the
        // hard-deleted (deleted_at) rows hidden.
        $query = parent::getEloquentQuery();

        // SQL narrows candidates only; ListNodes applies the exact ACL page filter.
        $actor = PanelActor::current();

        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }

        return app(NodeBrowserQuery::class)->constrainToAccessible($query, $actor);
    }

    /**
     * Rename through the authorized node operations. Gated by Ability::Edit.
     */
    private static function renameAction(): Action
    {
        return Action::make('rename')
            ->label('Rename')
            ->icon('heroicon-o-pencil-square')
            ->visible(fn (Node $record): bool => $record->trashed_at === null && self::actorCan(Ability::Edit, $record))
            ->schema([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    ->default(fn (Node $record): string => $record->name),
            ])
            ->action(function (Node $record, array $data): void {
                /** @var string $name */
                $name = $data['name'];

                self::driveFor($record)->nodes()->rename(
                    node: $record,
                    name: $name,
                );

                self::notifyDone('Node renamed');
            });
    }

    /**
     * Move through the authorized node operations. Gated by Ability::Move. The
     * destination is picked from the storage's folder nodes; null = the storage root.
     */
    private static function moveAction(): Action
    {
        return Action::make('move')
            ->label('Move')
            ->icon('heroicon-o-arrows-right-left')
            ->visible(fn (Node $record): bool => $record->trashed_at === null && self::actorCan(Ability::Move, $record))
            ->schema([
                Select::make('parent_id')
                    ->label('Destination folder')
                    ->placeholder('(storage root)')
                    ->options(fn (Node $record): array => self::folderOptions($record))
                    ->searchable(),
            ])
            ->action(function (Node $record, array $data, Action $action): void {
                $destination = $data['parent_id'] ?? null;
                if ($destination === '') {
                    $destination = null;
                }
                if ($destination !== null && !is_int($destination) && !is_string($destination)) {
                    throw ValidationException::withMessages(['parent_id' => 'The destination must be a node ID.']);
                }
                try {
                    self::driveFor($record)->nodes()->move(
                        node: $record,
                        destination: $destination,
                    );
                } catch (PermissionDeniedException) {
                    Notification::make()->title('Move not allowed')->danger()->send();
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
            ->visible(fn (Node $record): bool => $record->trashed_at === null && self::actorCan(Ability::Trash, $record))
            ->action(function (Node $record): void {
                self::driveFor($record)->nodes()->trash(
                    node: $record,
                );

                self::notifyDone('Node trashed');
            });
    }

    /**
     * Restore through the authorized trash operations. Gated by
     * Ability::Restore; only visible on an already-trashed node.
     */
    private static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (Node $record): bool => $record->trashed_at !== null && self::actorCan(Ability::Restore, $record))
            ->action(function (Node $record): void {
                self::driveFor($record)->trash()->restore(
                    node: $record,
                );

                self::notifyDone('Node restored');
            });
    }

    /**
     * Permanent deletion through the authorized trash operations. Gated
     * by Ability::Delete; only visible on a trashed node (purge from trash).
     */
    private static function forceDeleteAction(): Action
    {
        return Action::make('forceDelete')
            ->label('Delete permanently')
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (Node $record): bool => $record->trashed_at !== null && self::actorCan(Ability::Delete, $record))
            ->action(function (Node $record): void {
                self::driveFor($record)->trash()->purge(
                    node: $record,
                );

                self::notifyDone('Node permanently deleted');
            });
    }

    /**
     * Whether the acting admin holds the ability on the node (core AccessResolver).
     * No acting user ⇒ false, so an unauthenticated panel never advertises an action.
     */
    private static function actorCan(Ability $ability, Node $node): bool
    {
        $actor = PanelActor::current();

        if ($actor === null) {
            return false;
        }

        return app(NodeBrowserQuery::class)->can(self::driveFor($node), $ability, $node);
    }

    private static function notifyDone(string $title): void
    {
        Notification::make()->title($title)->success()->send();
    }

    /**
     * The selectable destination folders within the node's storage (excluding the
     * node itself, since a node cannot move into itself). Root is offered as the
     * placeholder (null parent_id).
     *
     * @return array<int|string, string>
     */
    private static function folderOptions(Node $record): array
    {
        if (PanelActor::current() === null) {
            return [];
        }

        return app(NodeBrowserQuery::class)->folderOptions(handle: self::driveFor($record), source: $record);
    }

    private static function driveFor(Node $node): DriveHandle
    {
        $actor = PanelActor::current() ?? throw MissingActorException::required();

        return Vaulter::drive(app(NodeBrowserQuery::class)->driveForNode($node))->actingAs($actor);
    }

    /** @return array<int|string, string> */
    private static function storageOptions(): array
    {
        return app(NodeBrowserQuery::class)->driveOptions();
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

    private static function humanBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '—';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $power = (int) min(floor(log($bytes, 1024)), count($units) - 1);
        $value = $bytes / (1024 ** $power);

        return sprintf('%s %s', rtrim(rtrim(number_format($value, 2), '0'), '.'), $units[$power]);
    }
}
