<?php

declare(strict_types=1);

namespace Vaulter\Drive\Enums\Uploads;

use Vaulter\Attributes\Label;
use Vaulter\Support\Concerns\HasLabel;

/**
 * The resumable-upload session lifecycle (master plan §2.8 / D16):
 *
 *   Initiated → Uploading → Assembled → Committed
 *        └──────────┴──────────┘
 *               ↓ (abort / sweep)
 *          Aborted / Expired
 *
 *  - Initiated  — the session row exists; no bytes received yet (received_bytes=0).
 *  - Uploading  — at least one tus PATCH / S3 part has landed; received_bytes is
 *                 the authoritative server-side offset.
 *  - Assembled  — all bytes are present on the staged temp (received_bytes ==
 *                 total_size_bytes for tus / multipart completed); ready to commit.
 *  - Committed  — the assembled bytes went through BlobStore.commit (H.3 — server
 *                 recomputes sha256, dedup + race-free GC apply) and the File +
 *                 FileVersion + node were created; assembled_blob_id is set. Terminal.
 *  - Aborted    — the client (or a DELETE) abandoned the session; staged temp +
 *                 S3 multipart cleaned. Terminal.
 *  - Expired    — the sweeper found a stale session past expires_at and reclaimed
 *                 it (staged temp + S3 multipart cleaned). Terminal.
 */
enum UploadStatus: string
{
    use HasLabel;

    #[Label('Initiated')]
    case Initiated = 'initiated';

    #[Label('Uploading')]
    case Uploading = 'uploading';

    #[Label('Assembled')]
    case Assembled = 'assembled';

    #[Label('Committed')]
    case Committed = 'committed';

    #[Label('Aborted')]
    case Aborted = 'aborted';

    #[Label('Expired')]
    case Expired = 'expired';

    /**
     * The states a session may legally move to from this one. The sweeper / abort
     * paths only act on the two open states (Initiated, Uploading); a terminal
     * state is never re-opened.
     *
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Initiated => [self::Uploading, self::Assembled, self::Aborted, self::Expired],
            self::Uploading => [self::Assembled, self::Aborted, self::Expired],
            self::Assembled => [self::Committed, self::Aborted, self::Expired],
            self::Committed, self::Aborted, self::Expired => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), strict: true);
    }

    /**
     * Whether the session is still open (accepts bytes and is a sweep/abort
     * candidate). Initiated and Uploading are the only sweepable states (§2.8).
     */
    public function isOpen(): bool
    {
        return $this === self::Initiated || $this === self::Uploading;
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $status): string => $status->value, self::cases());
    }
}
