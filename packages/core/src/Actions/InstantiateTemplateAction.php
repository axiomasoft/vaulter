<?php

declare(strict_types=1);

namespace DocVault\Actions;

use DocVault\Data\InstantiateTemplateData;
use DocVault\Enums\DocumentType;
use DocVault\Events\DocumentCreated;
use DocVault\Models\Document;
use DocVault\Repositories\DocumentStoreRepository;
use DocVault\Support\TemplateVariableSubstitutor;
use DocVault\ValueObjects\TipTapContent;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Creates a new editable document from a template document, substituting
 * `{{ placeholder }}` tokens in its content with the provided variables.
 */
final readonly class InstantiateTemplateAction
{
    public function __construct(
        private DocumentStoreRepository $documents,
        private TemplateVariableSubstitutor $substitutor,
    ) {}

    public function execute(Document $source, InstantiateTemplateData $data): Document
    {
        if ($source->type !== DocumentType::Template) {
            throw ValidationException::withMessages([
                'document' => 'The source document is not a template.',
            ]);
        }

        $sourceContent = is_array($source->content) ? $source->content : ['type' => 'doc', 'content' => []];
        $content = TipTapContent::fromArray($this->substitutor->substitute($sourceContent, $data->variables));

        $document = DB::transaction(fn (): Document => $this->documents->create([
            'workspace_type' => $data->workspaceType ?? $source->workspace_type,
            'workspace_id' => $data->workspaceId ?? $source->workspace_id,
            'folder_id' => $data->folderId ?? $source->folder_id,
            'type' => $data->type ?? DocumentType::RichText->value,
            'title' => $data->title ?? $source->title,
            'content' => $content->toArray(),
            'meta' => $source->meta,
            'created_by' => $data->actorId,
            'updated_by' => $data->actorId,
        ]));

        event(new DocumentCreated($document, $data->actorId));

        return $document;
    }
}
