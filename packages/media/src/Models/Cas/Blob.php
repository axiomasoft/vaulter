<?php

declare(strict_types=1);

namespace Vaulter\Media\Models\Cas;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Media\Enums\Cas\BlobStatus;
use Vaulter\Media\Support\UtcDateTimeCast;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * A content-addressable, immutable payload: identified by its
 * sha256 within a dedup scope and stored once on the Flysystem disk under a
 * scope-qualified storage_key. ref_count is a reconciled cache; the liveness
 * truth is its pins in v_blob_references. The byte handling and GC
 * commit-flow live in the Cas services — this is the schema + model only.
 *
 * Swappable via configuration key vaulter-media.models.blob.
 *
 * @property CarbonImmutable|null $quarantined_at
 * @property CarbonImmutable|null $integrity_checked_at
 * @property CarbonImmutable|null $integrity_failed_at
 * @property string|null          $storage_version
 * @property int|string           $id
 * @property int|string|null      $tenant_id
 * @property int|string|null      $storage_id
 * @property string               $hash_algo
 * @property string               $sha256
 * @property int                  $size_bytes
 * @property string               $disk
 * @property string               $storage_key
 * @property BlobStatus           $status
 * @property int                  $ref_count
 * @property string               $scope_key
 * @property string               $dedup_scope
 * @property string|null          $etag
 * @property string|null          $md5
 * @property string|null          $crc32c
 * @property bool                 $chunked
 * @property int|string|null      $encryption_key_id
 * @property Carbon|null          $first_seen_at
 * @property Carbon|null          $committed_at
 * @property Carbon|null          $delete_after_at
 */
#[Internal]
class Blob extends Model
{
    use GuardsWrites;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    public $timestamps = false;

    /**
     * Fully guarded: every column is content-addressed or CAS
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
        return app(DatabaseOptions::class)->table('blobs');
    }

    /**
     * Whether the integrity scrub found nothing wrong with the bytes of this blob: false from the moment it
     * marks the blob (missing object, wrong size or wrong digest) until an ingest of the same bytes rewrites the
     * object. The mark is media's own state — the drive's scan quarantine is a different thing — and a consumer that
     * hands the bytes out refuses a blob that is not intact.
     */
    #[Api]
    public function isIntact(): bool
    {
        return $this->integrity_failed_at === null;
    }

    /**
     * GC-candidate scope: committed blobs whose ref-count CACHE is zero.
     * That cache is rebuilt from the pins by reconciliation, so a committed
     * blob reaches ref_count<=0 the moment its last pin is removed and
     * reconciled — that, not a pre-armed deadline, is what arms the mark scan.
     * The grace window is applied by tombstone() (mark) and enforced by sweep,
     * NOT here: requiring delete_after_at on a still-committed blob would gate the
     * scan on a column no commit path ever writes, so a genuinely orphaned blob
     * would never be tombstoned. This scope only narrows the scan — the real
     * liveness re-check happens under the per-(scope,sha256) lock.
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
            'quarantined_at' => UtcDateTimeCast::class,
            'integrity_checked_at' => UtcDateTimeCast::class,
            'integrity_failed_at' => UtcDateTimeCast::class,
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
