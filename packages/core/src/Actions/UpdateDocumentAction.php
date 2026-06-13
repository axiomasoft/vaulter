<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Data\UpdateDocumentData;
use DocVault\Events\DocumentUpdated;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentStoreRepository;
use DocVault\Support\Config;
use Illuminate\Support\Facades\DB;

final readonly class UpdateDocumentAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
    ) {}

    public function execute(Document $document, UpdateDocumentData $data): Document
    {
        /** @var array<string, mixed> $attributes */
        $attributes = ['updated_by' => $data->actorId];

        if ($data->title !== null) {
            $attributes['title'] = $data->title;
        }

        if ($data->content !== null) {
            $data->content->ensureWithin(Config::maxDocumentSizeKb());
            $attributes['content'] = $data->content->toArray();
        }

        if ($data->meta !== null) {
            $attributes['meta'] = $data->meta;
        }

        $updated = DB::transaction(fn (): Document => $this->documents->update($document, $attributes));

        event(new DocumentUpdated($updated, $data->actorId));

        return $updated;
    }
}
