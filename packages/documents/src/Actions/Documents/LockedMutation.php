<?php

declare(strict_types=1);

namespace Vaulter\Documents\Actions\Documents;

use Illuminate\Database\Connection;
use LogicException;
use Packman\Attributes\Internal;
use Vaulter\Documents\Content\ContentDecoder;
use Vaulter\Documents\Contracts\DocumentContent;
use Vaulter\Documents\Contracts\LockedDocumentMutation;
use Vaulter\Documents\Data\Documents\DocumentMutationState;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\State\PendingStateGuard;
use Vaulter\Drive\Models\Node;
use Vaulter\Drive\Operations\DriveContext;
use Vaulter\Support\Identity;

#[Internal]
final class LockedMutation implements LockedDocumentMutation
{
    private bool $open = true;

    private bool $replaced = false;

    private DocumentMutationState $state;

    public function __construct(
        private readonly Connection $connection,
        private readonly DriveContext $context,
        private readonly Node $node,
        private readonly Document $document,
        private readonly ContentDecoder $decoder,
        private readonly WriteLockedDocumentContent $writer,
    ) {
        $this->state = $this->readState();
    }

    private function __clone() {}

    public function snapshot(): DocumentMutationState
    {
        $this->assertOpen();

        return $this->state;
    }

    public function connection(): Connection
    {
        $this->assertOpen();

        return $this->connection;
    }

    public function replace(DocumentContent $content, int $expectedStateVersion): DocumentMutationState
    {
        $this->assertOpen();
        if ($this->replaced) {
            throw new LogicException('[Vaulter] Only one replacement is permitted per atomic mutation attempt.');
        }
        $this->replaced = true;
        $result = $this->writer->execute($this->context, $this->node, $this->document, $content, $expectedStateVersion, command: true);

        if ($result->changed) {
            $this->state = $this->readState();
        }

        return $this->state;
    }

    public function close(): void
    {
        $this->open = false;
    }

    private function assertOpen(): void
    {
        if (!$this->open) {
            throw new LogicException('[Vaulter] The document mutation callback has ended.');
        }
    }

    private function readState(): DocumentMutationState
    {
        return new DocumentMutationState(
            nodeId: Identity::stringify($this->node->id),
            documentId: Identity::stringify($this->document->id),
            type: $this->document->type,
            schemaVersion: $this->document->schema_version,
            generation: $this->document->collab_epoch,
            position: $this->document->state_version,
            writeMode: $this->document->write_mode->value,
            protocol: $this->document->syncState?->collab_protocol,
            pending: PendingStateGuard::markerOf($this->document) !== null,
            content: $this->decoder->read($this->document->type, $this->decoder->storedPayload($this->document->type, $this->document->payload?->getAttributes()['content'] ?? null, $this->document->payload?->content), $this->document->schema_version)->content,
        );
    }
}
