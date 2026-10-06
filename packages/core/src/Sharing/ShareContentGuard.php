<?php

declare(strict_types=1);

namespace Vaulter\Sharing;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;

/** @internal Legacy availability seam; versioned evidence enforcement replaces this guard. */
class ShareContentGuard
{
    public function assertAvailable(Node $node, FileRevision $revision): void
    {
        if (!in_array($revision->scan_status, ['not_required', 'clean'], true)) {
            throw new HttpException(423, 'Content is not available.');
        }
    }
}
