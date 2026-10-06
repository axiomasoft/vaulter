<?php

declare(strict_types=1);

namespace Vaulter\Models\Cas;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Configuration\VaulterConfig;
use Vaulter\Enums\Cas\BlobStatus;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;

/**
 * A content-addressable, immutable payload (master plan §2.3): identified by its
 * sha256 within a dedup scope and stored once on the Flysystem disk under a
 * scope-qualified storage_key. ref_count is a reconciled cache; the liveness
 * truth is the existence of a v_file_revision_blobs edge (H.3). The byte handling
 * and GC commit-flow are M1b — this is the schema + model only.
 *
 * Swappable via configuration key vaulter.models.blob.
 *
 * @property int|string      $id
 * @property int|string|null $tenant_id
 * @property int|string|null $drive_id
 * @property string          $hash_algo
 * @property string          $sha256
 * @property int             $size_bytes
 * @property string          $disk
 * @property string          $storage_key
 * @property BlobStatus      $status
 * @property int             $ref_count
 * @property string          $scope_key
 * @property string          $dedup_scope
 * @property string|null     $etag
 * @property string|null     $md5
 * @property string|null     $crc32c
 * @property bool            $chunked
 * @property int|string|null $encryption_key_id
 * @property Carbon|null     $first_seen_at
 * @property Carbon|null     $committed_at
 * @property Carbon|null     $delete_after_at
 */
class Blob extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public $timestamps = false;

    /**
     * Fully guarded (F-P1.5-04): every column is content-addressed or CAS
     * integrity state (sha256/storage_key are the content address itself;
     * ref_count/status drive GC). Mass-assigning ANY of them could point a CAS
     * reference at the wrong bytes. The sole writer, BlobStoreRepository, uses
     * forceFill() exclusively and is unaffected by this list.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('blobs');
    }

    /**
     * Every file version that references this blob through the edge table.
     *
     * @return BelongsToMany<FileRevision, $this, FileRevisionBlob>
     */
    public function versions(): BelongsToMany
    {
        return $this->belongsToMany(
            related: app(VaulterConfig::class)->model('file_revision'),
            table: app(DatabaseConfig::class)->table('file_revision_blobs'),
            foreignPivotKey: 'blob_id',
            relatedPivotKey: 'file_revision_id',
        )
            ->using(app(VaulterConfig::class)->model('file_revision_blob'))
            ->withPivot(['role', 'ordinal', 'offset_bytes', 'length_bytes']);
    }

    /**
     * GC-candidate scope (§2.3): committed blobs whose ref-count CACHE is zero.
     * That cache is rebuilt from the edge table by reconciliation, so a committed
     * blob reaches ref_count<=0 the moment its last edge is detached and
     * reconciled — that, not a pre-armed deadline, is what arms the mark scan.
     * The grace window is applied by tombstone() (mark) and enforced by sweep,
     * NOT here: requiring delete_after_at on a still-committed blob would gate the
     * scan on a column no commit path ever writes, so a genuinely orphaned blob
     * would never be tombstoned. This scope only narrows the scan — the real
     * liveness re-check happens under the per-(scope,sha256) lock (H.3).
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeGarbageCollectable(Builder $query): Builder
    {
        return $query
            ->where('status', BlobStatus::Committed->value)
            ->where('ref_count', '<=', 0);
    }

    /**
     * Tombstoned blobs whose grace window has elapsed — the sweep candidates.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeSweepable(Builder $query): Builder
    {
        return $query
            ->where('status', BlobStatus::Tombstoned->value)
            ->whereNotNull('delete_after_at')
            ->where('delete_after_at', '<', now());
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'ref_count' => 'integer',
            'status' => BlobStatus::class,
            'chunked' => 'boolean',
            'first_seen_at' => 'datetime',
            'committed_at' => 'datetime',
            'delete_after_at' => 'datetime',
        ];
    }
}
