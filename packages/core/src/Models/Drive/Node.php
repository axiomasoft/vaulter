<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Concerns\StampsDriveTenant;
use Vaulter\Support\NodeName;

/**
 * A node in the unified folder|file|document adjacency tree (master plan §2.2,
 * the centre of the schema). One tree carries all kinds; move/rename are a single
 * parent_id/name UPDATE and reads use a recursive CTE (D5).
 *
 * The recursive relationships come from staudenmeir/laravel-adjacency-list; the
 * adjacency key column is parent_id and the local key is the strategy-driven
 * primary key (ulid|uuid7|bigint), so descendants()/ancestors() resolve the same
 * under every id strategy. Swappable — point configuration key vaulter.models.node at a
 * subclass for tenant scoping.
 *
 * @property int|string                                $id
 * @property string                                    $profile
 * @property int|string|null                           $tenant_id
 * @property int|string                                $drive_id
 * @property int|string|null                           $parent_id
 * @property NodeKind                                  $kind
 * @property string                                    $name
 * @property string                                    $name_key
 * @property string|null                               $mime_type
 * @property string|null                               $extension
 * @property int                                       $size_bytes
 * @property int                                       $sequence
 * @property array<string, array<string, string>>|null $properties
 * @property bool                                      $inherit_grants
 * @property int|string|null                           $shortcut_target_id
 * @property Carbon|null                               $trashed_at
 * @property int|string|null                           $trashed_by
 * @property int|string|null                           $trashed_from_parent_id
 * @property int|string|null                           $trash_root_id
 * @property Carbon|null                               $content_modified_at
 * @property int|string|null                           $content_modified_by
 * @property Carbon|null                               $created_at
 * @property Carbon|null                               $updated_at
 * @property-read Drive $storage
 * @property-read Node|null        $parent
 * @property-read File|null        $file
 */
class Node extends Model
{
    use GuardsWrites, HasRecursiveRelationships {
        GuardsWrites::newBaseQueryBuilder insteadof HasRecursiveRelationships;
    }
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    /**
     * Closed allow-list (F-P1.8-02): only the handful of columns a caller
     * legitimately mass-assigns through fill()/create() with untrusted-shaped
     * arrays. Everything structural/security (id, tenant_id, drive_id,
     * parent_id, inherit_grants, shortcut_target_id, the
     * trashed_* triplet, timestamps) is excluded — every write path that sets
     * those columns already uses forceFill() (NodeStoreRepository) and is
     * unaffected by this list.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'mime_type',
        'extension',
    ];

    #[\Override]
    public function getTable(): string
    {
        // Honour an explicitly-set table (staudenmeir swaps it to the recursive
        // CTE alias during descendants()/ancestors()); only fall back to the
        // shared schema name when none was set.
        return $this->table ?? app(DatabaseConfig::class)->table('nodes');
    }

    /**
     * Every write of the name also writes its sibling-uniqueness key, so no path —
     * an Action, a host model write or a seeder — can store one without the other.
     *
     * @return Attribute<string, string>
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            set: static fn (string $value): array => ['name' => $value, 'name_key' => NodeName::key($value)],
        );
    }

    /**
     * The adjacency parent-key column (§2.2). Constant across id strategies — only
     * the column TYPE changes, not its name. Overrides the trait default.
     */
    public function getParentKeyName(): string
    {
        return 'parent_id';
    }

    /**
     * The local key the parent key points back at — the strategy-driven primary
     * key (id), so the recursive CTE joins on the right column under ulid/uuid7/
     * bigint alike. Overrides the trait default.
     */
    public function getLocalKeyName(): string
    {
        return $this->getKeyName();
    }

    /**
     * Cycle detection on READ (F-P4.2-02): a cycle in `parent_id` that reached
     * the data outside `MoveNodeAction::assertNoCycle()` (raw SQL, a seeder, a
     * legacy import, a future bulk-write path) must not send `ancestors()`/
     * `descendants()` into an inflated or unbounded recursive scan. The trait
     * defaults this to `false` — every Node overrides it `true`, so the
     * recursive CTE stops at the repeated row (a `not(is_cycle)` guard on the
     * recursive leg) on every adjacency-list read, not only through the Action.
     */
    public function enableCycleDetection(): bool
    {
        return true;
    }

    /**
     * Restrict a query (and any recursive descent built on it) to a single
     * storage's tree.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeTreeOf(Builder $query, int|string $storageId): Builder
    {
        return $query->where('drive_id', $storageId);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('node'), 'parent_id');
    }

    /**
     * @return HasMany<Node, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(app(VaulterConfig::class)->model('node'), 'parent_id');
    }

    /**
     * @return BelongsTo<Drive, $this>
     */
    public function storage(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('drive'), 'drive_id');
    }

    /**
     * The file aggregate of a kind=file node; its head lives on the aggregate.
     *
     * @return HasOne<File, $this>
     */
    public function file(): HasOne
    {
        return $this->hasOne(app(VaulterConfig::class)->model('file'), 'node_id');
    }

    /**
     * Personal state of this node, one row per actor (PLAN2.D40).
     *
     * @return HasMany<NodeUserState, $this>
     */
    public function userStates(): HasMany
    {
        return $this->hasMany(NodeUserState::class, 'node_id');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'kind' => NodeKind::class,
            'size_bytes' => 'integer',
            'sequence' => 'integer',
            'properties' => 'array',
            'inherit_grants' => 'boolean',
            'trashed_at' => 'datetime',
            'content_modified_at' => 'datetime',
        ];
    }
}
