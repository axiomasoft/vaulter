<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Vaulter\Attributes\Internal;
use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\DocumentContentDegradedException;
use Vaulter\Documents\Exceptions\DocumentTypeUnavailableException;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Drive\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Drive\Support\DriveLifecycle;
use Vaulter\Drive\Support\TreeGuard;
use Vaulter\Events\EventRecorder;

/**
 * Write a document node's content — the autosave path. The working
 * state is replaced; an autosave checkpoint is taken only when the checkpoint
 * interval has passed, so a typing session does not flood the history and no
 * stored checkpoint is ever rewritten. Replacing the JSON of a document that has
 * Yjs state drops that state and moves the collaboration epoch, so open rooms
 * reload instead of merging their old history back. The working state it replaces
 * must be editable (ContentDecoder::editable()), checked on the locked row.
 *
 * One outer transaction under T → N → D locks; a single after-commit event when the
 * write changed something.
 */
#[Internal]
final readonly class UpdateDocumentContentAction
{
    public function __construct(
        private DocumentReadRepository $documents,
        private DocumentStateWriter $writer,
        private VaulterDatabase $database,
        private DocumentsConfig $documentsConfig,
        private TreeGuard $tree,
        private ContentDecoder $decoder,
        private DriveLifecycle $lifecycle,
        private EventRecorder $recorder,
    ) {}

    /**
     * @throws DocumentTypeUnavailableException
     * @throws DocumentContentDegradedException
     */
    public function execute(DriveContext $context, UpdateDocumentContentInput $data): DocumentStateResult
    {
        $node = $data->node;

        // the docs/adr/0004-content-format.md size limit was defined but never enforced at save
        // time — check it before the transaction, not just at the UI layer. Callers
        // that decode through the type already applied its own limit.
        if ($data->content instanceof TipTapContent) {
            $data->content->ensureWithin($this->documentsConfig->maxContentSizeKb());
        }

        return $this->database->transaction(function () use ($context, $node, $data): DocumentStateResult {
            // The node must live in the operation's drive; the locked row is checked below.
            $driveId = $context->driveId();
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === '' || $driveId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            $this->database->lockTreeShared($driveId);
            $this->lifecycle->assertWritable($context);
            $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
            if (!$this->tree->sameStorage($current, $driveId)) {
                throw NodeNotFoundException::forId($nodeId);
            }
            // A trash that committed before this lock leaves nothing to write to.
            $this->tree->assertLive($current);
            if (!$current->kind->isDocument()) {
                throw NotADocumentException::forNode($nodeId);
            }
            $node = $this->tree->sync($node, $current);
            $document = $this->documents->lockForNode($node) ?? throw NotADocumentException::forNode($nodeId);
            $document->setRelation('node', $node);
            // A restore may have committed between the caller's check and this lock.
            $type = $this->decoder->editableType($document);

            $result = $this->writer->write($document, new DocumentStateChange(
                cause: StateWriteCause::Update,
                content: $data->content,
                schemaVersion: $type->schemaVersion(),
                yjsState: $data->yjsState,
                actorId: $context->actor->ref()->id,
                expectedStateVersion: $data->expectedStateVersion,
            ));

            if ($result->changed) {
                $this->recorder->record(new DocumentContentUpdated(
                    node: $node,
                    version: $result->checkpoint ?? $document->currentRevision()->firstOrFail(),
                    created: $result->checkpoint !== null,
                    stateVersion: $result->document->state_version,
                    epoch: $result->document->collab_epoch,
                    actor: $context->actor->ref(),
                    correlationId: $context->correlationId,
                ));
            }

            return $result;
        });
    }
}
