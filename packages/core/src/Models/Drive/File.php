<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Support\Config;

/**
 * The mutable 1:1 handle for a kind=file node (master plan §2.4): it owns the
 * version history and caches the head's mime/size. Swappable via
 * config('vaulter.models.file').
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string      $node_id
 * @property int|string|null $current_version_id
 * @property string|null     $mime_type
 * @property int             $size_bytes
 * @property int             $version_count
 * @property int|string|null $created_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 * @property-read FileRevision|null $currentVersion
 */
class File extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use SoftDeletes;

    /**
     * Closed guard-list (F-P1.8-02): id/tenant_id/node_id/current_version_id/
     * size_bytes/version_count/created_by/timestamps are structural or
     * server-computed bookkeeping — every write path that sets them already uses
     * forceFill() (FileStoreRepository). Only mime_type is a plain scalar left
     * mass-assignable.
     *
     * @var list<string>
     */
    protected $guarded = [
        'id',
        'tenant_id',
        'node_id',
        'current_version_id',
        'size_bytes',
        'version_count',
        'created_by',
        'created_at',
        'updated_at',
    ];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('files');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Config::nodeModel(), 'node_id');
    }

    /**
     * @return HasMany<FileRevision, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(Config::fileVersionModel(), 'file_id');
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
            'size_bytes' => 'integer',
            'version_count' => 'integer',
        ];
    }
}
