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
use Override;
use UnitEnum;
use Vaulter\Access\AccessResolver;
use Vaulter\Access\ValueObjects\Subject;
use Vaulter\Actions\Access\GrantNodeAccessAction;
use Vaulter\Actions\Access\RevokeNodeAccessAction;
use Vaulter\Data\Access\GrantNodeAccessInput;
use Vaulter\Enums\Access\Ability;
use Vaulter\Enums\Access\Effect;
use Vaulter\Enums\Access\GrantLevel;
use Vaulter\Enums\Access\GrantScope;
use Vaulter\Filament\Resources\NodeGrantResource\Pages\ListNodeGrants;
use Vaulter\Filament\Support\PanelAccessScope;
use Vaulter\Filament\Support\PanelActor;
use Vaulter\Models\Access\NodeGrant;
use Vaulter\Models\Drive\Node;
use Vaulter\Support\Config;

/**
 * The per-node ACL admin surface (master plan §2.6 / §3): list, create and revoke
 * the node grants the {@see AccessResolver} folds into effective permissions. A
 * grant addresses a subject (type + id) on a node with a level + CRUDS bits, an
 * allow|deny effect, an item|subtree scope and an optional read-time expiry.
 *
 * Every mutation flows through a CORE Action — never a direct Eloquent write:
 *   - create → {@see GrantNodeAccessAction} (idempotent upsert on the grant key);
 *   - revoke → {@see RevokeNodeAccessAction} (drops every grant a subject holds).
 * Both require Ability::Share on the target node, gated through the AccessResolver,
 * so the panel never bypasses the ACL. The model resolves through
 * {@see Config::grantModel()} for swappability.
 */
final class NodeGrantResource extends Resource
{
    protected static ?string $model = NodeGrant::class;

    protected static BackedEnum|string|null $navigationIcon = 'heroicon-o-key';

    protected static string|UnitEnum|null $navigationGroup = 'Vaulter';

    #[Override]
    public static function getModel(): string
    {
        return Config::grantModel();
    }

    /**
     * Access-aware listing (F-P1.10-01): narrow to grant rows whose node_id is in
     * the acting admin's grant-reachable candidate set (the same set
     * {@see NodeResource::getEloquentQuery()} narrows the node table to). Empty
     * candidate set (no actor, or an actor with zero grants) ⇒ empty table.
     */
    #[Override]
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        $nodeIds = PanelAccessScope::candidateNodeIds();

        if ($nodeIds === null) {
            return $query;
        }

        if ($nodeIds === []) {
            // `whereIn([], ...)` below is also empty; keep the explicit fail-closed branch readable.
            return $query->whereRaw('1 = 0'); // @pest-mutate-ignore: RemoveEarlyReturn
        }

        return $query->whereIn('node_id', $nodeIds);
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

                TextColumn::make('level')
                    ->badge()
                    ->formatStateUsing(fn (GrantLevel $state): string => $state->label())
                    ->sortable(),

                TextColumn::make('effect')
                    ->badge()
                    ->color(fn (Effect $state): string => $state === Effect::Deny ? 'danger' : 'success')
                    ->formatStateUsing(fn (Effect $state): string => $state->label()),

                TextColumn::make('scope')
                    ->badge()
                    ->formatStateUsing(fn (GrantScope $state): string => $state->label())
                    ->toggleable(),

                TextColumn::make('permissions')
                    ->label('Bits')
                    ->toggleable(isToggledHiddenByDefault: true),

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
                SelectFilter::make('effect')
                    ->options(self::effectOptions()),

