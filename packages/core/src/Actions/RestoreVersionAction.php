<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Events\VersionRestored;
use DocVault\Models\Document;
use DocVault\Models\DocumentVersion;
use DocVault\Repositories\DocumentStoreRepository;
use DocVault\Support\Identity;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final readonly class RestoreVersionAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
    ) {}

    public function execute(Document $document, DocumentVersion $version, int|string|null $actorId = null): Document
    {
        if (Identity::stringify($version->document_id) !== Identity::stringify($document->getKey())) {
            throw ValidationException::withMessages([
                'version' => 'The version does not belong to this document.',
            ]);
        }

        $restored = DB::transaction(fn (): Document => $this->documents->update($document, [
            'content' => $version->content,
            'updated_by' => $actorId,
        ]));

        event(new VersionRestored($restored, $version, $actorId));

        return $restored;
    }
}
