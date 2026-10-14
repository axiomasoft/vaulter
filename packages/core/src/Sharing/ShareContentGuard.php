<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Content\DownloadGuards;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;

/** @internal Legacy availability seam; versioned evidence enforcement replaces this guard. */
class ShareContentGuard
{
    public function assertAvailable(Node $node, FileRevision $revision): void
    {
        $drive = $node->storage()->first() ?? throw new NotFoundHttpException;
        app(DownloadGuards::class)->check(DriveContext::system($drive, 'share availability evaluation'), $node, $revision);
    }
}
