<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Vaulter\Database\VaulterDatabase;
use Vaulter\Documents\Configuration\DocumentsConfig;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Data\Documents\UpdateDocumentContentInput;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\NotADocumentException;
use Vaulter\Documents\Repositories\Documents\DocumentReadRepository;
use Vaulter\Documents\ValueObjects\TipTapContent;
use Vaulter\Exceptions\Nodes\NodeNotFoundException;
use Vaulter\Support\TreeGuard;

/**
 * Write a document node's content — the autosave path (§2.5 / M4a). The working
 * state is replaced; an autosave checkpoint is taken only when the checkpoint
 * interval has passed, so a typing session does not flood the history and no
 * stored checkpoint is ever rewritten. Replacing the JSON of a document that has
 * Yjs state drops that state and moves the collaboration epoch, so open rooms
 * reload instead of merging their old history back.
 *
 * One outer transaction under T → N → D locks; a single after-commit event when the
 * write changed something.
 */
final readonly class UpdateDocumentContentAction
{
    public function __construct(
        private DocumentReadRepository $documents,
        private DocumentStateWriter $writer,
        private VaulterDatabase $database,
        private DocumentsConfig $documentsConfig,
        private TreeGuard $tree,
    ) {}

    public function execute(UpdateDocumentContentInput $data): DocumentStateResult
    {
        $node = $data->node;

        // F-P1.11-05: the ADR-0004 size limit was defined but never enforced at save
        // time — check it before the transaction, not just at the UI layer. Content
        // is handler-agnostic (DocumentContent); the limit only applies to the
        // TipTap shape, not an opaque non-rich-text snapshot (e.g. a spreadsheet).
        if ($data->content instanceof TipTapContent) {
            $data->content->ensureWithin($this->documentsConfig->maxContentSizeKb());
        }

        return $this->database->transaction(function () use ($node, $data): DocumentStateResult {
            $driveId = $node->getRawOriginal('drive_id');
            $nodeId = $node->getRawOriginal($node->getKeyName());
            if (!$node->exists || (!is_int($nodeId) && !is_string($nodeId)) || $nodeId === ''
                || (!is_int($driveId) && !is_string($driveId)) || $driveId === '') {
                throw NodeNotFoundException::forId('unpersisted');
            }
            $this->database->lockTreeShared($driveId);
            $current = $this->tree->locked($this->tree->lock([$nodeId]), $nodeId);
            if (!$this->tree->sameStorage($current, $driveId)) {
                throw NodeNotFoundException::forId($nodeId);
            }
            if (!$current->kind->isDocument()) {
                throw NotADocumentException::forNode($nodeId);
            }
            $node = $this->tree->sync($node, $current);
            $document = $this->documents->lockForNode($node) ?? throw NotADocumentException::forNode($nodeId);
            $document->setRelation('node', $node);

            $result = $this->writer->write($document, new DocumentStateChange(
                cause: StateWriteCause::Update,
                content: $data->content,
                yjsState: $data->yjsState,
                actorId: $data->actorId,
                expectedStateVersion: $data->expectedStateVersion,
            ));

            if ($result->changed) {
                event(new DocumentContentUpdated(
                    node: $node,
                    version: $result->checkpoint ?? $document->currentRevision()->firstOrFail(),
                    created: $result->checkpoint !== null,
                    actorId: $data->actorId,
                ));
            }

            return $result;
        });
    }
}
