<?php

declare(strict_types=1);

namespace Vaulter\Actions;

use Illuminate\Support\Facades\DB;
use Vaulter\Data\CreateDocumentData;
use Vaulter\Enums\DocumentType;
use Vaulter\Events\DocumentCreated;
use Vaulter\Exceptions\UnknownDocumentTypeException;
use Vaulter\Models\Document;
use Vaulter\Repositories\DocumentStoreRepository;
use Vaulter\Support\Config;

final readonly class CreateDocumentAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
    ) {}

    public function execute(CreateDocumentData $data): Document
    {
        $this->guardType($data->type);
        $data->content->ensureWithin(Config::maxDocumentSizeKb());

        $document = DB::transaction(fn (): Document => $this->documents->create([
            'workspace_type' => $data->workspaceType,
            'workspace_id' => $data->workspaceId,
            'folder_id' => $data->folderId,
            'type' => $data->type,
            'title' => $data->title,
            'content' => $data->content->toArray(),
            'meta' => $data->meta,
            'created_by' => $data->actorId,
            'updated_by' => $data->actorId,
        ]));

        event(new DocumentCreated($document, $data->actorId));

        return $document;
    }

    private function guardType(DocumentType $type): void
    {
        $enabled = Config::enabledDocumentTypes();

        if ($enabled !== [] && !in_array($type->value, $enabled, true)) {
            throw UnknownDocumentTypeException::forType($type->value, $enabled);
        }
    }
}
