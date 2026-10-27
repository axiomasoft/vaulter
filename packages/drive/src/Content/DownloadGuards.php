<?php

declare(strict_types=1);

namespace Vaulter\Drive\Content;

use Throwable;
use Vaulter\Attributes\Internal;
use Vaulter\Drive\Contracts\DownloadGuard;
use Vaulter\Drive\Data\Snapshots\FileRevisionSnapshot;
use Vaulter\Drive\Data\Snapshots\Mapping\AccessSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\FileRevisionSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Data\Snapshots\NodeSnapshot;
use Vaulter\Drive\Data\Snapshots\ShareLinkSnapshot;
use Vaulter\Drive\Exceptions\Files\ContentUnavailableException;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Sharing\ShareContext;
use Vaulter\Exceptions\Contracts\ProblemException;
use Vaulter\Kernel;

/**
 * Runs every active {@see DownloadGuard} on each delivery path, in the order of the slot. The first guard
 * that throws denies; a guard that throws anything but a problem is a denial too, never a pass.
 */
#[Internal]
final readonly class DownloadGuards
{
    public function __construct(private Kernel $kernel, private NodeSnapshotMapper $nodes, private FileRevisionSnapshotMapper $revisions, private AccessSnapshotMapper $shares) {}

    public function check(DriveContext|ShareContext $context, Node $node, FileRevision $revision): void
    {
        // The rows the delivery path selected, seen once as the immutable facts every guard reads.
        $this->checkSnapshots(
            $context instanceof ShareContext ? $this->shares->share($context->share) : $context,
            $this->nodes->facts($node),
            $this->revisions->map($revision, $node->file()->first() ?? throw NodeNotFoundException::forId($node->id)),
        );
    }

    /** The guard chain itself, over the facts check() mapped once. */
    public function checkSnapshots(DriveContext|ShareLinkSnapshot $context, NodeSnapshot $node, FileRevisionSnapshot $revision): void
    {
        foreach ($this->kernel->extensions()->resolve(DownloadGuard::class) as $guard) {
            try {
                $guard->check($context, $node, $revision);
            } catch (ProblemException $denied) {
                throw $denied;
            } catch (Throwable) {
                throw new ContentUnavailableException;
            }
        }
    }
}
