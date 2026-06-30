<?php

declare(strict_types=1);

namespace Vaulter\Http\Requests;

use Illuminate\Validation\Rule;
use Vaulter\Data\CreateDocumentData;
use Vaulter\Enums\Documents\DocumentType;
use Vaulter\Support\Config;
use Vaulter\ValueObjects\Documents\TipTapContent;

final class StoreDocumentRequest extends VaulterFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'workspace_type' => ['required', 'string'],
            'workspace_id' => ['required'],
            'type' => ['required', 'string', Rule::in(Config::enabledDocumentTypes() ?: DocumentType::values())],
            'title' => ['required', 'string', 'max:255'],
            'content' => ['required', 'array'],
            'folder_id' => ['nullable'],
            'meta' => ['nullable', 'array'],
        ];
    }

    public function toData(): CreateDocumentData
    {
        return new CreateDocumentData(
            workspaceType: $this->string('workspace_type')->toString(),
            workspaceId: $this->scalarId('workspace_id'),
            type: DocumentType::from($this->string('type')->toString()),
            title: $this->string('title')->toString(),
            content: TipTapContent::fromArray($this->arrayInput('content')),
            folderId: $this->nullableScalarId('folder_id'),
            meta: $this->nullableArrayInput('meta'),
            actorId: $this->actorId(),
        );
    }
}
