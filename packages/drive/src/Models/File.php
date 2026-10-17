<?php

declare(strict_types=1);

namespace Vaulter\Drive\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Models\Concerns\StampsDriveTenant;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * The mutable 1:1 handle for a kind=file node: it owns the
 * version history and caches the head's mime/size. Swappable via
 * configuration key vaulter-drive.models.file.
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
#[Internal]
class File extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    /**
     * Closed guard-list: id/tenant_id/node_id/current_revision_id/
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
        return $this->belongsTo(app(DriveConfig::class)->model('node'), 'node_id');
    }

    /**
     * @return HasMany<FileRevision, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(app(DriveConfig::class)->model('file_revision'), 'file_id');
    }

    /**
     * The head revision. The composite head FK guarantees it is a revision of
     * this file on PostgreSQL/MySQL.
     *
     * @return BelongsTo<FileRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(app(DriveConfig::class)->model('file_revision'), 'current_revision_id');
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
