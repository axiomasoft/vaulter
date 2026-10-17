<?php

declare(strict_types=1);

namespace Vaulter\Models\Drive;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Vaulter\Attributes\Internal;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Database\Factories\FileRevisionFactory;
use Vaulter\Drive\Models\Content\ScanEvidenceRecord;
use Vaulter\Drive\Models\Content\ScanObligation;
use Vaulter\Drive\Models\Content\ScanUtcDateTime;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Concerns\StampsDriveTenant;

/**
 * An immutable file version: a monotonic number per file
 * pointing at a content-addressed blob (or a chunk manifest). The version ↔ blob
 * edges in v_file_revision_blobs carry the media pins (D23). Swappable via
 * configuration key vaulter.models.file_revision.
 *
 * @property int                  $scan_admission_sequence
 * @property CarbonImmutable|null $quarantined_at
 * @property int|string           $id
 * @property int|string|null      $tenant_id
 * @property int|string           $file_id
 * @property int                  $number
 * @property int|string|null      $blob_id
 * @property int|string|null      $manifest_blob_id
 * @property string               $sha256
 * @property int                  $size_bytes
 * @property string|null          $mime_type
 * @property string|null          $declared_mime_type
 * @property string               $scan_status
 * @property string               $original_name
 * @property string               $status
 * @property bool                 $keep_forever
 * @property int|string|null      $restored_from_revision_id
 * @property int|string|null      $created_by
 * @property Carbon|null          $created_at
 * @property Carbon|null          $committed_at
 * @property Carbon|null          $superseded_at
 * @property Carbon|null          $client_modified_at
 */
#[Internal]
class FileRevision extends Model
{
    use GuardsWrites;

    /** @use HasFactory<FileRevisionFactory> */
    use HasFactory;

    use HasVaulterKeys;
    use ResolvesVaulterConnection;
    use StampsDriveTenant;

    public const UPDATED_AT = null;

    /**
     * Closed allow-list: id/tenant_id/file_id/number/blob_id/
     * manifest_blob_id/sha256/size_bytes/status/created_by/committed_at
     * are content-addressed or lifecycle columns — every write path that sets
     * them already uses forceFill()/create() with a server-built literal array
     * (FileRevisionStoreRepository).
     *
     * @var list<string>
     */
    protected $fillable = [
        'mime_type',
        'original_name',
        'keep_forever',
    ];

    protected static function newFactory(): FileRevisionFactory
    {
        return FileRevisionFactory::new();
    }

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('file_revisions');
    }

    /**
     * @return BelongsTo<File, $this>
     */
    public function file(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('file'), 'file_id');
    }

    /**
     * The single-blob payload (when not chunked).
     *
     * @return BelongsTo<Blob, $this>
     */
    public function blob(): BelongsTo
    {
        return $this->belongsTo(app(VaulterConfig::class)->model('blob'), 'blob_id');
    }

    /**
     * Every blob this version references through the edge table (each edge pinned, D23).
     *
     * @return BelongsToMany<Blob, $this, FileRevisionBlob>
     */
    public function blobs(): BelongsToMany
    {
        return $this->belongsToMany(
            related: app(VaulterConfig::class)->model('blob'),
            table: app(DatabaseConfig::class)->table('file_revision_blobs'),
            foreignPivotKey: 'file_revision_id',
            relatedPivotKey: 'blob_id',
        )
            ->using(app(VaulterConfig::class)->model('file_revision_blob'))
            ->withPivot(['role', 'ordinal', 'offset_bytes', 'length_bytes']);
    }

    /** @return HasMany<ScanEvidenceRecord, $this> */
    public function scanEvidence(): HasMany
    {
        return $this->hasMany(app(VaulterConfig::class)->model('scan_evidence'), 'revision_id');
    }

    /** @return HasMany<ScanObligation, $this> */
    public function scanObligations(): HasMany
    {
        return $this->hasMany(app(VaulterConfig::class)->model('scan_obligation'), 'revision_id');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'scan_admission_sequence' => PHP_INT_SIZE === 8 ? 'integer' : 'string',
            'quarantined_at' => ScanUtcDateTime::class,
            'number' => 'integer',
            'size_bytes' => 'integer',
            'keep_forever' => 'boolean',
            'client_modified_at' => 'datetime',
            'committed_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }
}
