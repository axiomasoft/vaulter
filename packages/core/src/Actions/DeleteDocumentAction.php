<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\Documents\DocumentDeleted;
use Vaulter\Models\Documents\Document;
use Vaulter\Repositories\Documents\DocumentStoreRepository;

final readonly class DeleteDocumentAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
    ) {}

    public function execute(Document $document, int|string|null $actorId = null): void
    {
        DB::transaction(fn () => $this->documents->softDelete($document));

        event(new DocumentDeleted($document, $actorId));
    }
}
