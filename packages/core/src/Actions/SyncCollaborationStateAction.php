<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\DocumentContentSynced;
use Vaulter\Events\DocumentUpdated;
use Vaulter\Models\Document;
use Vaulter\Repositories\DocumentStoreRepository;
use Vaulter\Support\Config;
use Vaulter\ValueObjects\TipTapContent;

/**
 * Persists the latest collaborative content (the merged Yjs state, serialized to
 * TipTap JSON by the client) and notifies other collaborators.
 */
final readonly class SyncCollaborationStateAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
    ) {}

    public function execute(Document $document, TipTapContent $content, int|string|null $actorId = null): Document
    {
        $content->ensureWithin(Config::maxDocumentSizeKb());

        $updated = DB::transaction(fn (): Document => $this->documents->update($document, [
            'content' => $content->toArray(),
            'updated_by' => $actorId,
        ]));

        event(new DocumentUpdated($updated, $actorId));
        event(new DocumentContentSynced($updated, $actorId));

        return $updated;
    }
}
