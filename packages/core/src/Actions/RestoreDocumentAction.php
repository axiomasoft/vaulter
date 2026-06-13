<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Events\DocumentRestored;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentStoreRepository;
use Illuminate\Support\Facades\DB;

final readonly class RestoreDocumentAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
    ) {}

    public function execute(Document $document, int|string|null $actorId = null): Document
    {
        DB::transaction(fn () => $this->documents->restore($document));

        event(new DocumentRestored($document, $actorId));

        return $document;
    }
}
