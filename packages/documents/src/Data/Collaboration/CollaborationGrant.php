<?php

declare(strict_types=1);

namespace Vaulter\Documents\Data\Collaboration;

use Packman\Attributes\Api;
use Vaulter\ValueObjects\Access\ActorRef;

/** Authorized limits for one node (nodeId is never the document aggregate id). */
#[Api]
final readonly class CollaborationGrant
{
    public function __construct(
        public string $nodeId,
        public string $driveId,
        public ?string $tenant,
        public ActorRef $principal,
        public CollaborationMode $mode,
        public int $generation,
        public int $schemaVersion,
        public string $documentType,
        public string $protocol,
        public string $stateFormat,
        public int $notAfter,
    ) {}
}