                SelectFilter::make('level')
                    ->options(self::levelOptions()),
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
        // Created through the header action (which authorizes Share on the chosen
        // node and calls the core Action), not a flat resource create page.
        return false;
    }

    /**
     * The grant form fields shared by the create header action (master plan §2.6).
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

            Select::make('level')
                ->options(self::levelOptions())
                ->default(GrantLevel::Reader->value)
                ->required(),

            Select::make('effect')
                ->options(self::effectOptions())
                ->default(Effect::Allow->value)
                ->required(),

            Select::make('scope')
                ->options(self::scopeOptions())
                ->default(GrantScope::Subtree->value)
                ->required(),

            TextInput::make('permissions')
                ->label('Extra CRUDS bits')
                ->helperText('0–31; OR-ed on top of the level bits. Leave 0 for level-only.')
                ->numeric()
                ->minValue(0)
                ->maxValue(31)
                ->default(0),

            DateTimePicker::make('expires_at')
                ->label('Expires at')
                ->placeholder('Never'),
        ];
    }

    /**
     * Persist a grant from the validated create-form data via the core Action. The
     * Share ability on the chosen node is enforced here; a denial surfaces as a
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

        if (!self::actorCanShare($node)) {
            self::notifyDenied('You may not manage sharing on this node.');

            return;
        }

        /** @var string $expiresAt */
        $expiresAt = $data['expires_at'] ?? '';

        app(GrantNodeAccessAction::class)->execute(
            node: $node,
            data: new GrantNodeAccessInput(
                subject: new Subject(
                    // Filament validation already supplies these enum/string fields with their declared types.
                    type: (string) $data['subject_type'], // @pest-mutate-ignore: RemoveStringCast
                    id: self::scalarId($data['subject_id'] ?? ''),
                ),
                level: GrantLevel::from((string) $data['level']), // @pest-mutate-ignore: RemoveStringCast
                effect: Effect::from((string) ($data['effect'] ?? Effect::Allow->value)), // @pest-mutate-ignore: RemoveStringCast
                scope: GrantScope::from((string) ($data['scope'] ?? GrantScope::Subtree->value)), // @pest-mutate-ignore: RemoveStringCast
                permissions: (int) ($data['permissions'] ?? 0),
                expiresAt: $expiresAt === '' ? null : Carbon::parse($expiresAt),
                grantedBy: PanelActor::id(),
            ),
        );

        Notification::make()->title('Grant saved')->success()->send();
    }

    /**
     * Revoke every grant a subject holds on the row's node via the core Action.
     * Gated by Ability::Share on the node.
     */
    private static function revokeAction(): Action
    {
        return Action::make('revoke')
            ->label('Revoke')
            ->icon('heroicon-o-trash')
            ->color('danger')
            ->requiresConfirmation()
            ->visible(fn (NodeGrant $record): bool => self::actorCanShare($record->node))
            ->action(function (NodeGrant $record): void {
                app(RevokeNodeAccessAction::class)->execute(
                    node: $record->node,
                    subject: $record->subject(),
                    actorId: PanelActor::id(),
                );

                Notification::make()->title('Grant revoked')->success()->send();
            });
    }

    /**
     * Whether the acting admin may share (manage grants on) the node. No acting
     * user ⇒ false.
     */
    private static function actorCanShare(Node $node): bool
    {
        $actor = PanelActor::current();

        if ($actor === null) {
            return false;
        }

        return app(AccessResolver::class)->can($actor, Ability::Share, $node);
    }

    private static function resolveNode(int|string|null $id): ?Node
    {
        // Both values also produce `find() === null`; this fast path avoids a needless query.
        if ($id === null || $id === '') { // @pest-mutate-ignore: BooleanOrToBooleanAnd,EmptyStringToNotEmpty
            return null;
        }

        $model = Config::nodeModel();

        return $model::query()->find($id);
    }

    /**
     * @return array<int|string, string>
     */
    private static function nodeOptions(): array
    {
        $model = Config::nodeModel();

        /** @var array<int|string, string> $options */
        $options = $model::query()
            ->whereNull('trashed_at')
            ->orderBy('storage_id')
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Node $node): array => [
                $node->getKey() => sprintf('#%s %s [%s]', $node->getKey(), $node->name === '' ? '(root)' : $node->name, $node->storage_id),
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
    private static function levelOptions(): array
    {
        $options = [];

        foreach (GrantLevel::cases() as $level) {
            $options[$level->value] = $level->label();
        }

        return $options;
    }

    /**
     * @return array<string, string>
     */
    private static function effectOptions(): array
    {
        $options = [];

        foreach (Effect::cases() as $effect) {
            $options[$effect->value] = $effect->label();
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
