<?php

declare(strict_types=1);

namespace Vaulter\Filament\Resources;

use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\PageRegistration;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Override;
use UnitEnum;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Models\Access\NodeGrant;
use Vaulter\Drive\Operations\AccessOperations;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Filament\Resources\NodeGrantResource\Pages\ListNodeGrants;
use Vaulter\Filament\Support\PanelActor;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Support\Identity;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\ValueObjects\Access\Actor;

/**
 * The per-node ACL admin surface: list, create and revoke
 * the node grants the {@see AccessOperations} folds into effective permissions. A
 * grant gives a subject (type + id) a role on a node, with an item|subtree scope
 * and an optional read-time expiry; grants are positive only.
 *
 * Every mutation flows through a CORE Action — never a direct Eloquent write:
 *   - create → {@see AccessOperations} (idempotent upsert on the grant key);
 *   - revoke → {@see AccessOperations} (drops every grant a subject holds).
 * Both require Ability::Manage on the target node, gated through the AccessOperations,
 * so the panel never bypasses the ACL. The model resolves through
 * {@see app(VaulterConfig::class)->model('grant')} for swappability.
 */
#[Internal]
final class NodeGrantResource extends Resource
{
    protected static ?string $model = NodeGrant::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function getModel(): string
    {
        return app(VaulterConfig::class)->model('grant');
    }

    /**
     * Access-aware listing: narrow to grant rows whose node_id is in
     * the acting admin's grant-reachable candidate set (the same set
     * {@see NodeResource::getEloquentQuery()} narrows the node table to). Empty
     * candidate set (no actor, or an actor with zero grants) ⇒ empty table.
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = app(TenancyBoundary::class)->constrain(parent::getEloquentQuery(), 'node.storage');

        $ids = [];
        $nodeClass = app(VaulterConfig::class)->model('node');
        foreach (app(TenancyBoundary::class)->constrain($nodeClass::query())->whereNull('trashed_at')->lazyById(100) as $node) {
            try {
                foreach (self::operations($node)->grants(Identity::stringify($node->getKey())) as $row) {
                    $ids[] = $row->id;
                }
            } catch (VaulterException) {
                // Hidden nodes and disabled features contribute no rows.
            }
        }

        return $query->whereKey($ids);
    }

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::grantFormComponents());
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('node_id')
                    ->label('Node')
                    ->sortable(),

                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (string $state, NodeGrant $record): string => $state.' #'.$record->subject_id)
                    ->searchable(),

                TextColumn::make('role_key')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state, NodeGrant $record): string => ucfirst($state).' v'.$record->role_version)
                    ->sortable(),

                TextColumn::make('scope')
                    ->badge()
                    ->formatStateUsing(fn (GrantScope $state): string => $state->label())
                    ->toggleable(),

                TextColumn::make('expires_at')
                    ->dateTime('d.m.Y H:i')
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('created_at')
                    ->dateTime('d.m.Y H:i')
                    ->sortable()
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('role_key')
                    ->label('Role')
                    ->options(self::roleOptions()),
            ])
            ->recordActions([
                self::revokeAction(),
            ])
            ->defaultSort('created_at', 'desc');
    }

    /**
     * @return array<string, PageRegistration>
     */
    #[Override]
    public static function getPages(): array
    {
        return [
            'index' => ListNodeGrants::route('/'),
        ];
    }

    #[Override]
    public static function canCreate(): bool
    {
        // Created through the header action (which authorizes Manage on the chosen
        // node and calls the core Action), not a flat resource create page.
        return false;
    }

    /**
     * The grant form fields shared by the create header action.
     *
     * @return array<int, Component>
     */
    public static function grantFormComponents(): array
    {
        return [
            Select::make('node_id')
                ->label('Node')
                ->options(fn (): array => self::nodeOptions())
                ->searchable()
                ->required(),

            TextInput::make('subject_type')
                ->label('Subject type')
                ->helperText('A registered morph alias (e.g. user, role, team).')
                ->required()
                ->maxLength(255),

            TextInput::make('subject_id')
                ->label('Subject id')
                ->required(),

            Select::make('role')
                ->options(self::roleOptions())
                ->default(RoleRegistry::READER)
                ->required(),

            Select::make('scope')
                ->options(self::scopeOptions())
                ->default(GrantScope::Subtree->value)
                ->required(),

            DateTimePicker::make('expires_at')
                ->label('Expires at')
                ->placeholder('Never'),
        ];
    }

