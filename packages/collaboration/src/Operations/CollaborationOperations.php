<?php

declare(strict_types=1);

namespace Vaulter\Collaboration\Operations;

use Vaulter\Attributes\Api;
use Vaulter\Attributes\Internal;
use Vaulter\Collaboration\CollaborationSessionService;
use Vaulter\Documents\Access\CollaborationAccessPolicy;
use Vaulter\Documents\Data\Collaboration\CollaborationSession;
use Vaulter\Drive\Contracts\DriveExtension;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Operations\DriveContext;

#[Api]
final readonly class CollaborationOperations implements DriveExtension
{
    #[Internal]
    public function __construct(
        private DriveContext $context,
        private CollaborationAccessPolicy $policy,
        private CollaborationSessionService $sessions,
    ) {}

    public function session(NodeSnapshot|string|int $document, ?int $clientSchemaVersion = null): CollaborationSession
    {
        $decision = $this->policy->evaluate($this->context, $document, clientSchemaVersion: $clientSchemaVersion);

        return $this->sessions->issue($decision->grant, $decision->ability);
    }
}
