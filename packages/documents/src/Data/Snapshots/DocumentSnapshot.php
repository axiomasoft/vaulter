<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Snapshots;

use DateTimeImmutable;
use Vaulter\Contracts\Handlers\DocumentContent;

/**
 * Read-only view of a document: either its working state (what every editor sees now)
 * or, when a revision was asked for, that checkpoint's content.
 *
 * The content validator is (epoch, stateVersion): pass stateVersion back as the
 * expected state version of update()/restore(). It is independent of the node's
 * sequence, so a rename never invalidates it.
 *
 * $editable is false when the type is not available or the content comes from a
 * historical checkpoint; such content must not be saved back.
 */
final readonly class DocumentSnapshot
{
    /** @internal */
    public function __construct(
        public string $nodeId,
        public string $documentId,
        public string $type,
        public int $schemaVersion,
        public bool $typeAvailable,
        public bool $editable,
        public int $epoch,
        public int $stateVersion,
        public bool $dirty,
        public ?string $headRevisionId,
        public DocumentContent $content,
        public ?DocumentRevisionSnapshot $revision,
        public ?string $contentHash,
        public ?int $yjsStateSize,
        public DateTimeImmutable $updatedAt,
        public ?string $updatedBy,
    ) {}
}
