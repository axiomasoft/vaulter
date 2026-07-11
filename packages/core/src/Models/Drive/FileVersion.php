<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Support\Config;

/**
 * An immutable file version (master plan §2.4): a monotonic version_no per file
 * pointing at a content-addressed blob (or a chunk manifest). The version ↔ blob
 * edges in v_file_version_blobs are the GC truth. Swappable via
 * config('vaulter.models.file_version').
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string      $file_id
 * @property int             $version_no
 * @property int|string|null $blob_id
 * @property int|string|null $manifest_blob_id
 * @property string          $content_sha256
 * @property int             $size_bytes
 * @property string|null     $mime_type
 * @property string          $original_name
 * @property string          $status
 * @property bool            $keep_forever
 * @property int|string|null $created_by
 * @property Carbon|null     $created_at
 * @property Carbon|null     $committed_at
 */
class FileVersion extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public const UPDATED_AT = null;

    /**
     * Closed allow-list (F-P1.8-02): id/tenant_id/file_id/version_no/blob_id/
     * manifest_blob_id/content_sha256/size_bytes/status/created_by/committed_at
     * are content-addressed or lifecycle columns — every write path that sets
     * them already uses forceFill()/create() with a server-built literal array
     * (FileVersionStoreRepository).
     *
     * @var list<string>
     */
    protected $fillable = [
        'mime_type',
        'original_name',
        'keep_forever',
    ];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('file_versions');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(Config::fileModel(), 'file_id');
    }

    /**
     * The single-blob payload (when not chunked).
     *
     * @return BelongsTo<Blob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(Config::blobModel(), 'blob_id');
    }

    /**
     * Every blob this version references through the edge table — the GC truth.
     *
     * @return BelongsToMany<Blob, $this, FileVersionBlob>
     */
    public function blobs(): BelongsToMany
    {
        return $this->belongsToMany(
            related: Config::blobModel(),
            table: Config::table('file_version_blobs'),
            foreignPivotKey: 'file_version_id',
            relatedPivotKey: 'blob_id',
        )
            ->using(Config::fileVersionBlobModel())
            ->withPivot(['role', 'ordinal', 'offset_bytes', 'length_bytes']);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'version_no' => 'integer',
            'size_bytes' => 'integer',
            'keep_forever' => 'boolean',
            'committed_at' => 'datetime',
        ];
    }
}
