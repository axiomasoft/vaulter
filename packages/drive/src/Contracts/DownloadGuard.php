<?php

declare(strict_types=1);

namespace Vaulter\Drive\Contracts;

use Packman\Attributes\Spi;
use Packman\Attributes\SpiKind;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Operations\DriveContext;

/**
 * May veto every delivery of file content (slot `drive`, Guard, any number): the download, the public-share
 * download and the direct-signed URL all call every active guard before a byte or a capability leaves.
 * A guard denies by throwing; any exception, not only {@see ContentUnavailableException}, denies (fail-closed).
 *
 * The delivery path has already authorized the caller (or resolved and refreshed the share) and picked the revision
 * before any guard runs. A guard reads the immutable facts it is given; the share snapshot describes the link, it is
 * no bearer capability, and a guard that needs the current state reads it by id.
 */
#[Spi(SpiKind::Implement)]
interface DownloadGuard
{
    /** @throws ContentUnavailableException to deny; the caller's own context decides the lane */
    public function check(DriveContext|ShareLinkSnapshot $context, NodeSnapshot $node, FileRevisionSnapshot $revision): void;
}
