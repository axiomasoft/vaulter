<?php

declare(strict_types=1);

namespace Vaulter\Documents\ValueObjects;

use Vaulter\Attributes\Api;

/**
 * The rendered file of one document export, with the source it was rendered from:
 * a chosen checkpoint (sourceRevisionId set) or the working state at
 * (sourceEpoch, sourceStateVersion). A host that caches renders keys them on that
 * source, never on the document id alone. Immutable.
 *
 * `sourceEpoch` is always the DOCUMENT's collaboration epoch at the moment of the
 * export — also for a checkpoint export, because a checkpoint records no epoch of its
 * own. A checkpoint is identified by `sourceRevisionId` and its own `sourceStateVersion`;
 * the epoch then only tells which room generation was current when it was rendered.
 *
 * `lossy` is true when the stored content could not be read whole — a document written
 * by a newer schema, or content its type reads only partly — so the bytes are what the
 * type could read, not necessarily everything that is stored. A host should not cache
 * or archive such a render as the document's export.
 */
#[Api]
final readonly class DocumentExport
{
    public function __construct(
        public string $bytes,
        public string $filename,
        public string $mimeType,
        public string $format,
        public int $sourceEpoch,
        public int $sourceStateVersion,
        public int|string|null $sourceRevisionId,
        public bool $lossy = false,
    ) {}
}
