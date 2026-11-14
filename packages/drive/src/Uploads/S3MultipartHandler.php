<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Illuminate\Validation\ValidationException;
use RuntimeException;
use Vaulter\Attributes\Internal;
use Vaulter\Contracts\Observability\MetricsRecorder;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Configuration\DriveConfig;
use Vaulter\Drive\Contracts\Uploads\UploadProtocolHandler;
use Vaulter\Drive\Data\Snapshots\UploadSessionSnapshot;
use Vaulter\Drive\Enums\Uploads\UploadProtocol;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Exceptions\Uploads\UploadConflictException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Drive\Repositories\Uploads\UploadPartStoreRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionReadRepository;
use Vaulter\Drive\Repositories\Uploads\UploadSessionStoreRepository;
use Vaulter\Drive\Support\AssembleSizeBucket;
use Vaulter\Media\Contracts\Cas\StorageDriver;
use Vaulter\Support\LoadTier;

/**
 * The S3-style multipart handler. When the session's disk is an
 * object store the bytes are uploaded as numbered parts; this handler records each
 * part (part_no / byte_offset / size / etag) in v_upload_parts — a re-upload of a
 * part REPLACES its row (the S3 semantics, encoded in the composite primary key) —
 * and on complete stitches the parts (ascending by part_no) into the assembled
 * staged temp.
 *
 * invariant: the assembled object is NEVER trusted by its multipart ETag — the
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
#[Internal]
final readonly class S3MultipartHandler implements UploadProtocolHandler
{
    public function __construct(
        private StagedUpload $staged,
        private StorageDriver $driver,
        private UploadPartStoreRepository $parts,
        private UploadSessionStoreRepository $sessions,
        private MetricsRecorder $metrics,
        private VaulterDatabase $database,
        private DriveConfig $driveConfig,
        private UploadSessionReadRepository $reads,
    ) {}

    /** The current session behind a snapshot: its status, totals and parts are the ones decided on. */
    private function current(UploadSessionSnapshot $session): UploadSession
    {
        return $this->reads->find($session->id) ?? throw NodeNotFoundException::forId('upload');
    }

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
    public function initialise(UploadSessionSnapshot $session): array
    {
        return [
            'chunk_size' => $this->current($session)->chunk_size,
        ];
    }

    /**
     * Record (and stage) one part: the bytes are staged under the part's key,
     * a server-recomputed part_sha256 is stored alongside the object-store etag, and
     * the part row is upserted (re-upload replaces). Advancing received_bytes is the
     * sum of the recorded part sizes, so a HEAD/status reflects real progress.
     *
     * Runs inside the SAME critical section as a tus PATCH (review, the
     * class): writing a part STAGES bytes, which is exactly what the sweeper/abort path
     * destroys. It used to run in the open and check `isOpen()` on the model the controller
     * had read BEFORE authorization — so a session reclaimed in that window passed the check
     * on a STALE snapshot and its part object landed on the disk of an already-terminal
     * session. Nothing ever collects it: {@see UploadSession::scopeSweepable()} does not
     * select terminal rows, so those bytes are orphaned for good. (Corruption was already
     * impossible — commitAssembled() refuses a terminal session — but the leak was real, and
     * the claim in UploadSessionStoreRepository::acquireForPatch() that EVERY actor touching
     * the staged bytes takes this seam was false while this one did not.)
     *
     * The status is therefore RE-READ under the lock, and the whole record→advance window is
     * one transaction, exactly like TusUploadHandler::patch().
     *
     * @param  resource                                     $chunk
     * @return array{part_no: int, size: int, etag: string}
     */
    public function putPart(UploadSession $session, int $partNo, int $byteOffset, mixed $chunk): array
    {
        try {
            return $this->metrics->timing(
                name: 'vaulter_upload_chunk_duration_seconds',
                operation: fn (): array => $this->putPartChunk(
                    session: $session,
                    partNo: $partNo,
                    byteOffset: $byteOffset,
                    chunk: $chunk,
                ),
                labels: [
                    'protocol' => 's3',
                    'tier' => LoadTier::label(),
                ],
            );
        } catch (UploadConflictException $exception) {
            $this->metrics->counter(
                name: 'vaulter_upload_conflict_total',
                labels: ['protocol' => 's3'],
            );

            throw $exception;
        }
    }

    /**
     * @param  resource                                     $chunk
     * @return array{part_no: int, size: int, etag: string}
     */
    private function putPartChunk(UploadSession $session, int $partNo, int $byteOffset, mixed $chunk): array
    {
        $bytes = $this->readPartWithinLimit($chunk);

        return $this->database->transaction(function () use ($session, $partNo, $byteOffset, $bytes): array {
            $locked = $this->sessions->acquireForPatch($session);

            if (!$locked->status->isOpen()) {
                throw ValidationException::withMessages([
                    'status' => "Upload session {$locked->upload_id} is not accepting parts (status {$locked->status->value}).",
                ]);
            }

            // A replaced part counts once. The check is before the object write: storage
            // is not rolled back with the database transaction.
            $incoming = strlen($bytes);
            $projected = $this->recordedSize($locked) - $this->parts->sizeOf($locked, $partNo) + $incoming;
            $this->assertProjectedSize($locked, $projected);

            // The per-part key under the session's tmp/ prefix. Reclaimed with the session
            // on commit/abort/sweep.
            $partKey = $this->partKey($locked, $partNo);
            $partStream = $this->streamFor($bytes);

            try {
                $this->driver->put($partKey, $partStream, $this->staged->diskName($locked));
            } finally {
                if (is_resource($partStream)) {
                    fclose($partStream);
                }
            }

            // The per-part sha256 is recomputed from the bytes WE received — never a
            // client claim. The object-store etag is the md5-ish handle multipart-complete
            // needs; it is NOT a content address.
            $partSha256 = hash('sha256', $bytes);
            $etag = '"'.md5($bytes).'"';

            $this->parts->record(
                session: $locked,
                partNo: $partNo,
                byteOffset: $byteOffset,
                sizeBytes: strlen($bytes),
                etag: $etag,
                partSha256: $partSha256,
            );

            $this->sessions->advanceReceived(
                session: $locked,
                receivedBytes: $this->recordedSize($locked),
            );

            $size = strlen($bytes);

            $this->metrics->counter(
                name: 'vaulter_upload_bytes_total',
                amount: $size,
                labels: ['protocol' => 's3'],
            );

            return [
                'part_no' => $partNo,
                'size' => $size,
                'etag' => $etag,
            ];
        });
    }

    /**
     * Complete-multipart: stitch the recorded parts (ascending by part_no) into
     * the assembled staged temp, ready for CommitUploadAction → BlobStore.commit. The
     * stitched bytes — NOT the multipart ETag — are what the server re-hashes.
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

        $assembleStarted = hrtime(true);
        $assembled = fopen('php://temp/maxmemory:2097152', 'w+b');

        if ($assembled === false) {
            throw new RuntimeException('Unable to open the multipart assembly stream.');
        }

        try {
            $assembledSize = 0;

            foreach ($parts as $part) {
                $partStream = $this->driver->get(
                    $this->partKey($session, $part->part_no),
                    $this->staged->diskName($session),
                );

                if (!is_resource($partStream)) {
                    throw ValidationException::withMessages([
                        'parts' => "Upload part {$part->part_no} is missing for session {$session->upload_id}.",
                    ]);
                }

                try {
                    $copied = stream_copy_to_stream($partStream, $assembled);
                } finally {
                    fclose($partStream);
                }

                if ($copied === false || $copied !== $part->size_bytes) {
                    throw ValidationException::withMessages([
                        'parts' => "Upload part {$part->part_no} size does not match its recorded bytes.",
                    ]);
                }

                $assembledSize += $copied;
            }

            $assembleDuration = (hrtime(true) - $assembleStarted) / 1_000_000_000;

            // Integrity gate: with a known declared total, the assembled
            // object MUST match it exactly. Stitching a subset of the parts (a client
            // that completes after uploading fewer bytes than declared) would otherwise
            // commit a TRUNCATED file as whole — the server, not the client, decides the
            // object is complete. Rejected BEFORE the staged temp is written,
            // so nothing downstream ever sees the short object.
            $total = $session->total_size_bytes;

            if ($total !== null && $assembledSize !== $total) {
                throw ValidationException::withMessages([
                    'parts' => "Cannot complete upload session {$session->upload_id}: assembled {$assembledSize} bytes but {$total} were declared.",
                ]);
            }

            $sizeBucket = AssembleSizeBucket::forBytes($assembledSize);

            $this->metrics->counter(
                name: 'vaulter_assemble_bytes_total',
                amount: $assembledSize,
                labels: ['size_bucket' => $sizeBucket],
            );

            $this->metrics->histogram(
                name: 'vaulter_assemble_duration_seconds',
                value: $assembleDuration,
                labels: ['size_bucket' => $sizeBucket],
            );

            rewind($assembled);
            $size = $this->staged->writeStream(session: $session, stream: $assembled);
        } finally {
            fclose($assembled);
        }

        $this->sessions->advanceReceived(session: $session, receivedBytes: $size);

        return $size;
    }

    /**
     * Every declared part has been recorded and stitched — completeness for multipart
     * is "the assembled total matches the declared total" (or, with an unknown total,
     * the parts cover received_bytes). Reads received_bytes which complete() set from
     * the stitched length.
     */
    public function isComplete(UploadSessionSnapshot $snapshot): bool
    {
        $session = $this->current($snapshot);
        $total = $session->total_size_bytes;

        if ($total === null) {
            // Unknown total: complete once the staged assembled temp exists.
            return $this->staged->size($session) > 0;
        }

        return $session->received_bytes >= $total && $this->staged->size($session) > 0;
    }

    /**
     * Abort the multipart upload: with a real S3 disk this is
     * AbortMultipartUpload; in the proxied default we delete the per-part objects. The
     * assembled staged temp is discarded by the manager. Idempotent.
     */
    public function abort(UploadSessionSnapshot $snapshot): void
    {
        $session = $this->current($snapshot);
        foreach ($this->parts->forSession($session) as $part) {
            $this->driver->delete($this->partKey($session, $part->part_no), $this->staged->diskName($session));
        }
    }

    /**
     * The part's bytes, bounded by the per-chunk ceiling (`uploads.max_chunk_bytes`, 64 MiB
     * by default —review /). The proxied multipart design has to
     * hold a part in memory (it re-hashes it), so the ceiling is what keeps that bounded: we
     * read at most one byte past it, and one byte past is already proof it was crossed. The
     * controller has usually refused the request on its Content-Length long before this; this
     * is the barrier for a body that never declared one.
     *
     * @param resource $chunk
     */
    private function readPartWithinLimit(mixed $chunk): string
    {
        $max = $this->driveConfig->maxChunkSize();

        $bytes = $max > 0
            ? stream_get_contents($chunk, $max + 1)
            : stream_get_contents($chunk);

        $bytes = $bytes === false ? '' : $bytes;

        if ($max > 0 && strlen($bytes) > $max) {
            throw ValidationException::withMessages([
                'chunk' => "The upload part exceeds the maximum chunk size of {$max} bytes.",
            ]);
        }

        return $bytes;
    }

    /**
     * The total recorded part size — the sum the manager uses as received_bytes.
     */
    private function assertProjectedSize(UploadSession $session, int $projected): void
    {
        $total = $session->total_size_bytes;
        if ($total !== null && $projected > $total) {
            throw ValidationException::withMessages([
                'parts' => "Upload session {$session->upload_id} would exceed its declared {$total} bytes.",
            ]);
        }

        $max = $this->driveConfig->maxFileSize();
        if ($max > 0 && $projected > $max) {
            throw ValidationException::withMessages([
                'received_bytes' => "The upload exceeded the maximum allowed {$max} bytes.",
            ]);
        }
    }

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
