<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\Documents\DocumentCreated;
use Vaulter\Models\Documents\Document;
use Vaulter\Repositories\Documents\DocumentStoreRepository;

final readonly class DuplicateDocumentAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
    ) {}

    public function execute(Document $document, int|string|null $actorId = null): Document
    {
        $copy = DB::transaction(fn (): Document => $this->documents->create([
            'workspace_type' => $document->workspace_type,
            'workspace_id' => $document->workspace_id,
            'folder_id' => $document->folder_id,
            'type' => $document->type,
            'title' => 'Copy of '.$document->title,
            'content' => $document->content,
            'meta' => $document->meta,
            'created_by' => $actorId,
            'updated_by' => $actorId,
        ]));

        event(new DocumentCreated($copy, $actorId));

        return $copy;
    }
}
