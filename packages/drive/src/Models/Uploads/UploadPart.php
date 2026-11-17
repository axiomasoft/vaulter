<?php

declare(strict_types=1);

namespace Vaulter\Drive\Models\Uploads;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Override;
use Packman\Attributes\Internal;
use Vaulter\Configuration\DatabaseOptions;
use Vaulter\Models\Concerns\GuardsWrites;
use Vaulter\Models\Concerns\ResolvesVaulterConnection;

/**
 * A single resumable-upload part: the normalised
 * record behind an S3-multipart part. The composite primary key
 * (upload_session_id, part_no) IS the S3 re-upload-replaces semantics — a fresh PUT
 * of part N supersedes the prior etag/size. etag is the object-store part etag (kept
 * for the complete-multipart call, NEVER trusted as a content hash);
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
#[Internal]
class UploadPart extends Pivot
{
    use GuardsWrites;
    use ResolvesVaulterConnection;

    public $incrementing = false;

    public $timestamps = false;

    /**
     * Fully guarded: the S3-multipart part record. Every write goes
     * through UploadPartStoreRepository via the query builder (the connection query builder
     * insert/update), never Eloquent mass-assignment, so this is pure
     * defense-in-depth against a future Eloquent-based write.
     *
     * @var array<string>|bool
     */
    protected $guarded = ['*'];

    #[Override]
    public function getTable(): string
    {
        return app(DatabaseOptions::class)->table('upload_parts');
    }

    /**
     * @return array<string, string>
     */
    #[Override]
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
