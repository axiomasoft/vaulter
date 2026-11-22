<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Closure;
use Packman\Attributes\Internal;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Content\DocumentStateWriter;
use Vaulter\Documents\Content\TipTap\BlockIdentity;
use Vaulter\Documents\Content\TipTap\TipTapContent;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\RequiresCommandWrite;
use Vaulter\Documents\Contracts\SupportsCollaboration;
use Vaulter\Documents\Data\Documents\DocumentMutationState;
use Vaulter\Documents\Data\Documents\DocumentStateChange;
use Vaulter\Documents\Data\Documents\DocumentStateResult;
use Vaulter\Documents\Data\Snapshots\Mapping\DocumentSnapshotMapper;
use Vaulter\Documents\Enums\Documents\DocumentWriteMode;
use Vaulter\Documents\Enums\Documents\StateWriteCause;
use Vaulter\Documents\Events\Documents\DocumentContentUpdated;
use Vaulter\Documents\Exceptions\CollaborationUnavailableException;
use Vaulter\Documents\Exceptions\DocumentCommandRequiredException;
use Vaulter\Documents\Exceptions\StaleDocumentStateException;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Documents\Types\RichTextDocumentType;
use Vaulter\Drive\Data\Snapshots\Mapping\NodeSnapshotMapper;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Events\EventRecorder;
use Vaulter\Http\Support\RepresentationTag;
use Vaulter\Support\Identity;

/** Validate and replace working content while the caller holds T, N and D. */
#[Internal]
final readonly class WriteLockedDocumentContent
{
    public function __construct(
        private DocumentStateWriter $writer,
        private ContentDecoder $decoder,
        private EventRecorder $recorder,
        private PendingStateGuard $pending,
        private NodeSnapshotMapper $nodeSnapshots,
        private DocumentSnapshotMapper $documentSnapshots,
    ) {}

    /**
     * @param array<string, mixed>|DocumentContent $content
     * @param (Closure(string): bool)|null         $matchesTag
     */
    public function execute(
        DriveContext $context,
        Node $node,
        Document $document,
        array|DocumentContent $content,
        ?int $expectedStateVersion,
        ?Closure $matchesTag = null,
        bool $command = false,
    ): DocumentStateResult {
        if ($document->write_mode === DocumentWriteMode::Journal) {
            throw new DocumentCommandRequiredException;
        }
        $this->pending->assertSettled($document);
        if ($matchesTag !== null && !$matchesTag(RepresentationTag::document(Identity::stringify($node->id), $document->collab_epoch, $document->state_version))) {
            throw new StaleDocumentStateException($document->state_version, $document->state_version, Identity::stringify($node->id));
        }

        $type = $this->decoder->editableType($document);
        if (!$command && $type instanceof RequiresCommandWrite) {
            throw new DocumentCommandRequiredException;
        }
        if ($command) {
            $this->writer->assertExpectedVersion($document, $expectedStateVersion);
        }

        $sync = $document->syncState;
        if ($sync?->collab_protocol !== null && ($type->schemaVersion() !== $document->schema_version
            || !$type instanceof SupportsCollaboration
            || $type->collaborationProtocol() !== $sync->collab_protocol || $type->collaborationStateFormat() !== $sync->collab_state_format)) {
            throw new CollaborationUnavailableException('unsupported_binding', $document->type);
        }
        $decoded = $this->decoder->decode($type, $content instanceof DocumentContent ? $content->toArray() : $content);
        if ($document->type === RichTextDocumentType::KEY && $type->schemaVersion() >= 2 && $decoded instanceof TipTapContent) {
            BlockIdentity::assertComplete($decoded->toArray());
        }

        $result = $this->writer->write(
            $document,
            new DocumentStateChange(
                cause: StateWriteCause::Update,
                content: $decoded,
                schemaVersion: $type->schemaVersion(),
                actorId: $context->actor->ref()->id,
                expectedStateVersion: $expectedStateVersion,
            ),
            maximumStateVersion: $command ? DocumentMutationState::MAX_WIRE_COUNTER : null,
            reseedBoundSnapshot: !$command,
        );

        $head = $result->checkpoint ?? $document->currentRevision()->sharedLock()->firstOrFail();
        if ($result->checkpoint === null) {
            $head->setRelation('payload', $head->payload()->sharedLock()->first(['revision_id', 'content_hash', 'content_size']));
            $head->setRelation('syncState', $head->syncState()->sharedLock()->first(['revision_id', 'collab_protocol', 'collab_state_format', 'collab_state_size']));
        }
        $document->setRelation('currentRevision', $head);

        if ($result->changed) {
            $this->recorder->record(new DocumentContentUpdated(
                node: $node,
                version: $head,
                created: $result->checkpoint !== null,
                stateVersion: $result->document->state_version,
                epoch: $result->document->collab_epoch,
                actor: $context->actor->ref(),
                correlationId: $context->correlationId,
                nodes: $this->nodeSnapshots,
                documents: $this->documentSnapshots,
            ));
        }

        return $result;
    }
}
