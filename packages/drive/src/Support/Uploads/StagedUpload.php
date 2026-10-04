<?php

declare(strict_types=1);

namespace Vaulter\Drive\Support\Uploads;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Drive\Exceptions\StagedUploadMissingException;
use Vaulter\Drive\Models\Uploads\UploadSession;
use Vaulter\Models\Drive\Drive;
use Vaulter\Support\Config;

/**
 * The staged temp for a resumable upload (§2.8 / H.3). The bytes accumulate on the
 * SESSION'S disk under a tmp/ prefix keyed by the upload_id, so they live next to
 * the eventual CAS objects (same disk, reclaimable by the sweeper) and never touch
 * the canonical CAS key until BlobStore.commit promotes them.
 *
 * A tus PATCH APPENDS to this temp; the assembled stream is read back from it and
 * fed to BlobStore.commit (which re-hashes it server-side — the temp's bytes, never
 * the client's claim). On commit OR abort the temp is removed.
 *
 * This is intentionally NOT the CAS ContentStore: the CAS stages to a LOCAL seekable
 * file for hashing, whereas this stages to the (possibly remote) session disk so a
 * multi-GB resumable upload survives across requests. The commit path streams this
 * back through ContentStore::stage, so the H.3 invariant (hash the local staged
 * file) still holds at the moment of commit.
 */
