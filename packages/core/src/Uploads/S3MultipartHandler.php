<?php

declare(strict_types=1);

namespace Vaulter\Uploads;

use Illuminate\Validation\ValidationException;
use RuntimeException;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Enums\Uploads\UploadProtocol;
use Vaulter\Models\Uploads\UploadPart;
use Vaulter\Models\Uploads\UploadSession;
use Vaulter\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Repositories\Uploads\UploadSessionStoreRepository;

/**
 * The S3-style multipart handler (§2.8 / D16 / M3a). When the session's disk is an
 * object store the bytes are uploaded as numbered parts; this handler records each
 * part (part_no / byte_offset / size / etag) in v_upload_parts — a re-upload of a
 * part REPLACES its row (the S3 semantics, encoded in the composite primary key) —
 * and on complete stitches the parts (ascending by part_no) into the assembled
 * staged temp.
 *
 * H.3 invariant: the assembled object is NEVER trusted by its multipart ETag — the
 * complete step writes the stitched bytes to the staged temp, and CommitUploadAction
 * → BlobStore.commit re-hashes THOSE bytes server-side. The per-part etag we store is
 * the object-store handle only (debug / the real complete-multipart call), never the
 * content address.
 *
 * Implementation note: rather than couple core to the AWS SDK, parts are PROXIED — a
 * recorded part carries its byte_offset + bytes (staged via the StorageDriver), so
 * the same flow works against a faked/local disk in tests and a real S3 disk in
 * production. A host wanting true presigned-PUT parts swaps this handler for one that
 * mints presigned URLs in initialise(); the record/complete contract is unchanged.
 */
final readonly class S3MultipartHandler implements UploadProtocolHandler
{
    public function __construct(
        private StagedUpload $staged,
        private StorageDriver $driver,
        private UploadPartStoreRepository $parts,
        private UploadSessionStoreRepository $sessions,
    ) {}

    public function protocol(): UploadProtocol
    {
        return UploadProtocol::S3Multipart;
    }

    /**
     * Initiate the multipart upload. With a real S3 disk this is CreateMultipartUpload
     * (the returned UploadId would be surfaced to the client); in the proxied default
     * it is a no-op — the parts accumulate as they are PUT. Returns the chunk-size
     * hint clients use to slice the file.
     *
     * @return array<string, mixed>
     */
    public function initialise(UploadSession $session): array
    {
        return [
            'chunk_size' => $session->chunk_size,
        ];
    }

    /**
     * Record (and stage) one part (§2.8): the bytes are staged under the part's key,
     * a server-recomputed part_sha256 is stored alongside the object-store etag, and
     * the part row is upserted (re-upload replaces). Advancing received_bytes is the
     * sum of the recorded part sizes, so a HEAD/status reflects real progress.
     *
     * @param  resource                                     $chunk
     * @return array{part_no: int, size: int, etag: string}
     */
    public function putPart(UploadSession $session, int $partNo, int $byteOffset, mixed $chunk): array
    {
        if (!$session->status->isOpen()) {
            throw ValidationException::withMessages([
                'status' => "Upload session {$session->upload_id} is not accepting parts (status {$session->status->value}).",
            ]);
        }

        $bytes = stream_get_contents($chunk);
        $bytes = $bytes === false ? '' : $bytes;

        // The per-part key under the session's tmp/ prefix. Reclaimed with the session
        // on commit/abort/sweep.
        $partKey = $this->partKey($session, $partNo);
        $partStream = $this->streamFor($bytes);

        try {
            $this->driver->put($partKey, $partStream, $this->staged->diskName($session));
        } finally {
            if (is_resource($partStream)) {
                fclose($partStream);
            }
        }

        // The per-part sha256 is recomputed from the bytes WE received — never a
        // client claim. The object-store etag is the md5-ish handle multipart-complete
        // needs; it is NOT a content address (H.3).
        $partSha256 = hash('sha256', $bytes);
        $etag = '"'.md5($bytes).'"';

        $this->parts->record(
            session: $session,
            partNo: $partNo,
            byteOffset: $byteOffset,
            sizeBytes: strlen($bytes),
            etag: $etag,
            partSha256: $partSha256,
        );

        $this->sessions->advanceReceived(
            session: $session,
            receivedBytes: $this->recordedSize($session),
        );

        return [
            'part_no' => $partNo,
            'size' => strlen($bytes),
            'etag' => $etag,
        ];
    }

    /**
     * Complete-multipart (§2.8): stitch the recorded parts (ascending by part_no) into
     * the assembled staged temp, ready for CommitUploadAction → BlobStore.commit. The
     * stitched bytes — NOT the multipart ETag — are what the server re-hashes (H.3).
     * Returns the assembled byte length.
     */
    public function complete(UploadSession $session): int
    {
        $parts = $this->parts->forSession($session);

        if ($parts->isEmpty()) {
            throw ValidationException::withMessages([
                'parts' => "Cannot complete upload session {$session->upload_id}: no parts were recorded.",
            ]);
        }

        $assembled = '';

        foreach ($parts as $part) {
            $assembled .= $this->readPart($session, $part);
        }

        $size = $this->staged->write(session: $session, bytes: $assembled);

        $this->sessions->advanceReceived(session: $session, receivedBytes: $size);

        return $size;
    }

    /**
     * Every declared part has been recorded and stitched — completeness for multipart
     * is "the assembled total matches the declared total" (or, with an unknown total,
     * the parts cover received_bytes). Reads received_bytes which complete() set from
     * the stitched length.
     */
    public function isComplete(UploadSession $session): bool
    {
        $total = $session->total_size_bytes;

        if ($total === null) {
            // Unknown total: complete once the staged assembled temp exists.
            return $this->staged->size($session) > 0;
        }

        return $session->received_bytes >= $total && $this->staged->size($session) > 0;
    }

    /**
     * Abort the multipart upload (§2.8): with a real S3 disk this is
     * AbortMultipartUpload; in the proxied default we delete the per-part objects. The
     * assembled staged temp is discarded by the manager. Idempotent.
     */
    public function abort(UploadSession $session): void
    {
        foreach ($this->parts->forSession($session) as $part) {
            $this->driver->delete($this->partKey($session, $part->part_no), $this->staged->diskName($session));
        }
    }

    private function readPart(UploadSession $session, UploadPart $part): string
    {
        $stream = $this->driver->get($this->partKey($session, $part->part_no), $this->staged->diskName($session));

        if (!is_resource($stream)) {
            return '';
        }

        try {
            $bytes = stream_get_contents($stream);

            return $bytes === false ? '' : $bytes;
        } finally {
            fclose($stream);
        }
    }

    /**
     * The total recorded part size — the sum the manager uses as received_bytes.
     */
    private function recordedSize(UploadSession $session): int
    {
        $sum = $this->parts->forSession($session)->sum('size_bytes');

        return is_numeric($sum) ? (int) $sum : 0;
    }

    private function partKey(UploadSession $session, int $partNo): string
    {
        return "tmp/uploads/{$session->upload_id}.parts/{$partNo}";
    }

    /**
     * @return resource
     */
    private function streamFor(string $bytes): mixed
    {
        $stream = fopen('php://temp', 'r+b');

        if ($stream === false) {
            throw new RuntimeException('Unable to open an in-memory stream for the upload part.');
        }

        fwrite($stream, $bytes);
        rewind($stream);

        return $stream;
    }
}
