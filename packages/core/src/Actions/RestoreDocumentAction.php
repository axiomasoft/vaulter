<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\DocumentRestored;
use Vaulter\Models\Document;
use Vaulter\Repositories\DocumentStoreRepository;

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
