<?php

declare(strict_types=1);

namespace Vaulter\Documents\ValueObjects;

/**
 * The rendered file of one document export, with the source it was rendered from:
 * a chosen checkpoint (sourceRevisionId set) or the working state at
 * (sourceEpoch, sourceStateVersion). A host that caches renders keys them on that
 * source, never on the document id alone. Immutable.
 */
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
    ) {}
}
