<?php

declare(strict_types=1);

namespace Vaulter\Enums\Uploads;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The resumable-upload transport a session speaks (master plan §2.8 / D16):
 *
 *  - Tus         — the tus.io CORE protocol (Creation + HEAD(Upload-Offset) +
 *                  PATCH(application/offset+octet-stream)). Each PATCH chunk is
 *                  appended to a staged temp on the session's disk; received_bytes
 *                  is the authoritative offset. Works on ANY Flysystem disk.
 *  - S3Multipart — the object-store multipart protocol: initiate on the bucket,
 *                  hand back presigned part URLs (or proxy PUT each part), record
 *                  parts in v_upload_parts, complete-multipart, then feed the
 *                  assembled object to BlobStore (server recompute sha256 — never
 *                  trust the multipart ETag, H.3). Only on S3-style disks.
 *  - Direct      — a one-shot (non-resumable) PUT; the session is a thin envelope
 *                  whose bytes are staged and committed in a single step (the
 *                  small-file fast path; reserved).
 */
enum UploadProtocol: string
{
    use HasLabel;

    #[Label('tus')]
    case Tus = 'tus';

    #[Label('S3 multipart')]
    case S3Multipart = 's3-multipart';

    #[Label('Direct')]
    case Direct = 'direct';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $protocol): string => $protocol->value, self::cases());
    }
}
