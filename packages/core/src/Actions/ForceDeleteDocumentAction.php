<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Events\DocumentPurged;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentStoreRepository;
use Illuminate\Support\Facades\DB;

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
