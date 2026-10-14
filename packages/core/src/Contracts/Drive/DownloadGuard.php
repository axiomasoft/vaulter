<?php

declare(strict_types=1);

namespace Vaulter\Contracts\Drive;

use Vaulter\Exceptions\Files\ContentUnavailableException;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Sharing\ShareContext;

/**
 * May veto every delivery of file content (slot `drive`, Guard, any number): the download, the public-share
 * download and the direct-signed URL all call every active guard before a byte or a capability leaves.
 * A guard denies by throwing; any exception, not only {@see ContentUnavailableException}, denies (fail-closed).
 */
interface DownloadGuard
{
    /** @throws ContentUnavailableException to deny; the caller's own context decides the lane */
    public function check(DriveContext|ShareContext $context, Node $node, FileRevision $revision): void;
}
