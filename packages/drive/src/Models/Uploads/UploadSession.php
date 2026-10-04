<?php

declare(strict_types=1);

namespace Vaulter\Drive\Models\Uploads;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Enums\Uploads\UploadStatus;
use Vaulter\Drive\Support\Uploads\UploadModels;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;
use Vaulter\Models\Drive\Drive;
use Vaulter\Support\Config;

/**
 * A resumable-upload session (master plan §2.8 / D16 / M3a): the first-class row
 * behind S3-multipart and tus.io ingest. upload_id is the client handle; status
 * drives the lifecycle (initiated→uploading→assembled→committed, with abort/expire);
 * received_bytes is the AUTHORITATIVE server-side tus offset — the resume point is
 * never the client's claim. On completion the assembled staged bytes go through
 * BlobStore.commit (H.3 — server recomputes sha256, never the client/ETag hash) and
 * assembled_blob_id is set.
 *
 * Swappable via config('vaulter.models.upload_session').
 *
 * @property int|string                $id
 * @property int|string|null           $tenant_id
 * @property int|string                $storage_id
 * @property string                    $upload_id
 * @property int|string|null           $node_id
 * @property int|string|null           $target_parent_id
 * @property string|null               $target_name
 * @property UploadProtocol            $protocol
 * @property UploadStatus              $status
 * @property string|null               $expected_sha256
 * @property int|null                  $total_size_bytes
 * @property int                       $received_bytes
 * @property int|null                  $chunk_size
 * @property string|null               $mime_type
 * @property array<int, mixed>|null    $parts
 * @property int|string|null           $assembled_blob_id
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null               $expires_at
 * @property int|string|null           $created_by
 * @property Carbon|null               $created_at
 * @property Carbon|null               $updated_at
 */
class UploadSession extends Model
{
    use HasExtraData;
    use HasVaulterKeys;
    use ResolvesVaulterConnection;

    /**
     * Closed allow-list (F-P1.8-02): upload metadata a caller may legitimately
     * declare. received_bytes (the AUTHORITATIVE tus offset, F-P1.4-01),
     * status/protocol/assembled_blob_id/storage_id/tenant_id/upload_id are
     * lifecycle/security state — every write path that sets them already uses
     * forceFill() (UploadSessionStoreRepository/UploadSessionManager).
     *
     * @var list<string>
     */
    protected $fillable = [
        'target_name',
        'mime_type',
        'metadata',
        'expected_sha256',
    ];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('upload_sessions');
    }

    /**
     * The storage this session ingests into.
     *
     * @return BelongsTo<Drive, $this>
     */
    public function storage(): BelongsTo
    {
        return $this->belongsTo(Config::storageModel(), 'storage_id');
    }

    /**
     * The normalised S3-multipart parts (§2.8); empty for a tus session.
     *
     * @return HasMany<UploadPart, $this>
     */
    public function parts(): HasMany
    {
        return $this->hasMany(UploadModels::part(), 'upload_session_id');
    }

    /**
     * The blob the assembled bytes deduped/committed into (null until committed).
     *
     * @return BelongsTo<Blob, $this>
     */
    public function assembledBlob(): BelongsTo
    {
        return $this->belongsTo(Config::blobModel(), 'assembled_blob_id');
    }

    /**
     * Whether this is a new-version upload onto an existing file node (§2.8).
     */
    public function isNewVersion(): bool
    {
        return $this->node_id !== null;
    }

    /**
     * The §2.8 sweeper scope: OPEN sessions (initiated|uploading) PLUS stuck
     * `Assembled` sessions (F-P1.4-10 — a session whose commit was interrupted after
     * every byte landed but before BlobStore.commit ran) whose expires_at is at or
     * before $threshold — the stale candidates the sweeper reclaims. `expires_at` is
     * stamped once at initiate and never bumped on assembly, so a session that
     * reaches Assembled well within its TTL is NOT swept (the same threshold that
     * protects an in-flight PATCH also protects an in-flight commit); only a session
     * that got stuck in Assembled past its original TTL is reclaimed. Backed by the
     * (status, expires_at) index.
     *
     * The TTL is a heuristic, not the barrier: an upload that lands its last chunk AFTER
     * expires_at (a PATCH is gated on status, never on expiry) reaches Assembled already
     * sweepable. What actually keeps the sweeper off a live commit is the session lock the
     * commit path now holds (UploadSessionManager::commitAssembled, VLT-REMED P5.2 / R-11).
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeSweepable(Builder $query, Carbon $threshold): Builder
    {
        return $query
            ->whereIn('status', [
                UploadStatus::Initiated->value,
                UploadStatus::Uploading->value,
                UploadStatus::Assembled->value,
            ])
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', $threshold);
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'protocol' => UploadProtocol::class,
            'status' => UploadStatus::class,
            'total_size_bytes' => 'integer',
            'received_bytes' => 'integer',
            'chunk_size' => 'integer',
            'parts' => 'array',
            'metadata' => 'array',
            'expires_at' => 'datetime',
        ];
    }
}
