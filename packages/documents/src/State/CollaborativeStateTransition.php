<?php

declare(strict_types=1);

namespace Vaulter\Documents\State;

use InvalidArgumentException;
use Packman\Attributes\Api;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\ValueObjects\Access\ActorRef;

/** Prepared and authorized outside the state transaction; attribution grants no authority. */
#[Api]
final readonly class CollaborativeStateTransition
{
    public function __construct(
        public ?CollaborationStateBinding $expectedBinding,
        public int $expectedStateVersion,
        public string $documentType,
        public int $schemaVersion,
        public ?string $protocol,
        public ?string $stateFormat,
        public DocumentContent $content,
        public ?string $collabState,
        public ActorRef $actor,
        public string $correlationId,
        public ?DocumentWriteMode $writeMode = null,
    ) {
        if ($expectedStateVersion < 0 || $schemaVersion < 1 || trim($documentType) === '' || trim($correlationId) === ''
            || (($protocol === null) !== ($stateFormat === null)) || ($protocol !== null && (trim($protocol) === '' || trim($stateFormat ?? '') === ''))
            || ($protocol === null && $collabState !== null) || $collabState === ''
            || ($writeMode === DocumentWriteMode::Journal && $collabState === null)
            || ($writeMode === DocumentWriteMode::Snapshot && $collabState !== null)) {
            throw new InvalidArgumentException('[Vaulter] Invalid prepared state transition.');
        }
    }
}
