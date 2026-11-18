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
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\Paginator;
use InvalidArgumentException;
use Override;
use Packman\Attributes\Internal;
use Packman\Filament\Concerns\Authorization\AuthorizesResource;
use UnitEnum;
use Vaulter\Drive\Access\Roles\RoleRegistry;
use Vaulter\Drive\Access\ValueObjects\Subject;
use Vaulter\Drive\Contracts\Access\AccessOperations;
use Vaulter\Drive\Data\Access\GrantInput;
use Vaulter\Drive\Enums\Access\Ability;
use Vaulter\Drive\Enums\Access\GrantScope;
use Vaulter\Drive\Exceptions\Access\UnknownRoleException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Rules\Access\RegisteredSubjectType;
use Vaulter\Exceptions\VaulterException;
use Vaulter\Filament\Resources\Concerns\ConfiguresNavigationGroup;
use Vaulter\Filament\Resources\NodeGrantResource\Pages\ListNodeGrants;
use Vaulter\Filament\Support\PanelDrives;
use Vaulter\Filament\Support\PanelExpiry;
use Vaulter\Filament\Support\PanelGrants;
use Vaulter\Filament\Support\PanelNodes;
use Vaulter\Filament\Support\PanelRecord;
use Vaulter\Filament\Support\PanelRecords;
use Vaulter\Support\Identity;

/**
 * The per-node ACL admin surface: list, create and revoke the node grants the drive's
 * access operations manage. A grant gives a subject (type + id) a role on a node, with an
 * item|subtree scope and an optional read-time expiry; grants are positive only.
 *
 * Rows are the grants the drive handles list for the nodes the acting admin can manage
 * ({@see PanelGrants}); every mutation flows through the same handle's access operations —
 * never a direct Eloquent write — and they require Ability::Manage on the node, so the
 * panel never bypasses the ACL.
 *
 * @phpstan-import-type GrantRow from PanelGrants
 */
#[Internal]
final class NodeGrantResource extends Resource
{
    use AuthorizesResource;
    use ConfiguresNavigationGroup;

    protected static string $packmanKey = 'vaulter.grant';

    protected static ?string $model = PanelRecord::class;

    protected static ?string $modelLabel = 'node grant';

    protected static bool $isGloballySearchable = false;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function form(Schema $schema): Schema
    {
        return $schema->components(self::grantFormComponents());
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

                TextColumn::make('subject_type')
                    ->label('Subject')
                    ->formatStateUsing(fn (string $state, array $record): string => $state.' #'.self::field($record, 'subject_id'))
                    ->searchable(),

                TextColumn::make('role_key')
                    ->label('Role')
                    ->badge()
                    ->formatStateUsing(fn (string $state, array $record): string => ucfirst($state).' v'.self::field($record, 'role_version'))
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
        // node through the access operations), not a flat resource create page.
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
                ->maxLength(255)
                ->rule(new RegisteredSubjectType),

            TextInput::make('subject_id')
                ->label('Subject id')
                ->required()
                ->maxLength(191),

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
                ->placeholder('Never')
                ->rules(PanelExpiry::grantRules()),
        ];
    }

    /**
     * Persist a grant from the validated create-form data through the node's drive handle.
     * The node must be one the admin can view and the access operations require Manage on it;
     * a denial surfaces as a warning notification rather than a silent write. A hidden node
     * and a missing node answer alike. A subject or role the grant refuses is that same
     * warning, and no row is written.
     *
     * @param array<array-key, mixed> $data
     */
    public static function grantFromData(array $data): void
    {
        $nodeId = $data['node_id'] ?? null;
        $found = is_int($nodeId) || is_string($nodeId) ? PanelNodes::find($nodeId) : null;

        if ($found === null) {
            self::notifyDenied('The selected node no longer exists.');

            return;
        }

        [$handle, $node] = $found;

        if (!self::actorCanManage($handle->access(), $node->id)) {
            self::notifyDenied('You may not manage access on this node.');

            return;
        }

        // A bad date stays a field error. An identity or role GrantInput refuses must not escape as a 500.
        $expiresAt = PanelExpiry::forGrant($data['expires_at'] ?? null);

        try {
            $handle->access()->grant(
                node: $node->id,
                grant: new GrantInput(
                    subject: new Subject(
                        // Filament validation already supplies these enum/string fields with their declared types.
                        type: Identity::stringify($data['subject_type']), // @pest-mutate-ignore: RemoveStringCast
                        id: self::scalarId($data['subject_id'] ?? ''),
                    ),
                    role: Identity::stringify($data['role']), // @pest-mutate-ignore: RemoveStringCast
                    scope: GrantScope::from(Identity::stringify($data['scope'] ?? GrantScope::Subtree->value)), // @pest-mutate-ignore: RemoveStringCast
                    expiresAt: $expiresAt,
                ),
            );
        } catch (InvalidArgumentException|UnknownRoleException $exception) {
            self::notifyDenied($exception->getMessage());

            return;
        }

        Notification::make()->title('Grant saved')->success()->send();
    }

    /**
     * Revoke every grant a subject holds on the row's node through the drive's access
     * operations, which re-check Manage inside the mutation.
     *
     * @param array<mixed> $record a panel grant row
     */
    public static function revokeRecord(array $record): void
    {
        $handle = PanelDrives::handle(self::field($record, 'drive_id')) ?? throw NodeNotFoundException::forId('missing');
        $handle->access()->revoke(node: self::field($record, 'node_id'), grant: self::field($record, 'id'));
    }

    /**
     * @param  array<string, mixed>                    $filters
     * @return Paginator<string, array<string, mixed>>
     */
    private static function records(?string $search, array $filters, ?string $sortColumn, ?string $sortDirection, int $page, int|string $recordsPerPage): Paginator
    {
        $role = PanelRecords::filterValue($filters, 'role_key');
        $rows = array_values(array_filter(
            PanelGrants::rows(),
            static fn (array $row): bool => ($role === null || $row['role_key'] === $role)
                && PanelRecords::contains($row['subject_type'].' '.$row['subject_id'], $search),
        ));

        return PanelRecords::paginate($rows, $sortColumn, $sortDirection, $page, $recordsPerPage, 'created_at');
    }

    /**
     * Revoke every grant a subject holds on the row's node. Gated by Ability::Manage on
     * the node: the rows are only built for nodes the admin can manage.
     */
    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->action(function (array $record): void {
                self::revokeRecord($record);

                Notification::make()->title('Grant revoked')->success()->send();
            });
    }

    /** Whether the handle lets the acting admin manage grants on the node (listing them needs Manage). */
    private static function actorCanManage(AccessOperations $access, string $nodeId): bool
    {
        try {
            $access->grants($nodeId);

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

    /**
     * @return array<int|string, string>
     */
    private static function nodeOptions(): array
    {
        $options = [];

        foreach (PanelNodes::rows() as $node) {
            if ($node['trashed_at'] === null && ($node['abilities'][Ability::Manage->value] ?? false) === true) {
                $options[$node['id']] = sprintf('#%s %s [%s]', $node['id'], $node['name'] === '' ? '(root)' : $node['name'], $node['drive_id']);
            }
        }

        return $options;
    }

    private static function scalarId(mixed $value): int|string
    {
        if (is_int($value)) { // @pest-mutate-ignore: IfNegated
            return $value; // @pest-mutate-ignore: RemoveEarlyReturn
        }

        return is_scalar($value) ? (string) $value : '';
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
