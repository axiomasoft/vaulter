<?php

declare(strict_types=1);

namespace Vaulter\Drive\Actions\Files;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Drive\Events\Files\FileRevisionDeleted;
use Vaulter\Events\EventRecorder;
use Vaulter\Exceptions\Drive\CannotDeleteHeadRevisionException;
use Vaulter\Exceptions\Drive\RevisionPinnedException;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Media\Contracts\Cas\BlobStore;
use Vaulter\Models\Drive\FileRevision;
use Vaulter\Models\Drive\FileRevisionBlob;
use Vaulter\Models\Drive\Node;
use Vaulter\Operations\DriveContext;
use Vaulter\Repositories\Drive\DriveStoreRepository;
use Vaulter\Repositories\Drive\FileRevisionStoreRepository;
use Vaulter\Repositories\Drive\FileStoreRepository;
use Vaulter\Support\DriveLifecycle;
use Vaulter\Support\Identity;
use Vaulter\Support\TreeGuard;

/**
 * Delete one non-current revision of a file — the single path of the API
 * and of the retention pruning. Lock order T(shared) → N(file node) → F(v_files) →
 * R(revision) → Q(drive quota) → C(CAS edges). Under the file and revision locks the
 * head and pinned revisions are refused (P4-I19), so a concurrent new revision or pin
 * cannot slip between the choice and the delete. The revision's bytes leave the
 * quota in one conditional UPDATE (P4-I18); its blobs lose their edges and become GC
 * candidates on the existing path. The node's sequence does not move: the file's
 * content is unchanged.
 *
 * Operations authorize before this command.
 */
#[Internal]
final readonly class DeleteFileRevisionAction
{
    public const string RETENTION_REASON = 'revision-retention';

    public function __construct(
        private VaulterDatabase $database,
        private TreeGuard $tree,
        private FileStoreRepository $files,
        private FileRevisionStoreRepository $revisions,
        private DriveStoreRepository $drives,
        private BlobStore $blobs,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    public function execute(DriveContext $context, Node $node, FileRevision|int|string $revision): void
    {
        $revisionId = $revision instanceof FileRevision ? $revision->getRawOriginal($revision->getKeyName()) : $revision;

        $this->database->transaction(function () use ($context, $node, $revisionId): void {
            $this->database->lockTreeShared($context->driveId());
            $this->lifecycle->assertWritable($context);
            $node = $this->tree->locked($this->tree->lock([$node->id]), $node->id);
            if (!$this->tree->sameStorage($node, $context->driveId())) {
                throw NodeNotFoundException::forId($node->id);
            }

            // Retention explicitly includes trash. User deletes and other maintenance
            // callers must still find a live file after acquiring the node lock.
            if (!$context->actor->isSystem() || $context->actor->ref()->reason !== self::RETENTION_REASON) {
                $this->tree->assertLive($node);
            }

            $file = $this->files->lockForUpdate($node->file()->firstOrFail());
            $locked = $file->versions()->whereKey($revisionId)->lockForUpdate()->first()
                ?? throw NodeNotFoundException::forId(Identity::stringify($revisionId));
            if (Identity::stringify($file->current_revision_id) === Identity::stringify($locked->id)) {
                throw CannotDeleteHeadRevisionException::forRevision($locked->id);
            }

            if ($locked->keep_forever) {
                throw RevisionPinnedException::forRevision($locked->id);
            }

            if ($locked->size_bytes > 0) {
                $this->drives->adjustUsedBytes(storage: $context->drive, delta: -$locked->size_bytes);
            }

            foreach ($locked->blobs as $blob) {
                /** @var FileRevisionBlob $edge */
                $edge = $blob->getRelationValue('pivot');
                $this->blobs->detach(version: $locked, blob: $blob, role: $edge->role, ordinal: $edge->ordinal);
            }

            $this->revisions->delete($locked);

            $this->recorder->record(new FileRevisionDeleted(
                node: $node,
                file: $file,
                revisionId: $locked->id,
                revisionNo: $locked->number,
                sizeBytes: $locked->size_bytes,
                actor: $context->actor->ref(),
                correlationId: $context->correlationId,
            ));
        });
    }
}