    /**
     * Persist a grant from the validated create-form data via the core Action. The
     * Manage ability on the chosen node is enforced here; a denial surfaces as a
     * warning notification rather than a silent write.
     *
     * @param array<string, mixed> $data
     */
    public static function grantFromData(array $data): void
    {
        $node = self::resolveNode($data['node_id'] ?? null);

        if (!$node instanceof Node) {
            self::notifyDenied('The selected node no longer exists.');

            return;
        }

        if (!self::actorCanManage($node)) {
            self::notifyDenied('You may not manage access on this node.');

            return;
        }

        /** @var string $expiresAt */
        $expiresAt = $data['expires_at'] ?? '';

        self::operations($node)->grant(
            node: Identity::stringify($node->getKey()),
            grant: new GrantInput(
                subject: new Subject(
                    // Filament validation already supplies these enum/string fields with their declared types.
                    type: (string) $data['subject_type'], // @pest-mutate-ignore: RemoveStringCast
                    id: self::scalarId($data['subject_id'] ?? ''),
                ),
                role: (string) $data['role'], // @pest-mutate-ignore: RemoveStringCast
                scope: GrantScope::from((string) ($data['scope'] ?? GrantScope::Subtree->value)), // @pest-mutate-ignore: RemoveStringCast
                expiresAt: $expiresAt === '' ? null : Carbon::parse($expiresAt),
            ),
        );

        Notification::make()->title('Grant saved')->success()->send();
    }

    /**
     * Revoke every grant a subject holds on the row's node via the core Action.
     * Gated by Ability::Manage on the node.
     */
    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (NodeGrant $record): bool => self::actorCanManage($record->node))
            ->action(function (NodeGrant $record): void {
                self::revokeRecord($record);

                Notification::make()->title('Grant revoked')->success()->send();
            });
    }

    /**
     * Whether the acting admin may manage grants on the node. No acting
     * user ⇒ false.
     */
    private static function actorCanManage(Node $node): bool
    {
        $actor = PanelActor::current();

        if ($actor === null) {
            return false;
        }

        try {
            self::operations($node)->grants(Identity::stringify($node->getKey()));

            return true;
        } catch (VaulterException) {
            return false;
        }
    }

    public static function revokeRecord(NodeGrant $record): void
    {
        $node = self::resolveNode($record->getRawOriginal('node_id')) ?? throw NodeNotFoundException::forId('missing');
        self::operations($node)->revoke(node: Identity::stringify($node->getKey()), grant: Identity::stringify($record->getKey()));
    }

    private static function operations(Node $node): AccessOperations
    {
        $actor = PanelActor::current() ?? throw MissingActorException::required();
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);

        return app()->makeWith(AccessOperations::class, ['context' => DriveContext::for(drive: $drive, actor: Actor::user($actor), correlationId: (string) Str::ulid())]);
    }

    private static function resolveNode(int|string|null $id): ?Node
    {
        // Both values also produce `find() === null`; this fast path avoids a needless query.
        if ($id === null || $id === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd,EmptyStringToNotEmpty
            return null;
        }

        $model = app(VaulterConfig::class)->model('node');

        return app(TenancyBoundary::class)->constrain($model::query())->find($id);
    }

    /**
     * @return array<int|string, string>
     */
    private static function nodeOptions(): array
    {
        $model = app(VaulterConfig::class)->model('node');

        /** @var array<int|string, string> $options */
        $options = app(TenancyBoundary::class)->constrain($model::query())
            ->whereNull('trashed_at')
            ->orderBy('drive_id')
            ->orderBy('name')
            ->get()
            ->filter(fn (Node $node): bool => self::actorCanManage($node))
            ->mapWithKeys(fn (Node $node): array => [
                $node->getKey() => sprintf('#%s %s [%s]', $node->getKey(), $node->name === '' ? '(root)' : $node->name, $node->drive_id),
            ])
            ->all();

        return $options;
    }

    private static function scalarId(mixed $value): int|string
    {
        if (is_int($value)) { // @pest-mutate-ignore: IfNegated
            return $value; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        $string = (string) $value;

        return ctype_digit($string) ? (int) $string : $string;
    }

    private static function notifyDenied(string $body): void
    {
        Notification::make()->title('Grant not saved')->body($body)->warning()->send();
    }

    /**
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        $options = [];

        foreach ((new RoleRegistry)->all() as $role) {
            $options[$role->key] = ucfirst($role->key);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function scopeOptions(): array
    {
        $options = [];

        foreach (GrantScope::cases() as $scope) {
            $options[$scope->value] = $scope->label();
        }

        return $options;
    }
}
