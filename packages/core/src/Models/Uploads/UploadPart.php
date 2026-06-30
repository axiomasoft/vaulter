<?php

declare(strict_types=1);

namespace Vaulter\Models\Uploads;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;
use Vaulter\Support\Config;

/**
 * A single resumable-upload part (master plan §2.8 / D16 / M3a): the normalised
 * record behind an S3-multipart part. The composite primary key
 * (upload_session_id, part_no) IS the S3 re-upload-replaces semantics — a fresh PUT
 * of part N supersedes the prior etag/size. etag is the object-store part etag (kept
 * for the complete-multipart call, NEVER trusted as a content hash, H.3);
 * part_sha256 is the optional server-recomputed per-part digest. Swappable via
 * config('vaulter.models.upload_part').
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
    public $incrementing = false;

    public $timestamps = false;

    protected $guarded = [];

    #[\Override]
    public function getTable(): string
    {
        return Config::table('upload_parts');
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
