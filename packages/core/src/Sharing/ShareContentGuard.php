<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Content\ContentAvailabilityGuard;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\OperationContext;

/** @internal Legacy availability seam; versioned evidence enforcement replaces this guard. */
class ShareContentGuard
{
    public function assertAvailable(Node $node, FileRevision $revision): void
    {
        $drive = $node->storage()->first() ?? throw new NotFoundHttpException;
        app(ContentAvailabilityGuard::class)->assertAvailable(OperationContext::system($drive, 'share availability evaluation'), $node, $revision);
    }
}
