<?php

declare(strict_types=1);

namespace Vaulter\Drive\Uploads;

use Illuminate\Contracts\Filesystem\Filesystem;
use Vaulter\Contracts\Cas\StorageDriver;
use Vaulter\Drive\Uploads\Models\UploadSession;
use Vaulter\Models\Drive\Storage;

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
        $storage = $session->storage instanceof Storage
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
     * write is offset-aware: it reads the existing staged bytes, concatenates the new
     * chunk and rewrites the temp, so a resumed PATCH from the authoritative offset
     * stitches the file back together deterministically. Returns the new staged
     * length.
     *
     * @param resource $chunk
     */
    public function append(UploadSession $session, mixed $chunk, int $offset): int
    {
        $disk = $this->diskFor($session);
        $key = $this->keyFor($session);

        $existing = '';

        if ($disk->exists($key)) {
            $existing = (string) $disk->get($key);
        }

        // Honour the authoritative offset: anything the client claims past the bytes
        // we already hold is truncated to our offset (the server, not the client,
        // owns the resume point — H.3 / §2.8).
        $head = mb_substr($existing, 0, $offset, '8bit');

        $appended = stream_get_contents($chunk);
        $bytes = $head.($appended === false ? '' : $appended);

        $disk->put($key, $bytes);

        return strlen($bytes);
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
