<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Documents;

use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Models\Revisions\DocumentRevision;

/**
 * One write of a document's working state. A null $content keeps the current JSON
 * projection (a collaboration store may carry only Yjs state); $yjsState is written
 * as given, so null clears it. $schemaVersion is the schema the JSON is in after the
 * write — the caller's, never assumed: a write that keeps the JSON keeps its version.
 *
 * @internal
 */
final readonly class DocumentStateChange
{
    public function __construct(
        public StateWriteCause $cause,
        public ?DocumentContent $content,
        public int $schemaVersion,
        public ?string $yjsState = null,
        public int|string|null $actorId = null,
        public ?int $expectedEpoch = null,
        public ?int $expectedStateVersion = null,
        public bool $forceCheckpoint = false,
        public ?string $label = null,
        public bool $keepForever = false,
        public ?DocumentRevision $restoredFrom = null,
    ) {}
}
