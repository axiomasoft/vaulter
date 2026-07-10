<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Support\Config;

/**
 * An ownable, polymorphic disk container (master plan §2.1): one storage row is a
 * disk root mounted on ANY host entity through the owner_type/owner_id morph
 * (D11). Swappable — point config('vaulter.models.storage') at a subclass to add
 * tenant scoping or extra behaviour.
 *
 * @property int|string      $id
 * @property string          $panel
 * @property int|string|null $tenant_id
 * @property string          $owner_type
 * @property int|string      $owner_id
 * @property string          $name
 * @property string|null     $slug
 * @property int|string|null $root_node_id
 * @property string          $disk
 * @property string          $storage_type
 * @property int|null        $quota_bytes
 * @property int             $used_bytes
 * @property string          $status
 * @property int             $default_node_permissions
 * @property int|string|null $created_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 */
class Storage extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use SoftDeletes;

    /**
     * Closed allow-list (F-P1.8-02). owner_type/owner_id/panel/disk/storage_type/
     * used_bytes/status/root_node_id are structural or accounting columns — every
     * write path that sets them already uses forceFill() (StorageStoreRepository).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'slug',
        'quota_bytes',
        'default_node_permissions',
    ];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('storages');
    }

    /**
     * The host entity this storage is mounted on (project, team, user, …).
     *
     * @return MorphTo<Model, $this>
     */
    public function owner(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return HasMany<Node, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(Config::nodeModel(), 'storage_id');
    }

    /**
     * The root folder node this storage points at.
     *
     * @return BelongsTo<Node, $this>
     */
    public function rootNode(): BelongsTo
    {
        return $this->belongsTo(Config::nodeModel(), 'root_node_id');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'quota_bytes' => 'integer',
            'used_bytes' => 'integer',
            'default_node_permissions' => 'integer',
        ];
    }
}
