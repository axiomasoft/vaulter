<?php

declare(strict_types=1);

namespace Vaulter\Drive\Sharing;

use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Content\DownloadGuards;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;

/** @internal Legacy availability seam; versioned evidence enforcement replaces this guard. */
#[Internal]
class ShareContentGuard
{
    public function assertAvailable(Node $node, FileRevision $revision): void
    {
        $drive = $node->storage()->first() ?? throw new NotFoundHttpException;
        app(DownloadGuards::class)->check(DriveContext::system($drive, 'share availability evaluation'), $node, $revision);
    }
}
