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
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Override;
use UnitEnum;
use Vaulter\Access\Roles\RoleRegistry;
use Vaulter\Configuration\SharingConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Data\Sharing\ShareLinkInput;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Enums\Sharing\ShareAudience;
use Vaulter\Exceptions\Access\MissingActorException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Exceptions\Sharing\ShareLinkTtlExceededException;
use Vaulter\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Filament\Resources\NodeShareResource\Pages\ListNodeShares;
use Vaulter\Filament\Support\PanelActor;
use Vaulter\Models\Drive\Node;
use Vaulter\Models\Sharing\NodeShare;
use Vaulter\Operations\OperationContext;
use Vaulter\Operations\ShareOperations;
use Vaulter\Tenancy\TenancyBoundary;
use Vaulter\ValueObjects\Access\Actor;

/**
 * The bearer share-link admin surface (master plan §2.7 / §3): list, create and
 * revoke the public share-links on a node. The raw token is NEVER stored and NEVER
 * displayed here — only the non-secret `token_prefix` plus the live/expired/revoked
 * status. It is shown exactly ONCE, at mint time, via the {@see ShareOperations}
 * result ({@see MintedShareLink}).
 *
 * Every mutation flows through a CORE Action:
 *   - create → {@see ShareOperations} (mints + hashes the token; raw shown once);
 *   - revoke → {@see ShareOperations} (stamps revoked_at; idempotent).
 * Both require Ability::Share on the node, gated through the ShareOperations. The model
 * resolves through {@see app(VaulterConfig::class)->model('node_share')} for swappability.
 */
final class NodeShareResource extends Resource
{
    protected static ?string $model = NodeShare::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-link';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    protected static ?string $recordTitleAttribute = 'token_prefix';

    #[Override]
    public static function getModel(): string
    {
        return app(VaulterConfig::class)->model('node_share');
    }

    /**
     * Access-aware listing (F-P1.10-01): narrow to share rows whose node_id is in
     * the acting admin's grant-reachable candidate set. Empty candidate set (no
     * actor, or an actor with zero grants) ⇒ empty table.
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = app(TenancyBoundary::class)->constrain(parent::getEloquentQuery(), 'node.storage');

        $ids = [];
        $nodeClass = app(VaulterConfig::class)->model('node');
        foreach (app(TenancyBoundary::class)->constrain($nodeClass::query())->whereNull('trashed_at')->lazyById(100) as $node) {
            try {
                foreach (self::operations($node)->list($node) as $row) {
                    $ids[] = $row->getKey();
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
        return $schema->components(self::shareFormComponents());
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('node_id')
                    ->label('Node')
                    ->sortable(),

                TextColumn::make('token_prefix')
                    ->label('Token prefix')
                    ->formatStateUsing(fn (string $state): string => $state.'…')
                    ->badge(),

                TextColumn::make('role_key')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => ucfirst($state))
                    ->toggleable(),

                TextColumn::make('audience')
                    ->badge()
                    ->formatStateUsing(fn (ShareAudience $state): string => ucfirst($state->value))
                    ->toggleable(),

                TextColumn::make('scope')
                    ->badge()
                    ->formatStateUsing(fn (GrantScope $state): string => $state->label())
                    ->toggleable(),

                TextColumn::make('status')
                    ->state(fn (NodeShare $record): string => self::statusLabel($record))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'Live' => 'success',
                        'Revoked' => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('use_count')
                    ->label('Uses')
                    ->formatStateUsing(fn (int $state, NodeShare $record): string => $record->max_uses === null ? (string) $state : $state.' / '.$record->max_uses)
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
                TernaryFilter::make('revoked')
                    ->label('Revoked')
                    ->placeholder('All')
                    ->trueLabel('Revoked only')
                    ->falseLabel('Live only')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('revoked_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('revoked_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                self::rotateAction(),
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
            'index' => ListNodeShares::route('/'),
        ];
    }

    #[Override]
    public static function canCreate(): bool
    {
        // Created through the header action, which authorizes Share, mints via the
        // core Action and returns the raw token exactly once.
        return false;
    }

    /**
     * The create-share form fields (master plan §2.7). The raw token is NOT a field —
     * it is minted by the Action and surfaced once.
     *
     * @return array<int, Component>
     */
    public static function shareFormComponents(): array
    {
        return [
            Select::make('node_id')
                ->label('Node')
                ->options(fn (): array => self::nodeOptions())
                ->searchable()
                ->required(),

            Select::make('role')
                ->label('Role')
                ->options(self::roleOptions())
                ->default(RoleRegistry::READER)
                ->helperText('A writer link needs an audience that is signed in.')
                ->required(),

            Select::make('audience')
                ->label('Who can open it')
                ->options(self::audienceOptions())
                ->default(ShareAudience::Anyone->value)
                ->required(),

            Select::make('scope')
                ->options(self::scopeOptions())
                ->default(GrantScope::Item->value)
                ->required(),

            TextInput::make('password')
                ->label('Password (optional)')
                ->password()
                ->maxLength(255),

            TextInput::make('max_uses')
                ->label('Max uses (optional)')
                ->numeric()
                ->minValue(1),

            DateTimePicker::make('expires_at')
                ->label('Expires at')
                ->placeholder('Never'),
        ];
    }

