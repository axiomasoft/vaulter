<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Events\DocumentPurged;
use Vaulter\Models\Document;
use Vaulter\Repositories\DocumentStoreRepository;

final readonly class ForceDeleteDocumentAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
    ) {}

    public function execute(Document $document, int|string|null $actorId = null): void
    {
        DB::transaction(fn () => $this->documents->forceDelete($document));

        event(new DocumentPurged($document, $actorId));
    }
}
