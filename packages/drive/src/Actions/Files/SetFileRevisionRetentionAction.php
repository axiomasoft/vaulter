<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Files;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Models\FileRevision;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Repositories\FileRevisionStoreRepository;
use Vaulter\Drive\Repositories\FileStoreRepository;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Support\Identity;

/**
 * Pin (keep forever) or unpin one revision of a file. Takes the same
 * T(shared) → N → F → R locks as DeleteFileRevisionAction, so a delete or prune
 * either sees the pin or finished before it. keep_forever is the only column of a
 * revision that changes after its insert; the node's sequence does not move.
 *
 * Operations authorize before this command.
 */
#[Internal]
final readonly class SetFileRevisionRetentionAction
{
    public function __construct(
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private FileStoreRepository $files,
        private FileRevisionStoreRepository $revisions,
        private DriveLifecycle $lifecycle,
    ) {}

    public function execute(DriveContext $context, Node $node, FileRevision|int|string $revision, bool $keepForever): FileRevision
    {
        $revisionId = $revision instanceof FileRevision ? $revision->getRawOriginal($revision->getKeyName()) : $revision;

        return $this->database->transaction(function () use ($context, $node, $revisionId, $keepForever): FileRevision {
            $this->database->lockTreeShared($node->drive_id);
            $this->lifecycle->assertWritable($context);
            $node = $this->tree->locked($this->tree->lock([$node->id]), $node->id);
            $this->tree->assertLive($node);
            $file = $this->files->lockForUpdate($node->file()->firstOrFail());
            $locked = $file->versions()->whereKey($revisionId)->whereIn('status', ['committed', 'superseded'])->lockForUpdate()->first()
                ?? throw NodeNotFoundException::forId(Identity::stringify($revisionId));

            return $locked->keep_forever === $keepForever ? $locked : $this->revisions->setKeepForever($locked, $keepForever);
        });
    }
}
