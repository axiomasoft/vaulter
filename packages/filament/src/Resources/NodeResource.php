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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Override;
use UnitEnum;
use Vaulter\Access\AccessResolver;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Facades\Vaulter;
use Vaulter\Filament\Resources\NodeResource\Pages\ListNodes;
use Vaulter\Filament\Support\PanelActor;
use Vaulter\Models\Drive\Node;
use Vaulter\Repositories\Drive\NodeReadRepository;
use Vaulter\Support\Config;

/**
 * The Drive-node admin surface (master plan §2.2 / §3) — the heart of the panel. It
 * lists the unified folder|file|document nodes with kind/name/size/parent columns,
 * filters by kind / storage / trashed state, and wires the row actions to the CORE
 * Drive Actions (rename/move/trash/restore/forceDelete) through the DriveContext —
 * NEVER a direct Eloquent write.
 *
 * Authorization is the core AccessResolver. The TABLE scopes to the acting admin's
 * grant-reachable CANDIDATE set (P1 #3): {@see getEloquentQuery()} narrows the query
 * through {@see NodeReadRepository::constrainToAccessible()}, the same candidate-SQL
 * the API listing uses — so the two surfaces are CONSISTENT. That narrowing is a
 * deliberate SUPERSET: it hides nodes the admin has no grant-reachability to, but does
 * NOT apply the exact deny-veto / inherit-cutoff in the listing SQL (a node a nearer
 * deny revokes can still appear in the table); that exact listing suppression is
 * DEFERRED to the H.4 materialised effective-grants read-model.
 *
 * Per-ROW ACTIONS remain EXACTLY gated: each row action is gated by the acting admin's
 * {@see Ability} on that node through the AccessResolver (Edit→rename, Move→move,
 * Trash→trash, Restore→restore, Delete→forceDelete), with the full deny-veto applied,
 * so the panel never bypasses the ACL even though the table lists the candidate set.
 * The model resolves through {@see Config::nodeModel()} so a swapped Node is still
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
        return Config::nodeModel();
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        // Nodes are never created/edited as a flat form: every mutation flows through
        // a core Drive Action (rename/move/trash/…), exposed as row actions. The form
        // exists only so a record title can render; it is non-creatable.
        return $schema->components([
            TextInput::make('name')->disabled(),
        ]);
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
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

                TextColumn::make('storage_id')
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

                SelectFilter::make('storage_id')
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
        // Folders/files are created through the Drive Actions (DriveContext), not a
        // flat resource form. The browser-style create lives on the table actions.
        return false;
    }

    #[Override]
    public static function getEloquentQuery(): Builder
    {
        // Trashed nodes carry trashed_at (a soft-trash, not the Eloquent soft-delete);
        // surface both so the Trashed filter can flip between them, but keep the
        // hard-deleted (deleted_at) rows hidden.
        $query = parent::getEloquentQuery()->withoutGlobalScopes([SoftDeletingScope::class]);

        // Access-aware listing (P1 #3): narrow the table to the acting admin's
        // grant-reachable CANDIDATE set when grant-filtering applies (a grant-
        // authoritative driver + 'grants' feature); a no-op for the open drivers. The
        // candidate set is a SUPERSET — it hides nodes with no grant-reachability but
        // does NOT apply the exact deny-veto in the listing SQL (deferred to the H.4
        // read-model); per-ROW ACTIONS stay exactly gated by the AccessResolver. No
        // acting user ⇒ an empty table (a panel never lists nodes for nobody). The
        // repository constrains WITHOUT a trashed-state filter, so the Trashed filter
        // still flips between live and trashed within the accessible set.
        $actor = PanelActor::current();

        if ($actor === null) {
            return $query->whereRaw('1 = 0');
        }

        return app(NodeReadRepository::class)->constrainToAccessible($query, $actor);
    }

    /**
     * Rename → core RenameNodeAction via DriveContext::rename. Gated by Ability::Edit.
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

                Vaulter::forStorage($record->storage)->rename(
                    node: $record,
                    name: $name,
                    actorId: PanelActor::id(),
                );

                self::notifyDone('Node renamed');
            });
    }

    /**
     * Move → core MoveNodeAction via DriveContext::move. Gated by Ability::Move. The
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
            ->action(function (Node $record, array $data): void {
                $into = self::resolveFolder($record, $data['parent_id'] ?? null);

                Vaulter::forStorage($record->storage)->move(
                    node: $record,
                    into: $into,
                    actorId: PanelActor::id(),
                );

                self::notifyDone('Node moved');
            });
    }

    /**
     * Trash → core TrashNodeAction via DriveContext::trash. Gated by Ability::Trash.
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
                Vaulter::forStorage($record->storage)->trash(
                    node: $record,
                    actorId: PanelActor::id(),
                );

                self::notifyDone('Node trashed');
            });
    }

    /**
     * Restore → core RestoreNodeAction via DriveContext::restore. Gated by
     * Ability::Restore; only visible on an already-trashed node.
     */
    private static function restoreAction(): Action
    {
        return Action::make('restore')
            ->label('Restore')
            ->icon('heroicon-o-arrow-uturn-left')
            ->visible(fn (Node $record): bool => $record->trashed_at !== null && self::actorCan(Ability::Restore, $record))
            ->action(function (Node $record): void {
                Vaulter::forStorage($record->storage)->restore(
                    node: $record,
                    actorId: PanelActor::id(),
                );

                self::notifyDone('Node restored');
            });
    }

    /**
     * Force-delete → core ForceDeleteNodeAction via DriveContext::forceDelete. Gated
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
                Vaulter::forStorage($record->storage)->forceDelete(
                    node: $record,
                    actorId: PanelActor::id(),
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

        return app(AccessResolver::class)->can($actor, $ability, $node);
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
        $model = Config::nodeModel();
        $keyName = (new $model)->getKeyName();

        /** @var array<int|string, string> $options */
        $options = $model::query()
            ->where('storage_id', $record->storage_id)
            ->where('kind', NodeKind::Folder->value)
            ->whereNull('trashed_at')
            ->whereKeyNot($record->getKey())
            ->orderBy('name')
            ->pluck('name', $keyName)
            ->map(fn (string $name): string => $name === '' ? '(root)' : $name)
            ->all();

        return $options;
    }

    /**
     * Resolve the chosen destination folder model (null = storage root).
     */
    private static function resolveFolder(Node $record, int|string|null $parentId): ?Node
    {
        if ($parentId === null || $parentId === '') {
            return null;
        }

        $model = Config::nodeModel();

        /** @var Node|null $folder */
        $folder = $model::query()
            ->where('storage_id', $record->storage_id)
            ->whereKey($parentId)
            ->first();

        return $folder;
    }

    /**
     * @return array<int|string, string>
     */
    private static function storageOptions(): array
    {
        $model = Config::storageModel();

        /** @var array<int|string, string> $options */
        $options = $model::query()
            ->orderBy('name')
            ->pluck('name', $model::query()->getModel()->getKeyName())
            ->all();

        return $options;
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
