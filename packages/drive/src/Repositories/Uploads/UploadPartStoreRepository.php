<?php

declare(strict_types=1);

namespace Vaulter\Drive\Repositories\Uploads;

use Illuminate\Database\Eloquent\Collection;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Models\Uploads\UploadPart;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Support\Config;

/**
 * Write-side row work for v_upload_parts (§2.8 / M3a). The S3-multipart part record:
 * recording a part REPLACES any prior row for the same (session, part_no) — the S3
 * re-upload semantics encoded in the composite primary key. Opens NO transactions.
 */
final class UploadPartStoreRepository
{
    public function __construct(private readonly VaulterDatabase $database) {}

    /**
     * Record (or REPLACE) a part for a session (§2.8). A re-upload of part N
     * overwrites its etag/size/offset — keyed on the (upload_session_id, part_no)
     * composite primary key, so the second PUT updates the same row rather than
     * inserting a duplicate.
     */
    public function record(
        UploadSession $session,
        int $partNo,
        int $byteOffset,
        int $sizeBytes,
        ?string $etag = null,
        ?string $partSha256 = null,
        int|string|null $tempBlobId = null,
    ): UploadPart {
        $model = Config::uploadPartModel();
        $table = (new $model)->getTable();

        $values = [
            'byte_offset' => $byteOffset,
            'size_bytes' => $sizeBytes,
            'etag' => $etag,
            'part_sha256' => $partSha256,
            'temp_blob_id' => $tempBlobId,
            'received_at' => now(),
        ];

        // The (upload_session_id, part_no) composite primary key has no single
        // auto-key for Eloquent to update by, so the re-upload-REPLACES semantics
        // (§2.8) is a query-builder upsert keyed on the composite: a matching part is
        // UPDATEd in place, otherwise INSERTed. (Eloquent's updateOrCreate keys on
        // getKeyName(), which a composite-PK pivot lacks.)
        $existing = $this->database->connection()->table($table)
            ->where('upload_session_id', $session->getKey())
            ->where('part_no', $partNo)
            ->exists();

        if ($existing) {
            $this->database->connection()->table($table)
                ->where('upload_session_id', $session->getKey())
                ->where('part_no', $partNo)
                ->update($values);
        } else {
            $this->database->connection()->table($table)->insert([
                'upload_session_id' => $session->getKey(),
                'part_no' => $partNo,
                ...$values,
            ]);
        }

        /** @var UploadPart $part */
        $part = $model::query()
            ->where('upload_session_id', $session->getKey())
            ->where('part_no', $partNo)
            ->firstOrFail();

        return $part;
    }

    /**
     * Every recorded part for a session, ascending by part_no — the order the
     * multipart assembler stitches them in (§2.8).
     *
     * @return Collection<int, UploadPart>
     */
    public function forSession(UploadSession $session): Collection
    {
        $model = Config::uploadPartModel();

        /** @var Collection<int, UploadPart> $parts */
        $parts = $model::query()
            ->where('upload_session_id', $session->getKey())
            ->orderBy('part_no')
            ->get();

        return $parts;
    }
}
