<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Staudenmeir\LaravelAdjacencyList\Eloquent\HasRecursiveRelationships;
use Vaulter\Enums\Drive\NodeKind;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Support\Config;

/**
 * A node in the unified folder|file|document adjacency tree (master plan §2.2,
 * the centre of the schema). One tree carries all kinds; move/rename are a single
 * parent_id/name UPDATE and reads use a recursive CTE (D5).
 *
 * The recursive relationships come from staudenmeir/laravel-adjacency-list; the
 * adjacency key column is parent_id and the local key is the strategy-driven
 * primary key (ulid|uuid7|bigint), so descendants()/ancestors() resolve the same
 * under every id strategy. Swappable — point config('vaulter.models.node') at a
 * subclass for tenant scoping.
 *
 * @property int|string      $id
 * @property string          $profile
 * @property int|string|null $tenant_id
 * @property int|string      $drive_id
 * @property int|string|null $parent_id
 * @property NodeKind        $kind
 * @property string          $name
 * @property string|null     $mime_type
 * @property string|null     $extension
 * @property int             $size_bytes
 * @property int|string|null $current_version_id
 * @property int|string|null $file_id
 * @property int             $position
 * @property bool            $is_pinned
 * @property bool            $inherit_grants
 * @property int|string|null $shortcut_target_id
 * @property int|string|null $owner_id
 * @property Carbon|null     $trashed_at
 * @property int|string|null $trashed_by
 * @property int|string|null $trashed_from_parent_id
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 * @property-read Drive $storage
 * @property-read Node|null        $parent
 * @property-read File|null        $file
 * @property-read FileRevision|null $currentVersion
 */
class Node extends Model
{
    use HasExtraData;
    use HasRecursiveRelationships;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use SoftDeletes;

    /**
     * Closed allow-list (F-P1.8-02): only the handful of columns a caller
     * legitimately mass-assigns through fill()/create() with untrusted-shaped
     * arrays. Everything structural/security (id, tenant_id, drive_id,
     * parent_id, current_version_id, file_id, owner_id, shortcut_target_id, the
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
        'is_pinned',
        'inherit_grants',
    ];

    #[\Override]
    public function getTable(): string
    {
        // Honour an explicitly-set table (staudenmeir swaps it to the recursive
        // CTE alias during descendants()/ancestors()); only fall back to the
        // shared schema name when none was set.
        return $this->table ?? Config::table('nodes');
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
        return $this->belongsTo(Config::nodeModel(), 'parent_id');
    }

    /**
     * @return HasMany<Node, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Config::nodeModel(), 'parent_id');
    }

    /**
     * @return BelongsTo<Drive, $this>
     */
    public function storage(): BelongsTo
    {
        return $this->belongsTo(Config::storageModel(), 'drive_id');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(Config::fileModel(), 'file_id');
    }

    /**
     * @return BelongsTo<FileRevision, $this>
     */
    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(Config::fileVersionModel(), 'current_version_id');
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
            'position' => 'integer',
            'is_pinned' => 'boolean',
            'inherit_grants' => 'boolean',
            'trashed_at' => 'datetime',
        ];
    }
}
