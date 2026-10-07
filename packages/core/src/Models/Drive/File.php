<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Concerns\StampsDriveTenant;

/**
 * The mutable 1:1 handle for a kind=file node (master plan §2.4): it owns the
 * version history and caches the head's mime/size. Swappable via
 * configuration key vaulter.models.file.
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string      $node_id
 * @property int|string|null $current_revision_id
 * @property string|null     $mime_type
 * @property int             $size_bytes
 * @property int             $revision_count
 * @property int|string|null $created_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $updated_at
 * @property-read FileRevision|null $currentRevision
 */
class File extends Model
{
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    /**
     * Closed guard-list (F-P1.8-02): id/tenant_id/node_id/current_revision_id/
     * size_bytes/revision_count/created_by/timestamps are structural or
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
        'current_revision_id',
        'size_bytes',
        'revision_count',
        'created_by',
        'created_at',
        'updated_at',
    ];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('files');
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('node'), 'node_id');
    }

    /**
     * @return HasMany<FileRevision, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(app(VaulterConfig::class)->model('file_revision'), 'file_id');
    }

    /**
     * The head revision. The composite head FK guarantees it is a revision of
     * this file on PostgreSQL/MySQL.
     *
     * @return BelongsTo<FileRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('file_revision'), 'current_revision_id');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'revision_count' => 'integer',
        ];
    }
}
