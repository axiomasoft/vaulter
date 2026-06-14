<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Events\DocumentMoved;
use DocVault\Models\Document;
use DocVault\Models\DocumentFolder;
use DocVault\Repositories\DocumentStoreRepository;
use DocVault\Repositories\FolderReadRepository;
use DocVault\Support\Identity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class MoveDocumentAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private FolderReadRepository $folders,
    ) {}

    public function execute(Document $document, int|string|null $folderId, int|string|null $actorId = null): Document
    {
        if ($folderId !== null) {
            $folder = $this->folders->find($folderId);

            if ($folder === null || !$this->sameWorkspace($folder, $document)) {
                throw ValidationException::withMessages([
                    'folder_id' => 'The folder does not exist in this workspace.',
                ]);
            }
        }

        $moved = DB::transaction(fn (): Document => $this->documents->update($document, [
            'folder_id' => $folderId,
            'updated_by' => $actorId,
        ]));

        event(new DocumentMoved($moved, $folderId, $actorId));

        return $moved;
    }

    private function sameWorkspace(DocumentFolder $folder, Document $document): bool
    {
        return $folder->workspace_type === $document->workspace_type
            && Identity::stringify($folder->workspace_id) === Identity::stringify($document->workspace_id);
    }
}
