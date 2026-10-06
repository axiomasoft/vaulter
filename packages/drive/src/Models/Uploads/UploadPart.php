<?php

declare(strict_types=1);

namespace Vaulter\Drive\Models\Uploads;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Vaulter\Configuration\DatabaseConfig;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * A single resumable-upload part (master plan §2.8 / D16 / M3a): the normalised
 * record behind an S3-multipart part. The composite primary key
 * (upload_session_id, part_no) IS the S3 re-upload-replaces semantics — a fresh PUT
 * of part N supersedes the prior etag/size. etag is the object-store part etag (kept
 * for the complete-multipart call, NEVER trusted as a content hash, H.3);
 * part_sha256 is the optional server-recomputed per-part digest. Swappable via
 * the configuration key ('vaulter-drive.models.upload_part').
 *
 * @property int|string      $upload_session_id
 * @property int             $part_no
 * @property int             $byte_offset
 * @property int             $size_bytes
 * @property string|null     $part_sha256
 * @property string|null     $etag
 * @property int|string|null $temp_blob_id
 * @property Carbon|null     $received_at
 */
class UploadPart extends Pivot
{
    use ResolvesVaulterConnection;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Fully guarded (F-P1.8-02): the S3-multipart part record. Every write goes
     * through UploadPartStoreRepository via the query builder (the connection query builder
     * insert/update), never Eloquent mass-assignment, so this is pure
     * defense-in-depth against a future Eloquent-based write.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    #[\Override]
    public function getTable(): string
    {
        return app(DatabaseConfig::class)->table('upload_parts');
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    protected function casts(): array
    {
        return [
            'part_no' => 'integer',
            'byte_offset' => 'integer',
            'size_bytes' => 'integer',
            'received_at' => 'datetime',
        ];
    }
}
