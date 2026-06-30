<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\Documents\DocumentRestored;
use Vaulter\Models\Documents\Document;
use Vaulter\Repositories\Documents\DocumentStoreRepository;

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
