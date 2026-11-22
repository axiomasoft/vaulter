<?php

declare(strict_types=1);

namespace Vaulter\Documents\Repositories\Documents;

use Packman\Attributes\Internal;
use Vaulter\Documents\Content\ManagedDocumentPayloads;
use Vaulter\Documents\Models\Documents\Document;
use Vaulter\Documents\Models\Documents\DocumentPayload;
use Vaulter\Documents\Models\Documents\DocumentSyncState;

/**
 * Row writes of the document aggregate. Only CreateDocumentAction (the empty
 * aggregate) and DocumentStateWriter (everything after) call it.
 */
#[Internal]
final class DocumentStoreRepository
{
    public function __construct(private readonly ManagedDocumentPayloads $references) {}
    /**
     * @param array<string, mixed> $attributes server-built, never request input
     */
    public function create(array $attributes): Document
    {
        $document = new Document;
        $document->forceFill($attributes)->save();

        return $document->refresh();
    }

    /**
     * @param array<string, mixed> $attributes
     */
    public function update(Document $document, array $attributes): Document
    {
        $document->forceFill($attributes)->save();

        return $document;
    }

    /** @param array<string, mixed> $attributes */
    public function savePayload(Document $document, array $attributes): void
    {
        $payload = $document->payload ?? new DocumentPayload;
        $this->references->change($document->type, 'working', (string) $document->getKey(), $payload->content, $attributes['content']);
        $document->payload()->save($payload->forceFill($attributes));
        $document->setRelation('payload', $payload);
    }

    /** @param array<string, mixed> $attributes */
    public function saveSyncState(Document $document, array $attributes): void
    {
        $sync = $document->syncState ?? new DocumentSyncState;
        $sync->forceFill($attributes);
        if ($sync->collab_protocol === null && $sync->pending_protocol === null && $sync->collab_state === null
            && $sync->collab_state_format === null && $sync->pending_generation === null) {
            if ($sync->exists) {
                $sync->delete();
            }
            $document->setRelation('syncState', null);

            return;
        }

        $document->syncState()->save($sync);
        $document->setRelation('syncState', $sync);
    }
}