    /**
     * Mint a share-link from the create-form data via the core Action and surface the
     * raw token ONCE via a persistent notification. The Share ability on the chosen
     * node is enforced first; a denial surfaces a warning, never a silent mint.
     *
     * @param array<string, mixed> $data
     */
    public static function createFromData(array $data): void
    {
        $node = self::resolveNode($data['node_id'] ?? null);

        if (!$node instanceof Node) {
            self::notifyDenied('The selected node no longer exists.');

            return;
        }

        if (!self::actorCanShare($node)) {
            self::notifyDenied('You may not share this node.');

            return;
        }

        /** @var string $password */
        $password = $data['password'] ?? '';
        /** @var string $expiresAt */
        $expiresAt = $data['expires_at'] ?? '';
        $maxUses = $data['max_uses'] ?? null;

        try {
            $minted = self::operations($node)->create(
                node: $node,
                input: new ShareLinkInput(
                    // Filament validation already supplies enum/string values with these types.
                    role: (string) ($data['role'] ?? RoleRegistry::READER), // @pest-mutate-ignore: RemoveStringCast
                    audience: ShareAudience::from((string) ($data['audience'] ?? ShareAudience::Anyone->value)), // @pest-mutate-ignore: RemoveStringCast
                    scope: GrantScope::from((string) ($data['scope'] ?? GrantScope::Item->value)), // @pest-mutate-ignore: RemoveStringCast
                    expiresAt: $expiresAt === '' ? null : Carbon::parse($expiresAt),
                    password: $password === '' ? null : $password,
                    maxUses: $maxUses === null || $maxUses === '' ? null : (int) $maxUses,
                ),
            );
        } catch (InvalidArgumentException|ShareRoleNotAllowedException|ShareLinkTtlExceededException $exception) {
            // A role the audience cannot carry, the tenant audience without tenancy, an out-of-range budget or expiry.
            self::notifyDenied($exception->getMessage());

            return;
        }

        // The raw token is shown exactly once, here — it is never persisted and never
        // recoverable from the row afterward.
        self::notifyToken('Share link created', $minted->token);
    }

    private static function notifyToken(string $title, string $token): void
    {
        Notification::make()
            ->title($title)
            ->body('Copy it now — it is shown only once: '.app(SharingConfig::class)->urlBase().'/'.$token)
            ->persistent()
            ->success()
            ->send();
    }

    /**
     * Revoke a share-link via the core Action. Gated by Ability::Share on the node;
     * hidden on an already-revoked share.
     */
    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (NodeShare $record): bool => !$record->isRevoked() && self::actorCanShare($record->node))
            ->action(function (NodeShare $record): void {
                self::revokeRecord($record);

                Notification::make()->title('Share link revoked')->success()->send();
            });
    }

    /**
     * Replace a link's token via the core Action. The new raw token is shown once.
     */
    private static function rotateAction(): Action
    {
        return Action::make('rotate')
            ->label('Rotate token')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('The current link stops working at once.')
            ->visible(fn (NodeShare $record): bool => !$record->isRevoked() && self::actorCanShare($record->node))
            ->action(function (NodeShare $record): void {
                self::notifyToken('Share link rotated', self::rotateRecord($record));
            });
    }

    private static function statusLabel(NodeShare $share): string
    {
        if ($share->isRevoked()) {
            return 'Revoked';
        }

        if ($share->isExpired()) {
            return 'Expired';
        }

        if ($share->isExhausted()) {
            return 'Exhausted';
        }

        return 'Live';
    }

    private static function actorCanShare(Node $node): bool
    {
        $actor = PanelActor::current();

        if ($actor === null) {
            return false;
        }

        try {
            self::operations($node)->list($node);

            return true;
        } catch (VaulterException) {
            return false;
        }
    }

    public static function revokeRecord(NodeShare $record): void
    {
        $node = self::resolveNode($record->getRawOriginal('node_id')) ?? throw NodeNotFoundException::forId('missing');
        self::operations($node)->revoke(node: $node, share: $record);
    }

    /** Rotate the record's token; returns the new raw token. */
    public static function rotateRecord(NodeShare $record): string
    {
        $node = self::resolveNode($record->getRawOriginal('node_id')) ?? throw NodeNotFoundException::forId('missing');

        return self::operations($node)->rotate(node: $node, share: $record)->token;
    }

    private static function operations(Node $node): ShareOperations
    {
        $actor = PanelActor::current() ?? throw MissingActorException::required();
        $drive = $node->storage()->first() ?? throw NodeNotFoundException::forId($node->id);

        return app()->makeWith(ShareOperations::class, ['context' => new OperationContext(drive: $drive, actor: Actor::user($actor), correlationId: (string) Str::ulid())]);
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
            ->filter(fn (Node $node): bool => self::actorCanShare($node))
            ->mapWithKeys(fn (Node $node): array => [
                $node->getKey() => sprintf('#%s %s [%s]', $node->getKey(), $node->name === '' ? '(root)' : $node->name, $node->drive_id),
            ])
            ->all();

        return $options;
    }

    private static function notifyDenied(string $body): void
    {
        Notification::make()->title('Share not created')->body($body)->warning()->send();
    }

    /**
     * The roles a link can carry; organizer and owner never. Whether a role fits the
     * chosen audience is decided by the core Action.
     *
     * @return array<string, string>
     */
    private static function roleOptions(): array
    {
        $options = [];

        foreach ([RoleRegistry::PREVIEWER, RoleRegistry::READER, RoleRegistry::COMMENTER, RoleRegistry::WRITER] as $key) {
            $options[$key] = ucfirst($key);
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function audienceOptions(): array
    {
        $options = [];

        foreach (ShareAudience::cases() as $audience) {
            $options[$audience->value] = ucfirst($audience->value);
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
