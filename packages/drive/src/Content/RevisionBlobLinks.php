<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Repositories\FileRevisionBlobStoreRepository;
use Vaulter\Media\Contracts\Cas\BlobPins;
use Vaulter\Media\Exceptions\Cas\BlobUnavailableException;
use Vaulter\Media\Models\Cas\Blob;
use Vaulter\Support\Identity;

/**
 * The drive's links between a file revision and its blobs: the edge in v_file_revision_blobs and the media pin
 * that keeps the blob alive, always written together inside the caller's transaction — the only writer of that edge
 * table. Lock order: the caller's drive locks (tree, node, file, revision) come first, then the pin takes the lock of
 * the blob.
 *
 * The pin's reference key is the edge's own key without the blob, "{file_revision_id}:{role}:{ordinal}", so a purge,
 * rollback or repair addresses exactly the pins of its rows. A revision row is never deleted with live edges: every
 * purge path calls {@see detachAll()} first — the foreign-key cascade would drop the edges and leave their pins
 * forever.
 */
#[Internal]
final readonly class RevisionBlobLinks
{
    public const string OWNER_TYPE = 'drive.revision_blob';

    public function __construct(
        private FileRevisionBlobStoreRepository $edges,
        private BlobPins $pins,
    ) {}

    /** The pin reference key of an edge; the revision key is a model key (Eloquent types it mixed). */
    public static function referenceKey(mixed $revisionId, string $role = 'data', int $ordinal = 0): string
    {
        $key = Identity::key($revisionId) ?? throw new LogicException('[Vaulter] A revision link needs a persisted revision.');

        return Identity::stringify($key).':'.$role.':'.$ordinal;
    }

    /**
     * Pin the blob, then write the edge. The pin comes first: a blob whose bytes are gone is refused before any row
     * is written.
     *
     * @param array{role?: string, ordinal?: int, offset_bytes?: int|null, length_bytes?: int|null} $attributes
     *
     * @throws BlobUnavailableException when the blob's bytes are gone; a caller holding the bytes commits them again
     */
    public function attach(FileRevision $revision, Blob $blob, array $attributes = []): void
    {
        $this->pins->pin(
            blob: Identity::stringify($blob->getKey()),
            ownerType: self::OWNER_TYPE,
            referenceKey: self::referenceKey($revision->getKey(), $attributes['role'] ?? 'data', $attributes['ordinal'] ?? 0),
        );
        $this->edges->attach(version: $revision, blob: $blob, attributes: $attributes);
    }

    /** Delete the pin and the edge. */
    public function detach(FileRevision $revision, Blob|int|string $blob, string $role = 'data', int $ordinal = 0): void
    {
        $this->pins->unpin(blob: $blob instanceof Blob ? Identity::stringify($blob->getKey()) : $blob, ownerType: self::OWNER_TYPE, referenceKey: self::referenceKey($revision->getKey(), $role, $ordinal));
        $this->edges->detach(version: $revision, blob: $blob, role: $role, ordinal: $ordinal);
    }

    /**
     * Delete every edge of the given revisions and their pins — before the revisions themselves are deleted.
     *
     * @param  iterable<mixed> $revisionIds model keys
     * @return int             the number of edges removed
     */
    public function detachAll(iterable $revisionIds): int
    {
        $removed = 0;
        $chunk = [];
        foreach ($revisionIds as $id) {
            $chunk[] = Identity::key($id) ?? throw new LogicException('[Vaulter] A revision link needs a persisted revision.');
            if (count($chunk) === 500) {
                $removed += $this->detachChunk($chunk);
                $chunk = [];
            }
        }

        return $removed + $this->detachChunk($chunk);
    }

    /** @param list<int|string> $revisionIds */
    private function detachChunk(array $revisionIds): int
    {
        foreach ($this->edges->forRevisions($revisionIds) as $edge) {
            $this->pins->unpin(
                blob: $edge['blob_id'],
                ownerType: self::OWNER_TYPE,
                referenceKey: self::referenceKey($edge['file_revision_id'], $edge['role'], $edge['ordinal']),
            );
        }

        return $this->edges->deleteForRevisions($revisionIds);
    }
}
