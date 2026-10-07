<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * An ownable, polymorphic disk container (master plan §2.1): one storage row is a
 * disk root mounted on ANY host entity through the owner_type/owner_id morph
 * (D11). Swappable — point configuration key vaulter.models.drive at a subclass to add
 * tenant scoping or extra behaviour.
 *
 * @property int             $scan_admission_sequence
 * @property int|string      $id
 * @property string          $profile
 * @property int|string|null $tenant_id
 * @property string          $owner_type
 * @property int|string      $owner_id
 * @property string          $name
 * @property int|string|null $root_node_id
 * @property string          $disk
 * @property int|null        $quota_bytes
 * @property int             $used_bytes
 * @property string          $status
 * @property int|string|null $created_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 */
class Drive extends Model
{
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    /**
     * Closed allow-list (F-P1.8-02). owner_type/owner_id/panel/disk/storage_type/
     * used_bytes/status/root_node_id are structural or accounting columns — every
     * write path that sets them already uses forceFill() (DriveStoreRepository).
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'quota_bytes',
    ];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('drives');
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
        return $this->hasMany(app(VaulterConfig::class)->model('node'), 'drive_id');
    }

    /**
     * The root folder node this storage points at.
     *
     * @return BelongsTo<Node, $this>
     */
    public function rootNode(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('node'), 'root_node_id');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'scan_admission_sequence' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'quota_bytes' => 'integer',
            'used_bytes' => 'integer',
        ];
    }
}
