<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Vaulter\Drive\Uploads\Enums\UploadProtocol;
use Vaulter\Drive\Uploads\Enums\UploadStatus;
use Vaulter\Models\Cas\Blob;
use Vaulter\Models\Concerns\HasExtraData;
use Vaulter\Models\Concerns\HasVaulterKeys;
use Vaulter\Models\Drive\Storage;
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

    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('upload_sessions');
    }

    /**
     * The storage this session ingests into.
     *
     * @return BelongsTo<Storage, $this>
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
        return $this->hasMany(Config::uploadPartModel(), 'upload_session_id');
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
     * The §2.8 sweeper scope: OPEN sessions (initiated|uploading) whose expires_at is
     * at or before $threshold — the stale candidates the sweeper reclaims. Backed by
     * the (status, expires_at) index.
     *
     * @param  Builder<$this> $query
     * @return Builder<$this>
     */
    public function scopeSweepable(Builder $query, Carbon $threshold): Builder
    {
        return $query
            ->whereIn('status', [UploadStatus::Initiated->value, UploadStatus::Uploading->value])
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
