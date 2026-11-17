<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Snapshots;

use DateTimeImmutable;
use Packman\Attributes\Api;
use Packman\Attributes\Internal;
use Vaulter\Documents\Contracts\DocumentContent;

/**
 * Read-only view of a document: either its working state (what every editor sees now)
 * or, when a revision was asked for, that checkpoint's content.
 *
 * The content validator is (epoch, stateVersion): pass stateVersion back as the
 * expected state version of update()/restore(). It is independent of the node's
 * sequence, so a rename never invalidates it.
 *
 * $editable is false when the type is not installed ($typeAvailable = false; the
 * content is then opaque JSON), when the content comes from a historical checkpoint,
 * when reading it had to drop parts its type no longer accepts, or when it was written
 * in a newer schema version than the installed type's. Such content must not be saved
 * back: update() refuses it.
 */
#[Api]
final readonly class DocumentSnapshot
{
    #[Internal]
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
        public ?int $collabStateSize,
        public DateTimeImmutable $updatedAt,
        public ?string $updatedBy,
        public ?string $collabProtocol = null,
        public ?string $collabStateFormat = null,
    ) {}
}
