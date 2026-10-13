<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Cas;

use Vaulter\ValueObjects\Cas\BlobScope;

/**
 * The low-level byte side of the CAS: stage an incoming stream to a local
 * seekable temp file (hashing those durable bytes server-side), then promote the
 * staged bytes to their canonical scope-qualified key. The high-level
 * find-or-create / dedup / GC lifecycle is BlobStore — ContentStore knows only
 * bytes, hashes and keys, never the v_blobs row.
 */
interface ContentStore
{
    /**
     * Drain the incoming stream into a local seekable temp file and recompute its
     * sha256 from the staged bytes (never the live stream / client hash / S3
     * ETag).
     *
     * @param resource $stream
     */
    public function stage(mixed $stream): StagedObject;

    /**
     * Promote staged bytes to the canonical CAS key on the given disk. Idempotent
     * and concurrency-safe: when the object already exists (another uploader of
     * the same bytes won) the promotion is a no-op rather than a fatal — the
     * driver uses If-None-Match: * where supported and treats 412/409 as "already
     * there". After promotion the staged temp file may be discarded.
     */
    public function promote(StagedObject $staged, string $key, ?string $disk = null): void;

    /**
     * A read stream over the canonical object at $key, or null when absent.
     *
     * @return resource|null
     */
    public function get(string $key, ?string $disk = null): mixed;

    public function exists(string $key, ?string $disk = null): bool;

    public function delete(string $key, ?string $disk = null): void;

    /**
     * Build "<scope>/ab/<sha256>". The scope segment keeps two dedup scopes from ever sharing one
     * physical object, so one scope's GC can never delete another's live bytes.
     */
    public function keyFor(BlobScope $scope, string $sha256): string;
}
