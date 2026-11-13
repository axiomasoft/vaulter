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
use Filament\Tables\Enums\PaginationMode;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Override;
use UnitEnum;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Configuration\SharingConfig;
use Vaulter\Drive\Contracts\Sharing\ShareOperations;
use Vaulter\Drive\Data\Sharing\ShareLinkInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Enums\Sharing\ShareAudience;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Sharing\ShareLinkTtlExceededException;
use Vaulter\Drive\Exceptions\Sharing\ShareRoleNotAllowedException;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Filament\Resources\Concerns\ConfiguresNavigationGroup;
use Vaulter\Filament\Resources\NodeShareResource\Pages\ListNodeShares;
use Vaulter\Filament\Support\PanelDrives;
use Vaulter\Filament\Support\PanelNodes;
use Vaulter\Filament\Support\PanelRecord;
use Vaulter\Filament\Support\PanelRecords;
use Vaulter\Filament\Support\PanelShares;

/**
 * The bearer share-link admin surface: list, create and revoke the public share-links on a
 * node. The raw token is NEVER stored and NEVER displayed here — only the non-secret
 * `token_prefix` plus the live/expired/revoked status. It is shown exactly ONCE, at mint time,
 * from the share operations' result.
 *
 * Rows are the links the drive handles list for the nodes the acting admin can share
 * ({@see PanelShares}); every mutation flows through the same handle's share operations:
 *   - create → mints + hashes the token; raw shown once;
 *   - rotate → replaces the token; the new raw token shown once;
 *   - revoke → stamps revoked_at; idempotent.
 * All require Ability::Share on the node, so the panel never bypasses the ACL.
 *
 * @phpstan-import-type ShareRow from PanelShares
 */
#[Internal]
final class NodeShareResource extends Resource
{
    use ConfiguresNavigationGroup;

    protected static ?string $model = PanelRecord::class;

    protected static ?string $modelLabel = 'node share';

    protected static bool $isGloballySearchable = false;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-link';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::shareFormComponents());
    }

    #[Override]
    public static function table(Table $table): Table
    {
        return $table
            ->records(self::records(...))
            ->paginationMode(PaginationMode::Simple)
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
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        PanelShares::LIVE => 'success',
                        PanelShares::REVOKED => 'danger',
                        default => 'warning',
                    }),

                TextColumn::make('use_count')
                    ->label('Uses')
                    ->formatStateUsing(fn (int $state, array $record): string => ($max = self::maxUses($record)) === null ? (string) $state : $state.' / '.$max)
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
                    ->falseLabel('Live only'),
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
     * The create-share form fields. The raw token is NOT a field —
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
     * Mint a share-link from the create-form data through the node's drive handle and surface
     * the raw token ONCE via a persistent notification. The node must be one the admin can
     * view and the Share ability on it is enforced first; a denial surfaces a warning, never a
     * silent mint. A hidden node and a missing node answer alike.
     *
     * @param array<string, mixed> $data
     */
    public static function createFromData(array $data): void
    {
        $nodeId = $data['node_id'] ?? null;
        $found = is_int($nodeId) || is_string($nodeId) ? PanelNodes::find($nodeId) : null;

        if ($found === null) {
            self::notifyDenied('The selected node no longer exists.');

            return;
        }

        [$handle, $node] = $found;

        if (!self::actorCanShare($handle->shares(), $node->id)) {
            self::notifyDenied('You may not share this node.');

            return;
        }

        /** @var string $password */
        $password = $data['password'] ?? '';
        /** @var string $expiresAt */
        $expiresAt = $data['expires_at'] ?? '';
        $maxUses = $data['max_uses'] ?? null;

        try {
            $minted = $handle->shares()->create(
                node: $node->id,
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

    /**
     * Revoke a share-link through the drive's share operations, which re-check Share inside
     * the mutation.
     *
     * @param array<mixed> $record a panel share row
     */
    public static function revokeRecord(array $record): void
    {
        $handle = PanelDrives::handle(self::field($record, 'drive_id')) ?? throw NodeNotFoundException::forId('missing');
        $handle->shares()->revoke(node: self::field($record, 'node_id'), share: self::field($record, 'id'));
    }

    /**
     * Rotate the link's token; returns the new raw token.
     *
     * @param array<mixed> $record a panel share row
     */
    public static function rotateRecord(array $record): string
    {
        $handle = PanelDrives::handle(self::field($record, 'drive_id')) ?? throw NodeNotFoundException::forId('missing');

        return $handle->shares()->rotate(node: self::field($record, 'node_id'), share: self::field($record, 'id'))->token;
    }

    /**
     * @param  array<string, mixed>                    $filters
     * @return Paginator<string, array<string, mixed>>
     */
    private static function records(?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage): Paginator
    {
        $revoked = PanelRecords::filterValue($filters, 'revoked');
        $rows = array_values(array_filter(
            PanelShares::rows(),
            static fn (array $row): bool => match ($revoked) {
                '1' => $row['revoked_at'] !== null,
                '0' => $row['revoked_at'] === null,
                default => true,
            },
        ));

        return PanelRecords::paginate($rows, $sortColumn, $sortDirection, $page, $recordsPerPage, 'created_at');
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
     * Revoke a share-link. Gated by Ability::Share on the node: the rows are only built for
     * nodes the admin can share; hidden on an already-revoked share.
     */
    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-x-mark')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (array $record): bool => self::isLive($record))
            ->action(function (array $record): void {
                self::revokeRecord($record);

                Notification::make()->title('Share link revoked')->success()->send();
            });
    }

    /**
     * Replace a link's token. The new raw token is shown once.
     */
    private static function rotateAction(): Action
    {
        return Action::make('rotate')
            ->label('Rotate token')
            ->icon('heroicon-o-arrow-path')
            ->requiresConfirmation()
            ->modalDescription('The current link stops working at once.')
            ->visible(fn (array $record): bool => self::isLive($record))
            ->action(function (array $record): void {
                self::notifyToken('Share link rotated', self::rotateRecord($record));
            });
    }

    /** @param array<mixed> $record */
    private static function isLive(array $record): bool
    {
        return ($record['revoked_at'] ?? null) === null;
    }

    /** Whether the handle lets the acting admin share the node (listing its links needs Share). */
    private static function actorCanShare(ShareOperations $shares, string $nodeId): bool
    {
        try {
            $shares->list($nodeId);

            return true;
        } catch (VaulterException) {
            return false;
        }
    }

    /** @param array<mixed> $record */
    private static function field(array $record, string $key): string
    {
        $value = $record[$key] ?? null;

        return is_scalar($value) ? (string) $value : '';
    }

    /** @param array<mixed> $record */
    private static function maxUses(array $record): ?int
    {
        $max = $record['max_uses'] ?? null;

        return is_int($max) ? $max : null;
    }

    /**
     * @return array<int|string, string>
     */
    private static function nodeOptions(): array
    {
        $options = [];

        foreach (PanelNodes::rows() as $node) {
            if ($node['trashed_at'] === null && ($node['abilities'][Ability::Share->value] ?? false) === true) {
                $options[$node['id']] = sprintf('#%s %s [%s]', $node['id'], $node['name'] === '' ? '(root)' : $node['name'], $node['drive_id']);
            }
        }

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