final readonly class StagedUpload
{
    /**
     * The in-memory ceiling for the assembly buffer before it spills to a temp file
     * on disk (§2.8 / F-P1.4-04): peak memory stays bounded by this, never the full
     * staged file, no matter how large the resumable upload grows.
     */
    private const int SPILL_BYTES = 2_097_152; // 2 MiB

    public function __construct(
        private StorageDriver $driver,
    ) {}

    /**
     * The tmp/ staging key for a session on its disk (§2.8). Keyed by upload_id so a
     * second session never collides, and prefixed tmp/ so the sweeper / an S3
     * lifecycle rule can reclaim abandoned staging in bulk.
     */
    public function keyFor(UploadSession $session): string
    {
        return "tmp/uploads/{$session->upload_id}";
    }

    /**
     * The resolved Flysystem disk a session stages onto — the session's storage disk,
     * or the install default when the storage relation is not loaded/present.
     */
    public function diskFor(UploadSession $session): Filesystem
    {
        return $this->driver->disk($this->diskName($session));
    }

    /**
     * The disk NAME a session stages onto (null → the install default disk). The
     * S3-multipart handler uses this to address its per-part objects through the
     * StorageDriver.
     */
    public function diskName(UploadSession $session): ?string
    {
        $storage = $session->storage instanceof Drive
            ? $session->storage
            : $session->storage()->first();

        return $storage?->disk;
    }

    /**
     * The current staged length on disk — the source of truth the manager reconciles
     * received_bytes against (a crashed PATCH must not advance the offset past the
     * bytes that actually landed). 0 when nothing has been staged yet.
     */
    public function size(UploadSession $session): int
    {
        $disk = $this->diskFor($session);
        $key = $this->keyFor($session);

        if (!$disk->exists($key)) {
            return 0;
        }

        return (int) $disk->size($key);
    }

    /**
     * Append a chunk's bytes to the staged temp at $offset (§2.8 tus PATCH). The
     * write is offset-aware and STREAMING (F-P1.4-04): the already-staged bytes up
     * to the authoritative offset are streamed into a spill-to-disk assembly buffer
     * followed by the new chunk, and the result is written back — the full staged
     * file is NEVER read into a PHP string, so peak memory stays bounded by the
     * spill ceiling regardless of upload size. A per-chunk ceiling is enforced
     * BEFORE any bytes are staged. Returns the new staged length.
     *
     * FAIL-CLOSED (VLT-REMED P5.2 / R-03): a positive $offset with NO staged object is
     * an impossible state — the bytes it names were accepted and acknowledged. It only
     * happens when the object was removed underneath us (a concurrent sweep/abort — see
     * R-02 — or an out-of-band bucket cleanup). Continuing would write the new chunk
     * alone, drop the already-accepted prefix and hand the client a REGRESSED offset:
     * a corrupted file with no error anywhere. We refuse instead.
     *
     * @param resource $chunk
     */
    public function append(UploadSession $session, mixed $chunk, int $offset): int
    {
        $this->assertChunkWithinLimit($chunk);

        $disk = $this->diskFor($session);
        $key = $this->keyFor($session);

        if ($offset > 0 && !$disk->exists($key)) {
            throw StagedUploadMissingException::forSession($session->upload_id, $offset);
        }

        // A spill-to-disk buffer: it holds up to SPILL_BYTES in RAM then transparently
        // spills to a temp file, so assembling a multi-GB staged file peaks at the
        // ceiling, not the file size (F-P1.4-04).
        $buffer = fopen('php://temp/maxmemory:'.self::SPILL_BYTES, 'r+b');

        if ($buffer === false) {
            throw new RuntimeException('Unable to open an assembly buffer for the upload chunk.');
        }

        try {
            // Honour the authoritative offset: stream only the bytes we already hold
            // up to it (anything past it is dropped — the server, not the client, owns
            // the resume point, H.3 / §2.8). Streamed, never materialised as a string.
            // The object's existence is guaranteed above (fail-closed, R-03).
            if ($offset > 0) {
                $existing = $disk->readStream($key);

                if (is_resource($existing)) {
                    try {
                        stream_copy_to_stream($existing, $buffer, $offset);
                    } finally {
                        fclose($existing);
                    }
                }
            }

            $this->copyChunkWithinLimit($chunk, $buffer);

            $size = ftell($buffer);
            rewind($buffer);
            $disk->writeStream($key, $buffer);
        } finally {
            fclose($buffer);
        }

        return is_int($size) ? $size : 0;
    }

    /**
     * Whether $chunk's bytes are IDENTICAL to what already sits on the staged temp
     * at [$offset, $offset+len($chunk)) (§2.8 / F-P1.4-02, N3 C6): the ONLY case tus
     * permits treating an `offset < server` PATCH as an idempotent retransmit rather
     * than a 409 Conflict. A chunk longer than what is already staged, or whose
     * bytes disagree anywhere in the overlap, is NOT a match — the caller rejects it.
     * The chunk is read into memory (bounded by the same per-request body size the
     * controller already materialises for every PATCH — no new ceiling introduced);
     * the ALREADY-staged range is read back in bounded blocks, never the whole file.
     *
     * @param resource $chunk
     */
    public function rangeMatches(UploadSession $session, mixed $chunk, int $offset): bool
    {
        $chunkBytes = stream_get_contents($chunk);

        if ($chunkBytes === false) {
            return false;
        }

        $length = strlen($chunkBytes);

        if ($length === 0) {
            // A zero-length retransmit trivially matches whatever already sits there.
            return true;
        }

        $disk = $this->diskFor($session);
        $key = $this->keyFor($session);

        if (!$disk->exists($key) || $disk->size($key) < $offset + $length) {
            return false;
        }

        $existing = $disk->readStream($key);

        if (!is_resource($existing)) {
            return false;
        }

        try {
            if ($offset > 0) {
                $this->discardBytes($existing, $offset);
            }

            return fread($existing, $length) === $chunkBytes;
        } finally {
            fclose($existing);
        }
    }

    /**
     * Advance a (possibly non-seekable) stream past $bytes without materialising
     * them as one block — read-and-drop in bounded chunks so the discard itself
     * never grows memory past the spill ceiling, however far into the staged file
     * $bytes reaches.
     *
     * @param resource $stream
     */
    private function discardBytes(mixed $stream, int $bytes): void
    {
        $remaining = $bytes;

        while ($remaining > 0 && !feof($stream)) {
            $read = fread($stream, min($remaining, self::SPILL_BYTES));

            if ($read === false || $read === '') {
                break;
            }

            $remaining -= strlen($read);
        }
    }

    /**
     * Reject a PATCH chunk larger than the per-chunk hard ceiling BEFORE any bytes are
     * staged (§2.8 / F-P1.4-04 / U3.3): an unbounded single PATCH is a memory/IO DoS
     * vector. This is `uploads.max_chunk_bytes` — the hard cap — NOT `chunk_size`, which
     * is only the advertised HINT. 0 disables the cap; the shipped default does NOT (it
     * is 64 MiB — VLT-REMED P5.2 review).
     *
     * This is the CHEAP pre-check and it only sees a stat-able stream (a seekable temp).
     * The real request body is `php://input`, whose fstat size is 0 — for that one the
     * barrier is {@see copyChunkWithinLimit()}, on the copy itself. Both are needed: the
     * caller may hand us either.
     *
     * @param resource $chunk
     */
    private function assertChunkWithinLimit(mixed $chunk): void
    {
        $max = Config::uploadMaxChunkBytes();

        if ($max <= 0) {
            return;
        }

        $stat = is_resource($chunk) ? fstat($chunk) : false;
        $size = is_array($stat) ? (int) $stat['size'] : 0;

        if ($size > $max) {
            $this->rejectOversizeChunk($size, $max);
        }
    }

    /**
     * Copy the chunk into the assembly buffer, refusing to read past the per-chunk ceiling
     * (VLT-REMED P5.2 review). A body whose length we cannot know up front (`php://input`
     * under chunked transfer-encoding, or any non-stat-able stream) would otherwise slip
     * past {@see assertChunkWithinLimit()} entirely — the check that the docblock there
     * promises happens "BEFORE any bytes are staged" — and stream in unbounded. We read at
     * most $max+1 bytes: one byte past the ceiling is already proof it was crossed, and the
     * refusal still happens before `writeStream()`, so nothing is staged.
     *
     * @param resource $chunk
     * @param resource $buffer
     */
    private function copyChunkWithinLimit(mixed $chunk, mixed $buffer): void
    {
        $max = Config::uploadMaxChunkBytes();

        if ($max <= 0) {
            stream_copy_to_stream($chunk, $buffer);

            return;
        }

        $copied = stream_copy_to_stream($chunk, $buffer, $max + 1);

        if ($copied !== false && $copied > $max) {
            // We stopped reading at the ceiling, so the true body length is unknown — say
            // only what we KNOW, never a size we did not measure.
            $this->rejectOversizeChunk(null, $max);
        }
    }

    private function rejectOversizeChunk(?int $size, int $max): never
    {
        $measured = $size === null ? 'The upload chunk' : "The upload chunk of {$size} bytes";

        throw ValidationException::withMessages([
            'chunk' => "{$measured} exceeds the maximum chunk size of {$max} bytes.",
        ]);
    }

    /**
     * Overwrite the staged temp with $bytes in one shot (§2.8 S3-multipart assemble):
     * the multipart parts have been stitched into the assembled object, which is
     * written here so the commit path can stream it through ContentStore::stage and
     * re-hash it server-side.
     */
    public function write(UploadSession $session, string $bytes): int
    {
        $disk = $this->diskFor($session);
        $disk->put($this->keyFor($session), $bytes);

        return strlen($bytes);
    }

    /**
     * Stream an assembled upload into the staged object without materialising the
     * complete payload in PHP memory.
     *
     * @param resource $stream
     */
    public function writeStream(UploadSession $session, mixed $stream): int
    {
        if (!is_resource($stream)) {
            throw new RuntimeException('Unable to stage upload from a non-resource stream.');
        }

        if (!$this->driver->put($this->keyFor($session), $stream, $this->diskName($session))) {
            throw new RuntimeException("Unable to write staged upload [{$session->upload_id}].");
        }

        return $this->size($session);
    }

    /**
     * A read stream over the staged temp, or null when nothing is staged. The commit
     * path feeds this to BlobStore.commit (which stages it locally and re-hashes —
     * H.3).
     *
     * @return resource|null
     */
    public function readStream(UploadSession $session): mixed
    {
        $disk = $this->diskFor($session);
        $key = $this->keyFor($session);

        if (!$disk->exists($key)) {
            return null;
        }

        $stream = $disk->readStream($key);

        return is_resource($stream) ? $stream : null;
    }

    /**
     * Remove the staged temp (§2.8) — called on commit AND on abort/expire so an
     * abandoned upload never leaves bytes behind. Idempotent.
     */
    public function discard(UploadSession $session): void
    {
        $disk = $this->diskFor($session);
        $key = $this->keyFor($session);

        if ($disk->exists($key)) {
            $disk->delete($key);
        }
    }
}
